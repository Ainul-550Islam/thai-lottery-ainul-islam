<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\DTOs\LotteryGames\GameCapability;
use App\Enums\DrawStatus;
use App\Enums\GameRulesStatus;
use App\Exceptions\LotteryGameException;
use App\Models\Draw;
use App\Services\LotteryGames\GameAvailabilityService;
use App\Services\LotteryGames\GameRegistry;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * The nine gates between a configured digit game and a sold bet.
 *
 * WHY THIS SUITE IS THE CENTRE OF THE LANE
 * Every other component in Batch 2 assumes a game that may sell. This is the component
 * that decides it, and the decision is deliberately paranoid: nine independent questions,
 * each of which can only REFUSE. A game sells only when all nine answer yes. The tests
 * below therefore do the thing that is easy to skip and hard to get wrong: they take a
 * fully sellable game and close one gate at a time, asserting that exactly that gate
 * fails, that the refusal reason names it, and that nothing else silently flips.
 *
 * WHY THE FAILURE REASON IS ASSERTED AND NOT JUST THE BOOLEAN
 * "Not sellable" is not actionable. An operator needs to know whether to open the master
 * switch, verify the rules, configure a result source, create a draw, or talk to
 * compliance — five different people in four different systems. The reason string is the
 * hand-off, so it is part of the contract.
 *
 * WHY SUSPENSION IS TESTED SEPARATELY FROM UNVERIFIED
 * They look alike (both stop selling) and mean opposite things. Unverified means "nobody
 * has accepted this payout table yet"; suspended means "somebody accepted it and then
 * withdrew it". Reporting the second as the first sends an operator hunting for a missing
 * configuration value that does not exist, so the capability carries a different status
 * and a different reason for each.
 *
 * WHAT IS NOT TESTED HERE
 * The arithmetic of headroom and the digit matcher. They have their own suites; this one
 * only asks whether a game is allowed to trade.
 */
final class GameAvailabilityTest extends GameLaneTestCase
{
    private function availability(): GameAvailabilityService
    {
        return app(GameAvailabilityService::class);
    }

    private function registry(): GameRegistry
    {
        return app(GameRegistry::class);
    }

    // =========================================================================
    // The happy path, gate by gate
    // =========================================================================

    public function test_a_fully_configured_verified_game_with_an_open_draw_is_sellable(): void
    {
        $this->openLane('lane_game');

        $capability = $this->availability()->capability('lane_game');

        $this->assertTrue($capability->isSellable());
        $this->assertSame(GameCapability::STATUS_CONFIGURED, $capability->status);
        $this->assertSame('sellable', $capability->reason);
        $this->assertSame([], $capability->missing);

        // All nine, named, and every one of them true.
        $this->assertSame([
            'master_switch',
            'game_enabled',
            'rules_verified',
            'checksum_matches',
            'official_result_source',
            'liability_limits_configured',
            'seated_open_draw',
            'real_money_open',
            'legal_documents_published',
        ], array_keys($capability->gates));

        foreach ($capability->gates as $gate => $passed) {
            $this->assertTrue($passed, 'gate '.$gate.' should pass for a healthy game');
        }
    }

    public function test_the_nine_gates_are_reported_in_their_documented_order(): void
    {
        $this->openLane('lane_game');

        $gates = $this->availability()->gates('lane_game');

        $this->assertCount(9, $gates);
        $this->assertSame(
            [
                'master_switch',
                'game_enabled',
                'rules_verified',
                'checksum_matches',
                'official_result_source',
                'liability_limits_configured',
                'seated_open_draw',
                'real_money_open',
                'legal_documents_published',
            ],
            array_keys($gates),
        );
    }

    // =========================================================================
    // One gate at a time
    // =========================================================================

    public function test_the_deployment_kill_switch_alone_stops_every_game(): void
    {
        $this->openLane('lane_game');

        $this->enableGameLane(false);
        config()->set('real_money.enabled', true);
        $this->refreshGameRegistry();

        $capability = $this->availability()->capability('lane_game');

        $this->assertFalse($capability->isSellable());
        $this->assertFalse($capability->gates['master_switch']);
        $this->assertSame('game_lane_disabled', $capability->reason);
    }

