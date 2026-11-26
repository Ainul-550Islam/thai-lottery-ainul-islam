<?php

declare(strict_types=1);

namespace Tests\Support\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Enums\GameRulesStatus;
use App\Models\LotteryGame;
use App\Services\LotteryGames\GameRegistry;

/**
 * One complete, VALID digit-game configuration, built the way the real config is built.
 *
 * WHY A SHARED FIXTURE AND NOT A LITERAL IN EACH TEST
 * A digit game's rule block has fifteen top-level keys and a nested market table, and the
 * validator refuses any block that is internally inconsistent — the widest market must equal
 * the draw width, a cutoff must fit inside the draw cadence, a per-number ceiling above the
 * per-draw ceiling never binds, a multiplier above the RTP ceiling is a breach. Once two test
 * files copy that block, one of them drifts, and the drift shows up as a validation problem
 * in a test that has nothing to do with validation. So the block lives here ONCE and each
 * test overrides only the field it is about.
 *
 * WHY THE NUMBERS ARE THESE NUMBERS
 *   digits 4, widest market 4     so validateWidths() passes
 *   4d_direct 6000x               RTP 0.60 — comfortably inside the 0.98 ceiling
 *   4d_any_order 300x             RTP 0.72 at the BEST case (24 arrangements of 0123),
 *                                which is the number RtpAnalyzer uses; a 1500x table here
 *                                would be a real breach and would make every "valid game"
 *                                test quietly fail on RTP instead of on what it tests
 *   2d_bottom 90x                 RTP 0.90
 *   cutoff 300 inside a daily draw so nothing closes before it opens
 *   per-number 50,000 under per-draw 100,000 so the per-number cap actually binds
 * The comment is here, not in the values, so a future edit has to read it.
 *
 * WHY THE TRAIT ALSO CREATES THE ROW
 * Configuration alone is not a game: the registry reports "declared but not registered", the
 * governance verdict is "never verified", and the availability service refuses everything.
 * A test that wants a SELLABLE game needs both — the config block and a verified row whose
 * checksum matches the deployed block. `verifiedGame()` produces exactly that pair, and the
 * two lower-level helpers exist so a test can produce any half-built state on purpose.
 */
trait MakesGameConfig
{
    /**
     * A complete rule block for a 4-digit nightly game.
     *
     * @param  array<string, mixed>  $overrides  deep-merged over the blueprint
     * @return array<string, mixed>
     */
    protected function gameConfig(string $code = 'fixture_game', array $overrides = []): array
    {
        $blueprint = [
            'code' => $code,
            'name' => 'Fixture Game',
            'digits' => 4,
            'timezone' => 'Asia/Bangkok',
            'draw_cron' => ['0 21 * * *'],
            'cutoff_seconds' => 300,
            'bet_types' => [
                '4d_direct' => [
                    'selection_digits' => 4,
                    'match_mode' => 'exact',
                    'payout_multiplier' => '6000',
                    'market_key' => '3d_direct',
                ],
                '4d_any_order' => [
                    'selection_digits' => 4,
                    'match_mode' => 'any_order',
                    'payout_multiplier' => '300',
                    'market_key' => '3d_direct',
                ],
                '2d_bottom' => [
                    'selection_digits' => 2,
                    'match_mode' => 'suffix',
                    'payout_multiplier' => '90',
                    'market_key' => '2d_bottom',
                ],
            ],
            'min_stake' => '1.00',
            'max_stake' => '1000.00',
            'max_liability_per_draw' => '100000.00',
            'max_liability_per_number' => '50000.00',
            'rules_status' => 'verified',
            'settlement_market_key' => '2d_bottom',
            'publication_bridge' => [
                'first_prize' => 'digits_right_padded',
                'bottom_two' => 'last:2',
            ],
            'result_source' => [
                'driver' => 'official',
                'base_url' => 'https://results.example.test',
                'latest_path' => '/latest',
                'draw_no_field' => 'draw_no',
                'digits_field' => 'digits',
            ],
        ];

        return $this->mergeGameConfig($blueprint, $overrides);
    }

    /**
     * Declare a game in configuration and hand back its definition.
     *
     * NO DATABASE ROW IS CREATED: this is the "declared, never verified" state, which is
     * what every game looks like before an operator signs it off.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function declareGame(string $code = 'fixture_game', array $overrides = []): GameDefinition
    {
        return $this->registerGameConfig($code, $this->gameConfig($code, $overrides));
    }

    /**
     * Register a COMPLETE rule block, exactly as given.
     *
     * WHY THIS EXISTS SEPARATELY FROM declareGame()
     * gameConfig() deep-merges overrides, which is right for changing one value and wrong
     * for replacing the market table: a three-digit game whose `bet_types` were merged over
     * the four-digit blueprint would keep the blueprint's four-digit exact market, and the
     * validator would (correctly) refuse it for declaring a market wider than the draw. A
     * suite that needs a different SHAPE of game builds the whole block and registers it
     * here, so the shape it asserts on is the shape that was registered.
     *
     * @param  array<string, mixed>  $config
     */
    protected function registerGameConfig(string $code, array $config): GameDefinition
    {
        config()->set('lottery_games.games.'.$code, $config);

        // The registry memoises the parsed configuration; a test that changes config
        // and does not refresh would assert against the previous game.
        $this->refreshGameRegistry();

        return GameDefinition::fromConfig($code, $config);
    }

    /**
     * Declared AND registered, with a checksum that matches the deployed block.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function verifiedGame(
        string $code = 'fixture_game',
        array $overrides = [],
        bool $enabled = true,
    ): LotteryGame {
        $definition = $this->declareGame($code, $overrides);

        $row = LotteryGame::query()->firstOrNew(['code' => $code]);
        $row->fill([
            'name' => $definition->name,
            'status' => GameRulesStatus::Verified->value,
            'enabled' => $enabled,
            'rules_checksum' => $definition->checksum(),
            'verified_at' => now(),
        ]);
        $row->save();

        $this->refreshGameRegistry();

        return $row->fresh();
    }

    /**
     * A row that exists with no matching configuration checksum, i.e. drift.
     *
     * The stored checksum is a real hash of something else, because a forged value that is
     * not a hash would be refused by shape before the comparison under test is reached.
     */
    protected function driftedGame(string $code = 'fixture_game', array $overrides = []): LotteryGame
    {
        $row = $this->verifiedGame($code, $overrides);

        $row->forceFill(['rules_checksum' => hash('sha256', 'a rule set that was replaced')])->save();

        $this->refreshGameRegistry();

        return $row->fresh();
    }

    /**
     * Switch the deployment-level kill switch and the real-money gate for the lane.
     *
     * Both are read through the registry, which caches nothing about them, but the refresh
     * keeps one code path for every config change in these tests.
     */
    protected function enableGameLane(bool $enabled = true): void
    {
        config()->set('lottery_games.master_enabled', $enabled);

        $this->refreshGameRegistry();
    }

    /**
     * Drop the registry's memoised view of configuration.
     */
    protected function refreshGameRegistry(): void
    {
        app(GameRegistry::class)->refresh();
    }

    /**
     * Deep-merge overrides, so a test can change one market without restating the other two.
     *
     * Lists REPLACE rather than merge: `draw_cron => ['30 20 * * *']` must not leave the
     * blueprint's draw time behind, because two draw times is a different game.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function mergeGameConfig(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && ! array_is_list($value)) {
                $base[$key] = $this->mergeGameConfig($base[$key], $value);

                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }
}
