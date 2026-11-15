<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\Services\LotteryGames\GameRegistry;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * `php artisan lottery-games:validate-rules` — the gate that runs before a deploy.
 *
 * WHY A COMMAND AND A TEST FOR IT
 * The rules validator and the RTP analyzer are units; a unit test proves they compute the
 * right answer for the input it hands them. The command is the thing that decides whether
 * CI lets a deployment through, so what matters here is the part no unit test can cover:
 * which games it looks at, what it prints when a value is still REQUIRED_INPUT, and — the
 * whole point — that its EXIT CODE is non-zero for every kind of defect.
 *
 * A green build is only meaningful if the gate actually fails. Each test below therefore
 * asserts the exit code FIRST and the message second, because an exit code that stays 0
 * while printing an error is the failure mode that lets an unpriced payout table ship.
 *
 * WHAT IT MUST NOT DO
 * Reach the network, touch the database, or guess a missing value. It reports, and it
 * refuses. The tests assert that a placeholder is named as a placeholder - an operator has
 * to be able to find the key in config/lottery_games.php from the output alone.
 */
final class ValidateRulesCommandTest extends GameLaneTestCase
{
    private function registry(): GameRegistry
    {
        return app(GameRegistry::class);
    }

    /**
     * @return array{exit: int, output: string}
     */
    private function gate(?string $game = null): array
    {
        $parameters = $game === null ? [] : ['game' => $game];
        $exit = Artisan::call('lottery-games:validate-rules', $parameters);

        return ['exit' => $exit, 'output' => Artisan::output()];
    }

    // =========================================================================
    // The shipped configuration must NOT pass
    // =========================================================================

    public function test_the_shipped_placeholder_configuration_fails(): void
    {
        // Both shipped games are full of REQUIRED_INPUT: no operator has supplied rules.
        $result = $this->gate();

        $this->assertSame(1, $result['exit'], 'a configuration nobody has filled in must not pass the gate');
        $this->assertStringContainsString('speed_88', $result['output']);
        $this->assertStringContainsString('lao_hanoi_4d', $result['output']);
        $this->assertStringContainsString('Missing operator input', $result['output']);
        $this->assertStringContainsString('FAILED', $result['output']);
    }

    public function test_the_output_names_the_configuration_key_an_operator_must_fill_in(): void
    {
        $result = $this->gate('speed_88');

        // Paths, not prose: the operator should be able to find the line in the file.
        $this->assertStringContainsString('digits', $result['output']);
        $this->assertStringContainsString('cutoff_seconds', $result['output']);
        $this->assertStringContainsString('bet_types.4d_direct.payout_multiplier', $result['output']);
    }

    public function test_one_game_can_be_validated_on_its_own(): void
    {
        $result = $this->gate('lao_hanoi_4d');

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('lao_hanoi_4d', $result['output']);
        $this->assertStringNotContainsString('speed_88', $result['output']);
    }

    public function test_an_unknown_game_is_a_failure_not_a_silent_pass(): void
    {
        $result = $this->gate('no_such_game');

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('unknown game', $result['output']);
    }

    // =========================================================================
    // A complete rule set must PASS
    // =========================================================================

    public function test_a_complete_rule_set_inside_the_ceiling_passes(): void
    {
        $this->declareLaneGame('lane_game');

        $result = $this->gate('lane_game');

        $this->assertSame(0, $result['exit'], 'a complete, sane rule set must exit zero');
        $this->assertStringContainsString('OK', $result['output']);

        // The RTP table is printed, because the operator sign-off is about the numbers.
        $this->assertStringContainsString('3d_direct', $result['output']);
        $this->assertStringContainsString('Theoretical RTP', $result['output']);
        $this->assertStringContainsString('0.90', $result['output'], '900x on one in a thousand');
    }