    public function test_an_unverified_game_is_refused_even_when_everything_else_is_ready(): void
    {
        $this->openLane('lane_game');

        \App\Models\LotteryGame::query()->where('code', 'lane_game')->update([
            'status' => GameRulesStatus::Unverified->value,
            'rules_checksum' => null,
        ]);
        $this->refreshGameRegistry();

        $capability = $this->availability()->capability('lane_game');

        $this->assertFalse($capability->isSellable());
        $this->assertFalse($capability->gates['rules_verified']);
        $this->assertFalse($capability->gates['checksum_matches']);
        $this->assertSame('game_rules_unverified', $capability->reason);
    }

    public function test_a_checksum_that_no_longer_matches_the_deployed_rules_stops_selling(): void
    {
        $this->openLane('lane_game');

        // State the row says Verified, but the payout table it was verified against is
        // gone: this is what an edit to config/lottery_games.php looks like afterwards.
        \App\Models\LotteryGame::query()->where('code', 'lane_game')->update([
            'rules_checksum' => hash('sha256', 'a replaced rule set'),
        ]);
        $this->refreshGameRegistry();

        $gates = $this->availability()->gates('lane_game');

        $this->assertTrue($gates['rules_verified'], 'the row is still marked verified');
        $this->assertFalse($gates['checksum_matches'], 'but the hash no longer matches');
        $this->assertFalse($this->availability()->canSell('lane_game'));
        $this->assertSame('game_rules_checksum_stale', $this->availability()->capability('lane_game')->reason);
    }

    public function test_suspension_is_reported_as_suspended_and_not_as_a_configuration_gap(): void
    {
        ['game' => $game] = $this->openLane('lane_game');

        $game->markSuspended(1, 'withdrawn while the provider is investigated');
        $game->forceFill(['enabled' => false])->save();
        $this->refreshGameRegistry();

        $capability = $this->availability()->capability('lane_game');

        $this->assertFalse($capability->isSellable());
        $this->assertSame(GameCapability::STATUS_SUSPENDED, $capability->status);
        $this->assertNotSame('game_not_configured', $capability->reason);
        $this->assertStringContainsString('suspend', $capability->reason);
    }

    public function test_a_game_with_no_open_draw_cannot_sell(): void
    {
        ['draw' => $draw] = $this->openLane('lane_game');

        $draw->forceFill(['status' => DrawStatus::Closed->value])->save();
        $this->refreshGameRegistry();

        $gates = $this->availability()->gates('lane_game');

        $this->assertFalse($gates['seated_open_draw']);
        $this->assertSame('game_no_open_draw', $this->availability()->capability('lane_game')->reason);
    }

    public function test_an_open_draw_whose_betting_window_has_closed_is_not_an_open_draw(): void
    {
        ['draw' => $draw] = $this->openLane('lane_game');

        // Status still says open; the window says otherwise. A scheduler that has not yet
        // closed the draw must not be able to take stakes for it.
        $draw->forceFill([
            'betting_close_at' => now()->subMinute(),
        ])->save();

        $this->assertFalse($this->availability()->gates('lane_game')['seated_open_draw']);
        $this->assertFalse($this->availability()->canSell('lane_game'));
    }

    public function test_the_real_money_gate_closes_the_lane_even_for_a_perfect_game(): void
    {
        $this->openLane('lane_game');

        config()->set('real_money.enabled', false);

        $capability = $this->availability()->capability('lane_game');

        $this->assertFalse($capability->isSellable());
        $this->assertFalse($capability->gates['real_money_open']);
        $this->assertSame('real_money_closed', $capability->reason);
    }

    public function test_a_missing_liability_cap_closes_the_lane_and_names_the_gap(): void
    {
        $this->openLane('lane_game', ['max_liability_per_number' => null]);

        $capability = $this->availability()->capability('lane_game');

        $this->assertFalse($capability->isSellable());
        $this->assertFalse($capability->gates['liability_limits_configured']);
        $this->assertSame('game_liability_limits_not_configured', $capability->reason);
        // The gap is reported as the failing GATE, which is what an operator can act on.
        $this->assertContains('gate:liability_limits_configured', $capability->missing);
    }

    public function test_a_result_source_that_is_still_a_placeholder_closes_the_lane(): void
    {
        $this->openLane('lane_game', [
            'result_source' => [
                'driver' => 'official',
                'base_url' => 'REQUIRED_INPUT',
                'latest_path' => '/latest',
                'draw_no_field' => 'draw_no',
                'digits_field' => 'digits',
            ],
        ]);

        $gates = $this->availability()->gates('lane_game');

        $this->assertFalse($gates['official_result_source']);
        $this->assertFalse($this->availability()->canSell('lane_game'));
    }

