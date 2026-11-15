<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Enums\GameRulesStatus;
use App\Exceptions\LotteryGameException;
use App\Models\LotteryGame;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * The ONE door between the digit-game lane and config/lottery_games.php.
 *
 * WHY A REGISTRY AND NOT config() CALLS EVERYWHERE
 * Three properties are needed and none of them is a property of a config array:
 *
 *   1. ONE READ POINT. Every rule the lane obeys — digit width, bet types, match
 *      modes, multipliers, draw cron, cutoff, stake bounds, liability caps, result
 *      source, publication bridge — comes from one block, and an architecture test
 *      fails if any other class in the lane reads it. That is what makes "never
 *      invent official rules" enforceable rather than aspirational: there is one
 *      line to audit.
 *   2. GOVERNANCE LAYERED ON CONFIGURATION. A configuration block says what the
 *      rules ARE. The lottery_games row says whether an operator has VERIFIED
 *      them and switched the game on. Sellability needs both, and neither is
 *      allowed to be read directly by callers.
 *   3. MEMOISATION. A single placement request may ask the same question four
 *      times (availability, pricing, exposure, response). Each of those must see
 *      the same definition, so the definition is built once per request and the
 *      checksum stamped on a verification is therefore a checksum of exactly what
 *      was used to price the bet.
 *
 * THE GOVERNANCE RULE, STATED PRECISELY
 * A game is TRUSTED when all of the following hold:
 *
 *   - config/lottery_games.php declares it (otherwise the code is unknown), and
 *   - the declaration is not 'suspended' (a deployment can withdraw trust), and
 *   - a lottery_games row exists for it (an unregistered game is unknown to the
 *     operator), and
 *   - that row's status is Verified, and
 *   - the row's stored rules_checksum equals the checksum of the CURRENTLY
 *     DEPLOYED configuration block.
 *
 * The last clause is the important one. Verification is a statement about a
 * specific rule set, so editing a multiplier changes the checksum and withdraws
 * the trust automatically. Nothing has to remember to re-verify.
 *
 * WHY THE DECLARATION CAN ONLY SUBTRACT TRUST
 * The config value is written by a developer in a pull request; the row is
 * written by a permission-gated, audited operator action. So the row grants and
 * the declaration may only revoke: a config that says 'unverified' after an
 * operator verified the same block does not silently un-verify a live game (the
 * checksum still matches, so the operator's decision stands), while a config that
 * says 'suspended' refuses immediately, because that is how a deployment pulls a
 * game.
 *
 * WHY THE ROW IS READ LAZILY
 * Definitions are pure config and are built eagerly per code. Governance needs a
 * database row, and unit tests, CLI reports and the Filament rules report all
 * touch registries in contexts where reading a table is either unnecessary or
 * unavailable. So rows are fetched only when a caller asks a governance question,
 * and the answer is memoised for the request.
 *
 * THIS CLASS DECIDES NOTHING ABOUT MONEY
 * It says whether rules are trusted. Whether a STAKE may be taken additionally
 * requires an open draw, real-money admission, liability headroom and published
 * legal documents — GameAvailabilityService's nine gates, not this class's job.
 */
final class GameRegistry
{
    /**
     * Definition cache, per code. The absence of a key means "not built yet";
     * a null value means "asked, and the code is unknown".
     *
     * @var array<string, GameDefinition|null>
     */
    private array $definitions = [];

    /**
     * Governance cache, per code. See fetchRow().
     *
     * @var array<string, LotteryGame|null>
     */
    private array $rows = [];

    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * Every game code the deployment declares, in configuration order.
     *
     * Configuration order is preserved because it is the order an operator wrote
     * the games in and therefore the order every report should show them in.
     *
     * @return list<string>
     */
    public function codes(): array
    {
        /** @var array<string, mixed> $games */
        $games = (array) $this->config->get('lottery_games.games', []);

        return array_values(array_map(static fn ($code): string => (string) $code, array_keys($games)));
    }

    /**
     * The definition of a declared game, or null when the code is not declared.
     *
     * Null rather than an exception because most callers are iterating commands
     * and reports that must skip an unknown code with a message rather than abort.
     */
    public function find(string $code): ?GameDefinition
    {
        $code = trim($code);

        if (array_key_exists($code, $this->definitions)) {
            return $this->definitions[$code];
        }

        /** @var array<string, mixed> $games */
        $games = (array) $this->config->get('lottery_games.games', []);

        if (! array_key_exists($code, $games) || ! is_array($games[$code])) {
            return $this->definitions[$code] = null;
        }

        return $this->definitions[$code] = GameDefinition::fromConfig($code, $games[$code]);
    }

    /**
     * The definition of a declared game.
     *
     * @throws LotteryGameException game_unknown
     */
    public function definition(string $code): GameDefinition
    {
        $definition = $this->find($code);

        if (! $definition instanceof GameDefinition) {
            throw LotteryGameException::unknownGame($code);
        }

        return $definition;
    }

    /**
     * Whether the deployment declares this game at all.
     */
    public function declares(string $code): bool
    {
        return $this->find($code) instanceof GameDefinition;
    }

    /**
     * The status the DEPLOYMENT declares for a game.
     *
     * Only the revocation direction is meaningful (see the class docblock):
     * 'suspended' in config withdraws trust regardless of the stored row.
     */
    public function declaredStatus(string $code): GameRulesStatus
    {
        return $this->find($code)?->declaredRulesStatus ?? GameRulesStatus::Unverified;
    }

    /**
     * The operator's lottery_games row, or null when the game is unregistered.
     *
     * A game that is declared in configuration but has no row cannot be sold: no
     * operator has ever looked at it. That is what makes 'db:seed' meaningful for
     * a new game rather than a formality.
     */
    public function row(string $code): ?LotteryGame
    {
        $code = trim($code);

        if (array_key_exists($code, $this->rows)) {
            return $this->rows[$code];
        }

        return $this->rows[$code] = LotteryGame::query()->where('code', $code)->first();
    }

    /**
     * Whether the rules of this game are currently TRUSTED. See the docblock.
     */
    public function isVerifiedAgainstCurrentRules(string $code): bool
    {
        $definition = $this->find($code);

        if (! $definition instanceof GameDefinition) {
            return false;
        }

        // A deployment-level suspension outranks an operator's verification: it is
        // how a game is pulled urgently without waiting for an admin session.
        if ($this->declaredStatus($code) === GameRulesStatus::Suspended) {
            return false;
        }

        $row = $this->row($code);

        if (! $row instanceof LotteryGame) {
            return false;
        }

        return $row->isVerifiedAgainst($definition->checksum());
    }

    /**
     * Whether an operator has switched the game on, regardless of verification.
     *
     * Kept separate from isVerifiedAgainstCurrentRules() so the rules report can
     * say "enabled but unverified" — which is a misconfiguration an operator must
     * see, not a single false.
     */
    public function isEnabled(string $code): bool
    {
        $row = $this->row($code);

        return $row instanceof LotteryGame && $row->enabled === true;
    }

    /**
     * The full governance picture for one game, for reports and the availability
     * service. Never throws: an unknown code is reported as such.
     *
     * @return array{
     *     code: string,
     *     declared: string,
     *     registered: bool,
     *     status: string,
     *     enabled: bool,
     *     checksum_stored: string|null,
     *     checksum_current: string|null,
     *     checksum_matches: bool,
     *     verified_at: string|null,
     *     verified_by: int|null,
     *     trusted: bool
     * }
     */
    public function governance(string $code): array
    {
        $definition = $this->find($code);
        $row = $this->row($code);

        $current = $definition?->checksum();
        $stored = $row?->rules_checksum;

        $matches = $row instanceof LotteryGame && $definition instanceof GameDefinition
            ? $row->isVerifiedAgainst($current)
            : false;

        return [
            'code' => $code,
            'declared' => $this->declaredStatus($code)->value,
            'registered' => $definition instanceof GameDefinition,
            'status' => $row?->status->value ?? GameRulesStatus::Unverified->value,
            'enabled' => $row instanceof LotteryGame && $row->enabled === true,
            'checksum_stored' => is_string($stored) && $stored !== '' ? $stored : null,
            'checksum_current' => $current,
            'checksum_matches' => $matches,
            'verified_at' => $row?->verified_at?->toIso8601String(),
            'verified_by' => $row?->verified_by,
            'trusted' => $this->isVerifiedAgainstCurrentRules($code),
        ];
    }

    /**
     * Governance picture for every declared game, in configuration order.
     *
     * @return list<array<string, mixed>>
     */
    public function report(): array
    {
        $rows = [];

        foreach ($this->codes() as $code) {
            $rows[] = $this->governance($code);
        }

        return $rows;
    }

    /**
     * Whether the deployment-level kill switch is closed.
     *
     * Read here so the lane has ONE place that knows the switch's name and its
     * fail-closed default (absent configuration must mean OFF, never ON).
     */
    public function masterEnabled(): bool
    {
        return (bool) $this->config->get('lottery_games.master_enabled', false);
    }

    /**
     * Whether the fixture result provider may be used at all in this environment.
     *
     * Fabricated results are a development aid. They are refused in production
     * unconditionally, and elsewhere only when the deployment allows them.
     */
    public function fixturesAllowed(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return (bool) $this->config->get('lottery_games.allow_fixture_provider_outside_production', true);
    }

    /**
     * The operator policy ceilings the lane reads, exposed here so no other class
     * has to know the configuration layout.
     */
    public function rtpCeiling(): string
    {
        $ceiling = $this->config->get('lottery_games.rtp_ceiling', '0.98');

        return is_string($ceiling) && $ceiling !== '' ? $ceiling : '0.98';
    }

    /**
     * Drop the memoised rows so the next read sees committed changes.
     *
     * Needed by the seeders and by tests that write a lottery_games row inside the
     * same request; the container also forgets the whole instance (see the test
     * helper), but a targeted reset keeps a long-running worker honest.
     */
    public function refresh(): void
    {
        $this->rows = [];
        $this->definitions = [];
    }
}