    public function test_every_declared_game_is_checked_when_no_argument_is_given(): void
    {
        $this->declareLaneGame('lane_game');

        $result = $this->gate();

        // The complete game is reported OK, the two placeholders fail the run.
        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('lane_game', $result['output']);
        $this->assertStringContainsString('speed_88', $result['output']);
        $this->assertStringContainsString('lao_hanoi_4d', $result['output']);
    }

    // =========================================================================
    // Each kind of defect fails on its own
    // =========================================================================

    public function test_a_multiplier_above_the_ceiling_fails_the_gate(): void
    {
        // 12,000x on a one-in-a-thousand market returns 12.00 of every unit staked.
        $this->declareLaneGame('lane_game', [
            'bet_types' => [
                '3d_direct' => [
                    'selection_digits' => 3,
                    'match_mode' => 'exact',
                    'payout_multiplier' => '12000',
                    'market_key' => '3d_direct',
                ],
                '2d_bottom' => [
                    'selection_digits' => 2,
                    'match_mode' => 'suffix',
                    'payout_multiplier' => '90',
                    'market_key' => '2d_bottom',
                ],
            ],
        ]);

        $result = $this->gate('lane_game');

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('RTP ceiling breached', $result['output']);
        $this->assertStringContainsString('3d_direct', $result['output']);
    }

    public function test_a_cap_that_can_never_bind_fails_the_gate(): void
    {
        // A per-number cap above the per-draw cap can never be reached, so one number
        // could consume the whole draw.
        $this->declareLaneGame('lane_game', [
            'max_liability_per_number' => '500000.00',
            'max_liability_per_draw' => '100000.00',
        ]);

        $result = $this->gate('lane_game');

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('per-number cap never binds', $result['output']);
    }

    public function test_a_cutoff_longer_than_the_draw_cadence_fails_the_gate(): void
    {
        // A daily draw with a 25-hour cutoff closes before it opens: nothing is ever
        // sellable and nothing looks broken.
        $this->declareLaneGame('lane_game', [
            'cutoff_seconds' => 90000,
        ]);

        $result = $this->gate('lane_game');

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('cutoff_seconds', $result['output']);
        $this->assertStringContainsString('cadence', $result['output']);
    }

    public function test_a_market_wider_than_the_draw_fails_the_gate(): void
    {
        // A four-digit market on a three-digit draw can never win.
        $this->declareLaneGame('lane_game', [
            'bet_types' => [
                '4d_direct' => [
                    'selection_digits' => 4,
                    'match_mode' => 'exact',
                    'payout_multiplier' => '900',
                    'market_key' => '3d_direct',
                ],
            ],
        ]);

        $result = $this->gate('lane_game');

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('can never win', $result['output']);
    }

    public function test_a_fixture_result_source_is_reported_as_a_warning_but_still_passes(): void
    {
        // Fabricated results are legal in development; the gate names the risk loudly and
        // leaves the exit code alone, because refusing here would block local work.
        $this->declareLaneGame('lane_game', [
            'result_source' => ['driver' => 'fixture'],
        ]);

        $result = $this->gate('lane_game');

        $this->assertSame(0, $result['exit'], 'a fixture source is a warning, not a defect');
        $this->assertStringContainsString('OK', $result['output']);
    }

    public function test_the_gate_reads_the_live_configuration(): void
    {
        $this->declareLaneGame('lane_game');

        $this->assertSame(0, $this->gate('lane_game')['exit']);

        // An operator edits the payout table. In production the gate runs as its own
        // process and reads the file fresh; in-process the registry memoises the parsed
        // block (deliberately - a request must not re-parse configuration it has already
        // validated), so the refresh below stands in for "a new process started".
        config()->set('lottery_games.games.lane_game.bet_types.3d_direct.payout_multiplier', '12000');
        $this->refreshGameRegistry();

        $result = $this->gate('lane_game');

        $this->assertSame(1, $result['exit'], 'the edited table must fail the gate');
        $this->assertStringContainsString('RTP ceiling breached', $result['output']);
    }
}