    public function test_a_fixture_result_source_can_never_make_a_game_sellable(): void
    {
        $this->openLane('lane_game', [
            'result_source' => ['driver' => 'fixture'],
        ]);

        // Fabricated results are a development aid; the fixtures switch does not and must
        // not satisfy the official-source gate.
        $this->assertTrue($this->registry()->fixturesAllowed());
        $this->assertFalse($this->availability()->gates('lane_game')['official_result_source']);
    }

    // =========================================================================
    // The refusals carry their evidence
    // =========================================================================

    public function test_assert_sellable_refuses_with_every_gate_verdict_attached(): void
    {
        $this->openLane('lane_game');
        $this->enableGameLane(false);

        try {
            $this->availability()->assertSellable('lane_game');
            $this->fail('a disabled lane must refuse');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_not_sellable', $e->errorCode());

            $context = $e->context();
            $this->assertSame('lane_game', $context['game_code']);
            $this->assertSame('game_lane_disabled', $context['reason'], 'the reason travels with the refusal');
            $this->assertSame('NOT_CONFIGURED', $context['status']);
            $this->assertArrayHasKey('gates', $context);
            $this->assertFalse($context['gates']['master_switch']);
            $this->assertSame('game_lane_disabled', $e->getMessage());
        }
    }

    public function test_assert_sellable_returns_quietly_for_a_sellable_game(): void
    {
        $this->openLane('lane_game');

        $this->availability()->assertSellable('lane_game');

        $this->assertTrue(true, 'no exception is the assertion');
    }

    public function test_an_undeclared_game_is_reported_as_unknown_not_as_unconfigured(): void
    {
        try {
            $this->availability()->capability('no_such_game');
            $this->fail('an unknown game must be refused');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_unknown', $e->errorCode());
        }
    }

    public function test_capabilities_covers_every_declared_game_in_configuration_order(): void
    {
        $this->openLane('lane_game');

        $capabilities = $this->availability()->capabilities();

        $this->assertSame($this->registry()->codes(), array_keys($capabilities));
        $this->assertArrayHasKey('speed_88', $capabilities);
        $this->assertArrayHasKey('lao_hanoi_4d', $capabilities);

        // The shipped configuration is deliberately incomplete: the two live games must
        // report why, not merely that they cannot sell.
        $this->assertFalse($capabilities['speed_88']->isSellable());
        $this->assertSame(GameCapability::STATUS_NOT_CONFIGURED, $capabilities['speed_88']->status);
        // Nothing is enabled for a game nobody configured, so the first failing gate is
        // `game_enabled`; the real gap (every REQUIRED_INPUT) travels in `missing`.
        $this->assertSame('game_disabled', $capabilities['speed_88']->reason);
        $this->assertContains('digits', $capabilities['speed_88']->missing);
        $this->assertContains('cutoff_seconds', $capabilities['speed_88']->missing);

        // And the game this suite configured is the only sellable one.
        $this->assertTrue($capabilities['lane_game']->isSellable());
    }

    public function test_a_game_row_that_was_never_created_is_not_registered_and_cannot_sell(): void
    {
        // Declared in configuration only: no row, so no verification, no checksum.
        $this->declareGame('lane_game', $this->laneGameConfig('lane_game'));
        $this->enableGameLane(true);
        config()->set('real_money.enabled', true);
        $this->refreshGameRegistry();

        $capability = $this->availability()->capability('lane_game');

        $this->assertFalse($capability->isSellable());
        // The first failing gate in GATE_ORDER is `game_enabled`, so that is the reason.
        $this->assertSame('game_disabled', $capability->reason);
        $this->assertContains('gate:game_enabled', $capability->missing);
        $this->assertFalse($capability->gates['game_enabled']);
        $this->assertFalse($capability->gates['rules_verified']);
    }

    public function test_the_registry_agrees_with_the_capability_about_trust(): void
    {
        $this->openLane('lane_game');

        $this->assertTrue($this->registry()->isVerifiedAgainstCurrentRules('lane_game'));
        $this->assertTrue($this->registry()->isEnabled('lane_game'));

        $governance = $this->registry()->governance('lane_game');

        $this->assertTrue($governance['trusted']);
        $this->assertTrue($governance['checksum_matches']);
        $this->assertSame(GameRulesStatus::Verified->value, $governance['status']);
    }
}
