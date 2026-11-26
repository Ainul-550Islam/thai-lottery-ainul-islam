<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Log;

/**
 * THE ONE PLACE THAT DECIDES WHICH RULE SET IS IN FORCE.
 *
 * WHY THIS EXISTS
 * config/lottery_games.php is the ONLY source of official rules (Rule §0.3), and the
 * shipped file is deliberately full of REQUIRED_INPUT: an operator's official document
 * has not arrived, and a guessed multiplier is a silent liability. But "the rules are
 * outstanding" and "the software cannot be exercised" are different problems, and only
 * the first one is legitimate. This resolver separates them without ever letting a
 * reference value look like an official one:
 *
 *   layer 1  config/lottery_games.php                 the deployment's own declaration
 *   layer 2  config/lottery_games.profiles.php[name]  a COMPLETE reference rule set
 *                                                    (non-production only)
 *   layer 3  storage/app/lottery_games/rules.override.json
 *                                                    the operator's imported values
 *
 * Each layer above the first REPLACES a game's whole block. It is never merged field
 * by field, and the reason is the point of the class:
 *
 *   A field-level merge produces a rule set that no human being and no document ever
 *   stated — the operator's cutoff combined with a reference multiplier, published as
 *   one checksum and verified as one rule set. It would validate, it would price bets,
 *   and it would be un-reconcilable against any document. All-or-nothing per game is
 *   the only merge that keeps the checksum meaning "this exact rule set".
 *
 * The other two rules exist for the same reason:
 *
 *   COMPLETENESS  a layer that still contains REQUIRED_INPUT is refused for that game
 *                 and reported, never applied partially.
 *   PRODUCTION    a PROFILE is refused outright when APP_ENV=production. The profile
 *                 file is a test fixture that happens to live in config/, so the
 *                 refusal lives in code, not in a comment asking operators to be
 *                 careful. An operator OVERRIDE is allowed in production: those are
 *                 real values a human typed from a real document.
 *   VERIFICATION  a profile-driven game is forced to rules_status=unverified, so the
 *                 DB row (the audited operator decision) is still the only thing that
 *                 can make it sellable.
 *
 * NO DATABASE, NO MONEY, NO SIDE EFFECT
 * resolve() reads configuration and at most one JSON file. It never writes, never
 * queries, never dispatches. It is safe to call from a service provider's boot().
 */
