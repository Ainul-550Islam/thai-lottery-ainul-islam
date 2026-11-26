<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\DTOs\LotteryGames\DigitBetSelection;
use App\Events\BetCancelled;
use App\Models\Bet;
use App\Models\GameAuditEvent;
use App\Services\LotteryGames\GameAuditRecorder;
use App\Services\LotteryGames\GameBetPlacementService;
use LogicException;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * The lane's audit trail: what is recorded, and that it cannot be rewritten.
 *
 * WHY THIS SUITE EXISTS
 * The spec asks for an audit on every money path and on the "Verify rules" action. A
 * trail that exists but is never written, or that a later job can edit, is decoration.
 * These tests pin the three properties an auditor relies on: the rows appear for the real
 * flows (not for a synthetic call), they carry the facts the decision rested on, and they
 * are append-only.
 *
 * MONEY IN THE CONTEXT IS A DECIMAL STRING
 * The same convention as the rest of the lane; the assertions compare strings.
 */
final class GameAuditTrailTest extends GameLaneTestCase
{
    private function recorder(): GameAuditRecorder
    {
        return app(GameAuditRecorder::class);
    }

    public function test_the_recorder_stores_one_row_with_every_field(): void
    {
        $stored = $this->recorder()->record(
            GameAuditRecorder::GAME_ENABLED,
            'lane_game',
            7,
            'game',
            'lane_game',
            ['rules_checksum' => 'abc', 'stake' => '10.00'],
        );

        $this->assertTrue($stored);
        $this->assertSame(1, GameAuditEvent::query()->count());

        $row = GameAuditEvent::query()->firstOrFail();

        $this->assertSame('game.enabled', $row->event);
        $this->assertSame('lane_game', $row->game_code);
        $this->assertSame(7, $row->actor_id);
        $this->assertSame('game', $row->subject_type);
        $this->assertSame('lane_game', $row->subject_id);
        $this->assertSame('10.00', $row->context['stake'], 'amounts stay decimal strings in the context');
        $this->assertNotNull($row->occurred_at);
    }

    public function test_an_audit_row_cannot_be_updated(): void
    {
        $this->recorder()->record(GameAuditRecorder::GAME_ENABLED, 'lane_game');

        $this->expectException(LogicException::class);

        GameAuditEvent::query()->firstOrFail()->update(['event' => 'tampered']);
    }

    public function test_an_audit_row_cannot_be_deleted(): void
    {
        $this->recorder()->record(GameAuditRecorder::GAME_ENABLED, 'lane_game');

        $this->expectException(LogicException::class);

        GameAuditEvent::query()->firstOrFail()->delete();
    }

    public function test_the_vocabulary_is_a_closed_list_of_distinct_names(): void
    {
        $names = GameAuditRecorder::vocabulary();

        $this->assertSame($names, array_values(array_unique($names)), 'two constants must not share a name');
        $this->assertContains(GameAuditRecorder::BET_PLACED, $names);
        $this->assertContains(GameAuditRecorder::RULES_VERIFIED, $names);
        $this->assertContains(GameAuditRecorder::RESULT_PUBLISHED, $names);
    }

    public function test_a_placed_bet_is_audited_with_the_facts_it_was_decided_on(): void
    {
        $lane = $this->openLane();
        ['user' => $user] = $this->player('1000.00');
        $this->limitFor($lane['draw'], '3d_direct', '123');

        $receipt = app(GameBetPlacementService::class)->place(
            userId: (int) $user->getKey(),
            selection: new DigitBetSelection('lane_game', '3d_direct', '123', '10.00'),
            drawId: (int) $lane['draw']->getKey(),
            idempotencyKey: $this->key('audit-placement'),
        );

        $row = GameAuditEvent::query()->where('event', GameAuditRecorder::BET_PLACED)->firstOrFail();

        $this->assertSame('lane_game', $row->game_code);
        $this->assertSame((int) $user->getKey(), $row->actor_id);
        $this->assertSame('bet', $row->subject_type);
        $this->assertSame((string) $receipt['bet_id'], $row->subject_id);
        $this->assertSame('10.00', $row->context['stake']);
        $this->assertSame('9000.00', $row->context['potential_payout']);
        $this->assertSame('123', $row->context['number'], 'the number stays a string');
        $this->assertSame('real', $row->context['funding_source']);
        $this->assertNotSame('', (string) $row->context['rules_checksum'], 'the rule set the bet was priced against is recorded');
    }

    public function test_cancelling_a_bet_audits_the_release_of_its_liability(): void
    {
        $lane = $this->openLane();
        ['user' => $user] = $this->player('1000.00');
        $this->limitFor($lane['draw'], '3d_direct', '123');

        $receipt = app(GameBetPlacementService::class)->place(
            userId: (int) $user->getKey(),
            selection: new DigitBetSelection('lane_game', '3d_direct', '123', '10.00'),
            drawId: (int) $lane['draw']->getKey(),
            idempotencyKey: $this->key('audit-release'),
        );

        event(new BetCancelled(Bet::query()->findOrFail($receipt['bet_id']), 'player_request'));

        $row = GameAuditEvent::query()->where('event', GameAuditRecorder::BET_EXPOSURE_RELEASED)->firstOrFail();

        $this->assertSame((string) $receipt['bet_id'], $row->subject_id);
        $this->assertSame('3d_direct', $row->context['bet_type']);
        $this->assertSame('123', $row->context['number']);
        $this->assertSame('player_request', $row->context['reason']);
    }
}
