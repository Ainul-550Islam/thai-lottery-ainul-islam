<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Enums\GloPrizeTier;
use App\Enums\GloSourceState;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Services\PublicPages\PublishedResultSourceResolver;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;

/**
 * Server-side "did this ticket win?" for a PUBLISHED draw.
 *
 * ============================================================================
 * WHAT MAKES THIS CLASS SAFE TO PUT BEHIND A PUBLIC ENDPOINT
 * ============================================================================
 * 1. THE NUMBERS COME FROM THE DATABASE. The winning numbers are read from
 *    `draw_results` for a draw whose result is Published. Nothing is parsed from
 *    the request except the six digits the visitor typed.
 *
 * 2. THE MONEY COMES FROM CONFIGURATION. Tier amounts are read from
 *    config('glo.prizes'), which is the same catalogue the claim and settlement
 *    lanes read. There is not one literal baht amount in this file, so a
 *    corrected prize schedule is a configuration change and this endpoint cannot
 *    drift from the payer.
 *
 * 3. IT NEVER SAYS "NO PRIZE" WHEN IT DOES NOT KNOW. Three distinct negative
 *    answers exist and they are not interchangeable:
 *
 *      NOT_CONFIGURED  no published result exists at all       -> nothing to check against
 *      UNAVAILABLE     the published row carries a source the platform refuses to
 *                      treat as authoritative (a fixture in production)  -> do not answer
 *      NO_PRIZE        a published, authoritative result exists and the digits do not
 *                      match any tier                            -> a real answer
 *
 *    Collapsing the first two into "you did not win" would be a lie with
 *    financial consequences, so the state is part of the contract.
 *
 * 4. IT PAYS NOTHING. This service returns a description. Claim submission,
 *    review and payment are `GloPrizeClaimService`'s lane, behind authentication
 *    and a reviewer. The response even carries the claim deadline purely so the
 *    page can say "claim by", not so it can promise payment.
 *
 * STAMP DUTY is delegated to {@see GloStampDutyCalculator} so the net figure a
 * visitor sees is computed by the same integer-baht rule that governs the actual
 * payment.
 */
final class PrizeCheckService
{
    /** A published result exists and was checked. */
    public const STATE_CHECKED = 'CHECKED';

    /** No published result exists for any draw. */
    public const STATE_NOT_CONFIGURED = 'NOT_CONFIGURED';

    /** A result row exists but its provenance cannot be published. */
    public const STATE_UNAVAILABLE = 'UNAVAILABLE';

    /** The digits could not be accepted as a ticket number. */
    public const STATE_INVALID = 'INVALID_TICKET';

    public const VERDICT_WINNING = 'WINNING';

    public const VERDICT_NO_PRIZE = 'NO_PRIZE';