final class RulesProfileResolver
{
    /** Path, relative to storage_path(), of the operator-imported rule override. */
    public const OVERRIDE_RELATIVE_PATH = 'app/lottery_games/rules.override.json';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Application $app,
    ) {}

    /**
     * Resolve the games that are in force, and say which layer supplied each one.
     *
     * @return array{games: array<string, array<string, mixed>>, applied: array<string, string>, notes: list<string>}
     */
    public function resolve(): array
    {
        /** @var array<string, array<string, mixed>> $base */
        $base = array_filter(
            (array) $this->config->get('lottery_games.games', []),
            static fn ($block): bool => is_array($block),
        );

        $production = $this->app->environment('production');
        $notes = [];

        $override = $this->overrideGames($notes);
        $profile = $this->profileGames($notes);

        if ($production && $profile !== null) {
            // Dropped, loudly. A production process that was started with a profile
            // selected is a deployment mistake, and the mistake must not be able to
            // price a stake.
            $notes[] = 'profile refused in production: reference rule sets are not sellable rules.';
            $profile = null;

            Log::critical('lottery_games.rules_profile_refused_in_production', [
                'profile' => $this->profileName(),
            ]);
        }

        $resolved = $this->applyLayers($base, $profile, $override);

        // The layer-reading notes (malformed override, unknown profile, production
        // refusal) explain WHY the applied map looks the way it does, so they belong in
        // front of the per-game notes rather than being replaced by them.
        $resolved['notes'] = array_merge($notes, $resolved['notes']);

        return $resolved;
    }

    /**
     * Resolve, then mutate nothing: the shape the service provider writes into config.
     *
     * @return array{games: array<string, array<string, mixed>>, applied: array<string, string>, notes: list<string>}
     */
    public function applyToRuntimeConfig(): array
    {
        $resolved = $this->resolve();

        if ($resolved['applied'] !== []) {
            $this->config->set('lottery_games.games', $resolved['games']);

            Log::notice('lottery_games.rules_layers_applied', [
                'applied' => $resolved['applied'],
                'notes' => $resolved['notes'],
            ]);
        }

        return $resolved;
    }

    /**
     * The layering itself, as a pure function: no container, no filesystem, no logging.
     *
     * @param  array<string, array<string, mixed>>  $base
     * @param  array<string, array<string, mixed>>|null  $profile
     * @param  array<string, array<string, mixed>>|null  $override
     * @return array{games: array<string, array<string, mixed>>, applied: array<string, string>, notes: list<string>}
     */
    public function applyLayers(array $base, ?array $profile, ?array $override): array
    {
        $games = $base;
        $applied = [];
        $notes = [];

        foreach ([['profile', $profile], ['override', $override]] as [$layer, $blocks]) {
            if (! is_array($blocks) || $blocks === []) {
                continue;
            }

            foreach ($blocks as $code => $block) {
                $code = (string) $code;

                if (! is_array($block)) {
                    $notes[] = $layer.':'.$code.' ignored — the entry is not an array.';

                    continue;
                }

                if (! array_key_exists($code, $games)) {
                    // Layers may not introduce a game the deployment never declared:
                    // a declared code is what seeds the lottery_games row, and a layer
                    // is not a place to add one.
                    $notes[] = $layer.':'.$code.' ignored — the game is not declared in config/lottery_games.php.';

                    continue;
                }

                if (! GameDefinition::isCompleteBlock($block)) {
                    // Not complete in EITHER sense: a placeholder left in it, or a key the
                    // block simply does not have. Both mean a human has not finished
                    // stating this rule set, and a finished one is the only thing this
                    // layer accepts.
                    $notes[] = $layer.':'.$code.' ignored — it is not a complete block (a value is still '
                        .GameDefinition::REQUIRED_INPUT.' or a required key is absent); a partial layer is '
                        .'never merged into a rule set.';

                    continue;
                }

                if ($layer === 'profile') {
                    // A reference rule set can never look verified. Enforcement of the
                    // rules is an operator action recorded on the row, so anything a
                    // profile says about its own status is discarded here.
                    $block['rules_status'] = 'unverified';
                }

                $games[$code] = $block;
                $applied[$code] = $layer;
            }
        }

        return ['games' => $games, 'applied' => $applied, 'notes' => $notes];
    }

    /**
     * Whether a block still carries the literal REQUIRED_INPUT placeholder anywhere.
     *
     * Delegated to GameDefinition so the resolver and the import validator cannot hold two
     * different opinions about what "still outstanding" means.
     *
     * @param  array<string, mixed>  $block
     */
    public static function stillRequiresInput(array $block): bool
    {
        return GameDefinition::containsRequiredInput($block);
    }

    /**
     * The profile selected by LOTTERY_GAMES_PROFILE, or null when none is selected.
     *
     * @param  list<string>  $notes
     * @return array<string, array<string, mixed>>|null
     */
    public function profileGames(array &$notes = []): ?array
    {
        $name = $this->profileName();

        if ($name === null) {
            return null;
        }

        /** @var array<string, mixed> $profiles */
        $profiles = (array) $this->config->get('lottery_games.profiles', []);

        $profile = $profiles[$name] ?? null;

        if (! is_array($profile) || ! is_array($profile['games'] ?? null)) {
            $notes[] = 'profile "'.$name.'" is not defined in config/lottery_games.profiles.php.';

            return null;
        }

        return $profile['games'];
    }

    /**
     * The operator's imported values, or null when no override file exists.
     *
     * A malformed or unreadable override is NOT an error: it is a file an operator
     * may be half-way through writing, and refusing to boot over it would turn a typo
     * into an outage. It is reported in `notes` and ignored, which leaves the
     * deployment on its declared (fail-closed) configuration.
     *
     * @param  list<string>  $notes
     * @return array<string, array<string, mixed>>|null
     */
    public function overrideGames(array &$notes = []): ?array
    {
        $path = $this->overridePath();

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if (! is_string($raw) || trim($raw) === '') {
            $notes[] = 'override file is empty and was ignored.';

            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            $notes[] = 'override file is not valid JSON and was ignored.';

            return null;
        }

        $games = $decoded['games'] ?? $decoded;

        if (! is_array($games)) {
            $notes[] = 'override file has no "games" object and was ignored.';

            return null;
        }

        /** @var array<string, array<string, mixed>> $games */
        return $games;
    }

    public function overridePath(): ?string
    {
        try {
            return $this->app->storagePath(self::OVERRIDE_RELATIVE_PATH);
        } catch (\Throwable) {
            // A storage path resolution failure is not a reason to fail a request.
            return null;
        }
    }

    public function profileName(): ?string
    {
        $name = $this->config->get('lottery_games.profile');

        if (! is_string($name)) {
            return null;
        }

        $name = trim($name);

        return $name === '' ? null : $name;
    }
}
