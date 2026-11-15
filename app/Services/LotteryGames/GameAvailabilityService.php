<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\DTOs\LotteryGames\GameCapability;
use App\DTOs\LotteryGames\GameDefinition;
use App\Enums\DrawStatus;
use App\Enums\GameRulesStatus;
use App\Exceptions\LotteryGameException;
use App\Models\Draw;
use App\Services\Compliance\RealMoneyGate;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The NINE GATES a digit game must pass before a single stake may be taken.
 *
 * WHY A GATE LIST RATHER THAN A CHAIN OF IFS
 * Rule §0.4 requires that a game be purchasable only if every condition holds, and
 * that otherwise it report NOT_CONFIGURED with the list of what is missing. A
 * chain of early returns can satisfy that, but it cannot answer the question
 * operators actually ask — "how close is this game to being sellable?" — and it
 * silently changes behaviour when someone inserts a check in the wrong position.
 * So every gate is evaluated, every gate is recorded by name, and the refusal names
 * the FIRST gate in a fixed order. The order is fixed so the same misconfiguration
 * always produces the same message, and so a test can assert it.
 *
 * THE ORDER, AND WHY IT IS THIS ORDER
 *   1  master_switch              the deployment lane itself is off
 *   2  game_enabled               the operator has not switched this game on
 *   3  rules_verified             no operator has verified the rules
 *   4  checksum_matches           the rules were verified, then the config changed
 *   5  official_result_source     results could not arrive, so bets could not settle
 *   6  liability_limits_configured  uncapped risk
 *   7  seated_open_draw           nothing is open to bet on
 *   8  real_money_open            the deployment may not move real money
 *   9  legal_documents_published  the legal corpus a gambling product requires is absent
 *
 * The order runs from "the most fundamental statement about this game" to "the most
 * deployment-wide statement", because that is how an operator debugs: they check the
 * game before they check the platform. It is also fail-closed at every step: gate 1
 * being false makes the rest irrelevant, but all nine are still evaluated so the
 * report is complete rather than merely sufficient.
 *
 * WHAT THIS CLASS DOES NOT DO
 * It does not reserve liability, move money, create draws or ingest results. It
 * answers one question — may this game be sold right now — and it answers it in
 * terms an operator can act on.
 *
 * EVERYTHING IT READS, IT READS THROUGH ITS COLLABORATORS
 * Config through GameRegistry (the single read point), rules through
 * GameDefinition, money admission through the existing App\Services\Compliance\
 * RealMoneyGate, and the open draw through the existing Draw model. Nothing here
 * duplicates a Batch-1 decision.
 */
final class GameAvailabilityService
{
    /**
     * The gate names, in evaluation and reporting order.
     */
    public const GATE_ORDER = [
        'master_switch',
        'game_enabled',
        'rules_verified',
        'checksum_matches',
        'official_result_source',
        'liability_limits_configured',
        'seated_open_draw',
        'real_money_open',
        'legal_documents_published',
    ];

    /**
     * The machine-readable reason a gate refuses. Stable strings: the API surfaces
     * them, the admin screen groups by them and tests assert on them.
     *
     * @var array<string, string>
     */
    private const GATE_REASONS = [
        'master_switch' => 'game_lane_disabled',
        'game_enabled' => 'game_disabled',
        'rules_verified' => 'game_rules_unverified',
        'checksum_matches' => 'game_rules_checksum_stale',
        'official_result_source' => 'game_result_source_not_configured',
        'liability_limits_configured' => 'game_liability_limits_not_configured',
        'seated_open_draw' => 'game_no_open_draw',
        'real_money_open' => 'real_money_closed',
        'legal_documents_published' => 'legal_documents_not_published',
    ];