    /** Tier keys rendered in this order so the highest tier always wins. */
    private const TIER_ORDER = [
        GloPrizeTier::First->value,
        GloPrizeTier::AdjacentFirst->value,
        GloPrizeTier::Second->value,
        GloPrizeTier::Third->value,
        GloPrizeTier::Fourth->value,
        GloPrizeTier::Fifth->value,
        GloPrizeTier::FrontThree->value,
        GloPrizeTier::LastThree->value,
        GloPrizeTier::LastTwo->value,
    ];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly GloResultService $results,
        private readonly GloStampDutyCalculator $stampDuty,
        private readonly PublishedResultSourceResolver $sources,
    ) {}

    /**
     * Check a ticket against the latest published draw.
     *
     * @return array<string, mixed>
     */
    public function checkLatest(string $ticketNumber): array
    {
        // INPUT BEFORE STATE. A six-digit check costs nothing and does not touch
        // the database, so an invalid ticket must be refused as invalid even when
        // no draw has been published. The other order answers NOT_CONFIGURED for
        // a seven-digit number, which tells the member the platform is missing a
        // draw instead of telling them they typed the number wrong.
        if ($this->normaliseTicket($ticketNumber) === null) {
            return $this->stateOnly(self::STATE_INVALID, 'invalid_ticket_number');
        }

        $draw = $this->latestPublishedDraw();

        if ($draw === null) {
            return $this->stateOnly(self::STATE_NOT_CONFIGURED, 'no_published_draw');
        }

        return $this->check($ticketNumber, $draw);
    }

    /**
     * Check a ticket against a specific draw.
     *
     * @return array<string, mixed>
     */
    public function check(string $ticketNumber, Draw $draw): array
    {
        $ticket = $this->normaliseTicket($ticketNumber);

        if ($ticket === null) {
            return $this->stateOnly(self::STATE_INVALID, 'invalid_ticket_number');
        }

        $result = DrawResult::query()->where('draw_id', $draw->getKey())->first();

        if (! $result instanceof DrawResult) {
            return $this->stateOnly(self::STATE_NOT_CONFIGURED, 'result_not_recorded');
        }

        // `published_at` is the only publication signal on draw_results: there
        // is no status column, and a row that exists without it is an
        // un-published record that must not be checked against.
        if ($result->published_at === null) {
            return $this->stateOnly(self::STATE_NOT_CONFIGURED, 'result_not_published');
        }

        $source = $this->sources->resolve($result, $draw);

        // The resolver is the single authority on whether a stored row may be
        // presented as authoritative. A fixture in production resolves to
        // FixtureOnly, and this endpoint refuses to answer against it.
        if ($source === GloSourceState::FixtureOnly || $source === GloSourceState::Unavailable) {
            return $this->stateOnly(self::STATE_UNAVAILABLE, 'result_provenance_not_publishable');
        }

        $recorded = $this->results->recordedForDraw((int) $draw->getKey());

        if (($recorded['has_result'] ?? false) !== true || trim((string) ($recorded['first_prize'] ?? '')) === '') {
            return $this->stateOnly(self::STATE_NOT_CONFIGURED, 'result_has_no_first_prize');
        }

        $matchedTier = $this->matchTier($ticket, $recorded);

        return [
            'state' => self::STATE_CHECKED,
            'verdict' => $matchedTier === null ? self::VERDICT_NO_PRIZE : self::VERDICT_WINNING,
            'reason' => null,
            'ticket' => $ticket,
            'draw' => [
                'draw_number' => (string) $draw->draw_number,
                'scheduled_at' => $draw->scheduled_at?->toIso8601String(),
                'published_at' => $result->published_at?->toIso8601String(),
            ],
            'provenance' => [
                'state' => $source->value,
                // The label is a translation key, never a sentence: the surface
                // renders it through lang/{en,th}/frontend_ops.php.
                'label_key' => $this->provenanceLabelKey($source),
            ],
            'tier' => $matchedTier === null ? null : $this->tierPayload($matchedTier),
            'claim_window' => $this->claimWindow($result),
            'currency' => (string) ($draw->currency->value ?? 'THB'),
        ];
    }

    /**
     * The tier catalogue as configuration declares it. Public so the results
     * page and the tests read the same list rather than restating it.
     *
     * @return array<string, array{amount: string, winners: int, digits: int}>
     */
    public function tierCatalogue(): array
    {
        $configured = (array) $this->config->get('glo.prizes', []);
        $catalogue = [];

        foreach (self::TIER_ORDER as $tier) {
            $entry = $configured[$tier] ?? null;

            if (! is_array($entry)) {
                continue;
            }

            $amount = isset($entry['amount']) && is_string($entry['amount']) ? $entry['amount'] : '';

            if ($amount === '' || ! preg_match('/^\d{1,15}(\.\d{1,2})?$/', $amount)) {
                // A tier without a well-formed declared amount is not a tier this
                // service will quote. Skipping it means a malformed schedule makes
                // the endpoint say less, never say something wrong.
                continue;
            }

            $catalogue[$tier] = [
                'amount' => $this->money($amount),
                'winners' => (int) ($entry['winners'] ?? 0),
                'digits' => (int) ($entry['digits'] ?? 6),
            ];
        }

        return $catalogue;
    }

    /**
     * The latest draw whose result is genuinely published, or null.
     */
    public function latestPublishedDraw(): ?Draw
    {
        $drawId = DrawResult::query()
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->value('draw_id');

        if ($drawId === null) {
            return null;
        }

        return Draw::query()->find((int) $drawId);
    }

    /**
     * Exactly six digits, leading zeros preserved. Anything else is refused
     * rather than trimmed, because a trimmed ticket is a different ticket.
     */
    public function normaliseTicket(string $ticketNumber): ?string
    {
        $trimmed = trim($ticketNumber);

        if (preg_match('/^\d{6}$/', $trimmed) !== 1) {
            return null;
        }

        return $trimmed;
    }

    /**
     * The highest matching tier, or null. Reads only the recorded ladder.
     *
     * @param  array<string, mixed>  $recorded
     */
    private function matchTier(string $ticket, array $recorded): ?string
    {
        foreach (self::TIER_ORDER as $tier) {
            $digits = $this->digitsFor($tier);

            if ($digits <= 0) {
                continue;
            }

            $candidate = $digits === 6 ? $ticket : substr($ticket, -$digits);

            // A 3-digit front tier reads the first three digits; every other
            // short tier reads from the end of the ticket.
            if ($tier === GloPrizeTier::FrontThree->value) {
                $candidate = substr($ticket, 0, $digits);
            }

            foreach ($this->numbersForTier($tier, $recorded) as $number) {
                if ($number === $candidate) {
                    return $tier;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $recorded
     * @return list<string>
     */
    private function numbersForTier(string $tier, array $recorded): array
    {
        $raw = $recorded[$tier] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $item) {
            if (! is_string($item) && ! is_int($item)) {
                continue;
            }

            $value = trim((string) $item);

            if ($value === '') {
                continue;
            }

            $out[] = $value;
        }

        return $out;
    }

    private function digitsFor(string $tier): int
    {
        $configured = (array) $this->config->get('glo.prizes', []);
        $entry = $configured[$tier] ?? null;

        if (is_array($entry) && isset($entry['digits'])) {
            return (int) $entry['digits'];
        }

        // Declared schedule fallback: the six-digit ladder pays six, the short
        // tiers pay their own length. Read from the enum's own semantics rather
        // than a second table.
        return match ($tier) {
            GloPrizeTier::FrontThree->value, GloPrizeTier::LastThree->value => 3,
            GloPrizeTier::LastTwo->value => 2,
            default => 6,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function tierPayload(string $tier): array
    {
        $catalogue = $this->tierCatalogue();
        $entry = $catalogue[$tier] ?? null;

        if ($entry === null) {
            // A tier matched but the schedule no longer declares an amount. Say
            // nothing about money rather than invent it.
            return [
                'key' => $tier,
                'label_key' => 'frontend_ops.results.tier_'.$tier,
                'gross' => null,
                'stamp_duty' => null,
                'net' => null,
            ];
        }

        $gross = $entry['amount'];
        $duty = $this->stampDuty->dutyFor($gross);
        $net = $this->stampDuty->netAfterDuty($gross);

        return [
            'key' => $tier,
            'label_key' => 'frontend_ops.results.tier_'.$tier,
            'gross' => $gross,
            'stamp_duty' => $duty,
            'net' => $net,
        ];
    }

    /**
     * @return array{claim_by: string|null, window_years: int}
     */
    private function claimWindow(DrawResult $result): array
    {
        $years = max(1, (int) $this->config->get('glo.claim.window_years', 2));
        $anchor = $result->published_at;

        return [
            'claim_by' => $anchor?->copy()->addYears($years)->toIso8601String(),
            'window_years' => $years,
        ];
    }

    private function provenanceLabelKey(GloSourceState $state): string
    {
        return match ($state) {
            GloSourceState::OfficialSourceVerified => 'frontend_ops.results.provenance_official',
            GloSourceState::OfficialSourceConfigured => 'frontend_ops.results.provenance_official',
            GloSourceState::InternalReconciled => 'frontend_ops.results.provenance_reconciled',
            GloSourceState::FixtureOnly => 'frontend_ops.results.provenance_unavailable',
            GloSourceState::NotConfigured => 'frontend_ops.results.provenance_unavailable',
            GloSourceState::Unavailable => 'frontend_ops.results.provenance_unavailable',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function stateOnly(string $state, string $reason): array
    {
        return [
            'state' => $state,
            'verdict' => null,
            'reason' => $reason,
            'ticket' => null,
            'draw' => null,
            'provenance' => null,
            'tier' => null,
            'claim_window' => null,
            'currency' => 'THB',
        ];
    }

    /**
     * Normalise a declared amount into a two-decimal money string without
     * touching a float.
     */
    private function money(string $amount): string
    {
        $parts = explode('.', $amount, 2);
        $whole = ltrim($parts[0], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad(substr($parts[1] ?? '', 0, 2), 2, '0');

        if (! preg_match('/^\d+$/', $whole) || ! preg_match('/^\d{2}$/', $fraction)) {
            throw new InvalidArgumentException('A configured prize amount is not a decimal money string.');
        }

        return $whole.'.'.$fraction;
    }
}
