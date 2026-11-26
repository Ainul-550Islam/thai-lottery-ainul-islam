<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\DTOs\LotteryGames\DigitBetSelection;
use App\Models\Bet;
use App\Models\GameAuditEvent;
use App\Models\GameBetAttribution;
use App\Services\LotteryGames\GameAuditRecorder;
use App\Services\LotteryGames\GameBetPlacementService;
use App\Services\LotteryGames\LiabilityExposureService;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * A placement that died between buying the bet and attributing it.
 *
 * THE WINDOW
 * Batch 1's purchase commits in its own transaction; the lane's attribution commits in
 * another one afterwards. If the process dies between the two, the bet exists, the wallet
 * is debited and the liability is reserved, but the lane has no memory of it. The client
 * retries with the same idempotency key.
 *
 * WHAT MUST HAPPEN
 * The retry must finish the attribution and NOTHING ELSE: no second reservation (which
 * would leave the operator carrying double the exposure on that number for one bet), no
 * second purchase, no second debit.
 *
 * HOW THE CRASH IS SIMULATED
 * A real placement is made, then the attribution row and the bet's game columns are
 * erased - exactly the state a crash in that window leaves behind. The retry is then the
 * real service, unmodified.
 */
final class PlacementRecoveryTest extends GameLaneTestCase
{
    public function test_a_retry_after_a_lost_attribution_reserves_and_spends_nothing_twice(): void
    {
        $lane = $this->openLane();
        ['user' => $user, 'wallet' => $wallet] = $this->player('1000.00');
        $this->limitFor($lane['draw'], '3d_direct', '123');

        $service = app(GameBetPlacementService::class);
        $exposure = app(LiabilityExposureService::class);
        $key = $this->key('recovery-key');
        $selection = new DigitBetSelection('lane_game', '3d_direct', '123', '10.00');
        $drawId = (int) $lane['draw']->getKey();

        $first = $service->place((int) $user->getKey(), $selection, $drawId, $key);

        $reservedBefore = $exposure->exposureFor($drawId, 'lane_game', '123', '3d_direct');
        $this->assertSame('9000.00', $reservedBefore);

        // The crash: the bet and its reservation survive, the lane's memory of it does not.
        GameBetAttribution::query()->where('bet_id', $first['bet_id'])->delete();
        Bet::query()->whereKey($first['bet_id'])->update([
            'game_code' => null,
            'game_number' => null,
            'game_bet_type' => null,
        ]);

        $retry = $service->place((int) $user->getKey(), $selection, $drawId, $key);

        $this->assertSame($first['bet_id'], $retry['bet_id'], 'the same bet, not a second one');
        $this->assertSame('replayed', $retry['status']);
        $this->assertSame(1, Bet::query()->count());
        $this->assertSame('990.00', $wallet->fresh()->balance, 'one debit, not two');
        $this->assertSame(
            $reservedBefore,
            $exposure->exposureFor($drawId, 'lane_game', '123', '3d_direct'),
            'the reservation of the first attempt stands; the retry reserved nothing',
        );

        $attribution = GameBetAttribution::query()->where('bet_id', $first['bet_id'])->firstOrFail();
        $this->assertSame('123', (string) $attribution->number);
        $this->assertSame(
            1,
            GameAuditEvent::query()->where('event', GameAuditRecorder::BET_ATTRIBUTION_RECOVERED)->count(),
            'the recovery is on the audit trail',
        );
    }

    public function test_an_ordinary_replay_still_returns_the_first_receipt_untouched(): void
    {
        $lane = $this->openLane();
        ['user' => $user, 'wallet' => $wallet] = $this->player('1000.00');
        $this->limitFor($lane['draw'], '3d_direct', '123');

        $service = app(GameBetPlacementService::class);
        $key = $this->key('plain-replay');
        $selection = new DigitBetSelection('lane_game', '3d_direct', '123', '10.00');
        $drawId = (int) $lane['draw']->getKey();

        $first = $service->place((int) $user->getKey(), $selection, $drawId, $key);
        $second = $service->place((int) $user->getKey(), $selection, $drawId, $key);

        $this->assertSame($first['bet_id'], $second['bet_id']);
        $this->assertSame('990.00', $wallet->fresh()->balance);
        $this->assertSame(
            0,
            GameAuditEvent::query()->where('event', GameAuditRecorder::BET_ATTRIBUTION_RECOVERED)->count(),
            'a replay with an intact attribution is not a recovery',
        );
    }
}
