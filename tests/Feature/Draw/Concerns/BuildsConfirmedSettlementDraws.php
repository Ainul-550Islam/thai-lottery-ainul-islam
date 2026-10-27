<?php

declare(strict_types=1);

namespace Tests\Feature\Draw\Concerns;

use App\DTOs\BetPurchaseData;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\LimitStatus;
use App\Models\Draw;
use App\Models\NumberLimit;
use App\Models\User;
use App\Services\Betting\BetPurchaseService;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Draw\DrawResultConfirmationService;
use App\Services\Draw\DrawResultIngestionService;

/** Test-only fixture: real ingestion and distinct persisted maker/checker. */
trait BuildsConfirmedSettlementDraws
{
    protected function configureSafety(string $mode = 'LIVE', string $autoApproveBelow = '1000000.00'): void
    {
        config([
            'finance.prize_payout.safety_mode' => $mode,
            'finance.prize_payout.auto_approve_below' => $autoApproveBelow,
            'finance.prize_payout.require_approval_at_or_above' => $autoApproveBelow,
        ]);
    }

    /**
     * A published draw with one winning 3d_direct bet on '123' plus the given
     * number of losing bets, so a chunk boundary can fall between winners and
     * losers rather than always on a winner.
     *
     * @param  list<string>  $losingNumbers
     * @return array{draw: Draw, wallet: Wallet, user: User}
     */
    protected function publishedDrawWithBets(array $losingNumbers, string $suffix): array
    {
        $player = $this->createPlayer('500.00', Currency::THB);

        $draw = Draw::factory()->create(['type' => DrawType::ThreeD]);
        $draw->status = DrawStatus::Open;
        $draw->betting_open_at = now()->subHour();
        $draw->betting_close_at = now()->addHour();
        $draw->opened_at = now()->subHour();
        $draw->save();

        $numbers = array_merge(['123'], $losingNumbers);

        foreach ($numbers as $index => $number) {
            $limit = new NumberLimit;
            $limit->draw_id = $draw->getKey();
            $limit->bet_type = BetType::ThreeD;
            $limit->number = $number;
            $limit->max_amount = '100000.00';
            $limit->current_amount = '0.00';
            $limit->status = LimitStatus::Active;
            $limit->save();

            app(BetPurchaseService::class)->purchase(new BetPurchaseData(
                userId: (int) $player['user']->getKey(),
                drawId: (int) $draw->getKey(),
                marketKey: '3d_direct',
                rawNumber: $number,
                rawStake: '10.00',
                idempotencyKey: sprintf('chunked-%s-%d', $suffix, $index),
            ));
        }

        $lifecycle = app(DrawLifecycleService::class);
        $draw = $lifecycle->close($draw);
        $draw = $lifecycle->markResultPending($draw);

        $card = ['first_prize' => '456123', 'bottom_two' => '45'];
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        app(DrawResultIngestionService::class)->ingest(
            (int) $draw->getKey(), $card, 'internal', (int) $maker->id,
        );
        app(DrawResultConfirmationService::class)->confirm(
            (int) $draw->getKey(), (int) $checker->id, $card,
        );

        return [
            'draw' => $draw->refresh(),
            'wallet' => $player['wallet'],
            'user' => $player['user'],
        ];
    }
}