    public function __construct(
        private readonly GameRegistry $registry,
        private readonly RealMoneyGate $realMoney,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * The capability of one game: status, whether it may sell, why not, and what the
     * nine gates said.
     *
     * @throws LotteryGameException game_unknown
     */
    public function capability(string $code): GameCapability
    {
        $definition = $this->registry->definition($code);

        $gates = $this->gates($code, $definition);
        $missing = $this->missingRequirements($definition, $gates);
        $suspended = $this->isSuspended($code);

        if ($suspended) {
            // Suspension is NOT "not configured": it is a withdrawn decision, and
            // reporting it as a configuration gap would send an operator looking for
            // a missing value that does not exist.
            return new GameCapability(
                status: GameCapability::STATUS_SUSPENDED,
                enabled: false,
                reason: GameRulesStatus::Suspended->refusalReason() ?? 'game_rules_suspended',
                missing: $missing,
                gates: $gates,
            );
        }

        $firstFailure = $this->firstFailingGate($gates);

        if ($firstFailure === null) {
            return new GameCapability(
                status: GameCapability::STATUS_CONFIGURED,
                enabled: true,
                reason: 'sellable',
                missing: [],
                gates: $gates,
            );
        }

        return new GameCapability(
            status: GameCapability::STATUS_NOT_CONFIGURED,
            enabled: false,
            reason: self::GATE_REASONS[$firstFailure] ?? $firstFailure,
            missing: $missing,
            gates: $gates,
        );
    }

    /**
     * Every gate's verdict for one game, in GATE_ORDER.
     *
     * Evaluated eagerly and completely: a caller can see all nine answers even when
     * the first is false, which is what makes the report actionable.
     *
     * @return array<string, bool>
     */
    public function gates(string $code, ?GameDefinition $definition = null): array
    {
        $definition ??= $this->registry->find($code);

        if (! $definition instanceof GameDefinition) {
            throw LotteryGameException::unknownGame($code);
        }

        $governance = $this->registry->governance($code);

        $verdicts = [
            'master_switch' => $this->registry->masterEnabled(),
            'game_enabled' => $governance['enabled'] === true,
            'rules_verified' => $governance['status'] === GameRulesStatus::Verified->value,
            'checksum_matches' => $governance['checksum_matches'] === true,
            'official_result_source' => $this->resultSourceConfigured($definition),
            'liability_limits_configured' => $this->liabilityLimitsConfigured($definition),
            'seated_open_draw' => $this->openDrawExists($code),
            'real_money_open' => $this->realMoney->isOpen(),
            'legal_documents_published' => $this->legalCorpusPublished(),
        ];

        // Re-key in GATE_ORDER so the map's order is the documented order, not the
        // order the checks happen to be written in.
        $ordered = [];

        foreach (self::GATE_ORDER as $gate) {
            $ordered[$gate] = $verdicts[$gate] ?? false;
        }

        return $ordered;
    }

    /**
     * Whether the game may be sold right now: all nine gates, no exceptions.
     */
    public function canSell(string $code): bool
    {
        return $this->capability($code)->isSellable();
    }

    /**
     * Refuse unless the game may be sold.
     *
     * @throws LotteryGameException game_not_sellable carrying every gate verdict
     */
    public function assertSellable(string $code): void
    {
        $capability = $this->capability($code);

        if ($capability->isSellable()) {
            return;
        }

        throw LotteryGameException::notSellable(
            $capability->reason,
            [
                'game_code' => $code,
                'status' => $capability->status,
                'gates' => $capability->gates,
                'missing' => $capability->missing,
            ],
        );
    }

    /**
     * Capability of every declared game, keyed by code in configuration order.
     *
     * @return array<string, GameCapability>
     */
    public function capabilities(): array
    {
        $capabilities = [];

        foreach ($this->registry->codes() as $code) {
            $capabilities[$code] = $this->capability($code);
        }

        return $capabilities;
    }

    /**
     * The requirements an operator must satisfy, as dotted names.
     *
     * Two sources: the configuration placeholders still open (REQUIRED_INPUT), and
     * the gates that failed for a reason configuration alone cannot fix (no open
     * draw, real money closed). Naming the gate keeps the message honest — an
     * operator cannot fix "no open draw" by editing config.
     *
     * @param  array<string, bool>  $gates
     * @return list<string>
     */
    private function missingRequirements(GameDefinition $definition, array $gates): array
    {
        $missing = $definition->requiredInputs();

        foreach ($gates as $gate => $passed) {
            if ($passed) {
                continue;
            }

            $missing[] = 'gate:'.$gate;
        }

        return array_values(array_unique($missing));
    }

    /**
     * The first gate that failed, in GATE_ORDER, or null when all passed.
     *
     * @param  array<string, bool>  $gates
     */
    private function firstFailingGate(array $gates): ?string
    {
        foreach (self::GATE_ORDER as $gate) {
            if (($gates[$gate] ?? false) !== true) {
                return $gate;
            }
        }

        return null;
    }

    /**
     * Whether the game is suspended anywhere: operator row or deployment declaration.
     */
    private function isSuspended(string $code): bool
    {
        $governance = $this->registry->governance($code);

        return $governance['status'] === GameRulesStatus::Suspended->value
            || $governance['declared'] === GameRulesStatus::Suspended->value;
    }

    /**
     * Gate 5: an OFFICIAL result source, completely configured.
     *
     * The fixture driver is a development aid and never satisfies this gate: a game
     * that can only be given fabricated results cannot be settled for real money.
     * `fixturesAllowed()` is therefore deliberately not consulted here — it governs
     * whether the fixture provider may RUN, not whether a game may SELL.
     */
    private function resultSourceConfigured(GameDefinition $definition): bool
    {
        $source = $definition->resultSource;
        $driver = $source['driver'] ?? null;

        if (! is_string($driver) || trim($driver) === '' || trim($driver) === 'fixture') {
            return false;
        }

        foreach (['base_url', 'latest_path', 'draw_no_field', 'digits_field'] as $key) {
            $value = $source[$key] ?? null;

            if (! is_string($value) || trim($value) === '' || trim($value) === GameDefinition::REQUIRED_INPUT) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gate 6: both liability caps are configured numbers greater than zero.
     *
     * Both, not either: a per-number cap without a per-draw cap cannot stop ten
     * thousand acceptable selections adding up, and a per-draw cap without a
     * per-number cap cannot stop one selection consuming the whole draw.
     */
    private function liabilityLimitsConfigured(GameDefinition $definition): bool
    {
        foreach ([$definition->maxLiabilityPerDraw, $definition->maxLiabilityPerNumber] as $cap) {
            if ($cap === null || ! is_numeric($cap) || bccomp($cap, '0', 2) <= 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Gate 7: this game has a draw that is open AND inside its betting window.
     *
     * "Open" alone is not enough: a draw can be flagged open while its
     * betting_close_at has passed (a scheduler that has not yet closed it), and
     * selling into it would take stakes for a draw nobody can be added to. The Draw
     * model already owns that judgement in isOpenForBetting(); this gate asks it
     * rather than re-deriving the window.
     */
    private function openDrawExists(string $code): bool
    {
        /** @var list<Draw> $draws */
        $draws = Draw::query()
            ->where('game_type', $code)
            ->where('status', DrawStatus::Open->value)
            ->orderBy('scheduled_at')
            ->get()
            ->all();

        foreach ($draws as $draw) {
            if ($draw->isOpenForBetting()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gate 9: the legal corpus this deployment is required to publish is present.
     *
     * WHAT THIS GATE IS, AND IS NOT
     * It is NOT the per-player consent check — Batch 1 owns that in
     * RequireCurrentLegalConsent middleware, and it answers a different question
     * (has THIS player accepted THIS version). It is the product-level admission
     * question: does this deployment assert a published, current terms/privacy
     * corpus at an adult minimum age? A gambling product may not be sold on a
     * deployment that cannot answer yes, and the values it reads are the ones the
     * legal pages themselves render from config/legal.php.
     *
     * Removing any of them is how a deployment signals "not ready to sell", so the
     * gate fails closed on all four.
     */
    private function legalCorpusPublished(): bool
    {
        $terms = $this->config->get('legal.terms.version');
        $privacy = $this->config->get('legal.privacy_version');
        $content = $this->config->get('legal.content_version');
        $minimumAge = $this->config->get('legal.minimum_age');

        foreach ([$terms, $privacy, $content] as $version) {
            if (! is_string($version) || trim($version) === '') {
                return false;
            }
        }

        return is_int($minimumAge) && $minimumAge >= 18;
    }
}
