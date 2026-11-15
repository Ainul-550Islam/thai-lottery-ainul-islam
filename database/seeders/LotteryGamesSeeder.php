<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\GameRulesStatus;
use App\Models\LotteryGame;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Config;

/**
 * Registers the batch-2 digit games with the operator layer.
 *
 * WHAT THIS SEEDER DOES
 * It creates one lottery_games row per game declared in config/lottery_games.php,
 * in the only state a shipped game may honestly be in: rules_status=unverified
 * and enabled=false. Rule §0.3 forbids inventing official rules, so a fresh
 * deployment has two games that are completely described structurally — digit
 * count placeholder, bet type placeholders, REQUIRED_INPUT multipliers, no draw
 * schedule — and completely unsellable. An operator verifies the rules and
 * enables the game through the permission-gated Filament actions, which stamp
 * verified_by, verified_at and rules_checksum.
 *
 * WHY IT IS IDEMPOTENT AND NON-DESTRUCTIVE
 * Re-running `db:seed` on a live database must never undo an operator's decision.
 * The seeder therefore uses firstOrCreate on `code` and, for a row that already
 * exists, only refreshes the display name. It never writes status, enabled,
 * rules_checksum, verified_by or verified_at on an existing row: a verification is
 * an audited operator act, and a seeder is not an operator.
 *
 * WHY IT READS THE REGISTRY
 * The list of games, and their names, are configuration — the same single source
 * of truth the rest of the lane reads through GameRegistry. The seeder takes the
 * raw config block here because the registry depends on the database row that
 * this seeder is creating, and because a seeder is deployment tooling rather than
 * a runtime rule reader: no rule value is copied into the row, only the code and
 * the name.
 *
 * WHAT IT DOES NOT DO
 * It does not create draws, results, exposures or attributions. It does not
 * schedule anything. A game with no verified rules must not have a draw, because
 * a draw implies a schedule, and a schedule for an unverified game is invented.
 */
class LotteryGamesSeeder extends Seeder
{
    /**
     * The games this batch ships. Kept explicit rather than a glob of the config
     * block so that adding a game to config does not silently start registering
     * it: shipping a game is a decision, and it is made here in one visible list.
     */
    private const GAMES = ['speed_88', 'lao_hanoi_4d'];

    public function run(): void
    {
        /** @var array<string, array<string, mixed>> $games */
        $games = (array) Config::get('lottery_games.games', []);

        foreach (self::GAMES as $code) {
            $config = $games[$code] ?? null;

            if (! is_array($config)) {
                $this->command?->warn(sprintf(
                    'LotteryGamesSeeder: %s is not declared in config/lottery_games.php; skipped.',
                    $code,
                ));

                continue;
            }

            $existing = LotteryGame::query()->where('code', $code)->first();

            if ($existing instanceof LotteryGame) {
                // Never touch the operator half of an existing row.
                $name = (string) ($config['name'] ?? $code);

                if ($existing->name !== $name) {
                    $existing->forceFill(['name' => $name])->save();
                }

                continue;
            }

            LotteryGame::query()->create([
                'code' => $code,
                'name' => (string) ($config['name'] ?? $code),

                // The declared status is 'unverified' in the shipped config. of()
                // maps anything unknown to Unverified, so a typo in config cannot
                // produce a verified game.
                'status' => GameRulesStatus::of($config['rules_status'] ?? null),

                // No checksum: nothing has been verified, and a game with a
                // verification but no checksum is refused by
                // LotteryGame::isVerifiedAgainst() precisely so this stays true.
                'rules_checksum' => null,

                'enabled' => false,
                'verified_by' => null,
                'verified_at' => null,

                // Provenance for the audit trail. Not a rule value.
                'metadata' => [
                    'registered_by' => 'LotteryGamesSeeder',
                    'registered_at' => now()->toIso8601String(),
                    'note' => 'Rules are unverified and the game is disabled until an operator verifies '
                        .'config/lottery_games.php and enables the game.',
                ],
            ]);
        }

        $this->command?->info(sprintf(
            'LotteryGamesSeeder: %d game(s) registered (all unverified and disabled by design).',
            count(self::GAMES),
        ));
    }
}
