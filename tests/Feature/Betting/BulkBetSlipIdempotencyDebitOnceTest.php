<?php

declare(strict_types=1);

namespace Tests\Feature\Betting;

use App\DTOs\Betting\BulkBetSelectionData;

use App\Enums\BetType;

use App\Enums\Currency;

use App\Enums\DrawStatus;

use App\Enums\LimitStatus;

use App\Models\Bet;

use App\Models\Draw;

use App\Models\NumberLimit;

use App\Services\Betting\BulkBetService;

use Carbon\Carbon;

use Tests\Feature\Payment\PaymentTestCase;

/**

 * A bet purchase must own its own database transaction, so this cannot live in a

 * RefreshDatabase class (which wraps every test in one). Money-critical: the same

 * slip key submitted twice must debit the wallet exactly once.

 */

final class BulkBetSlipIdempotencyDebitOnceTest extends PaymentTestCase

{

    public function test_same_slip_key_debits_the_wallet_exactly_once(): void

    {

        $player = $this->createPlayer('500.00', Currency::THB);

        $user = $player['user'];

        $wallet = $player['wallet'];

        $draw = Draw::query()->create([

            'draw_number' => 'GLO-20261001-03',

            'scheduled_at' => Carbon::now()->addDay(),

            'status' => DrawStatus::OPEN,

            'currency' => Currency::THB,

        ]);

        $draw->betting_open_at = now()->subHour();

        $draw->betting_close_at = now()->addHour();

        $draw->opened_at = now()->subHour();

        $draw->save();

        // Capacity is reserved against a number_limits row; a purchase with no row is

        // refused by design, so the fixture creates one.

        $definition = (array) config('lottery.markets.2d_top');

        $limit = new NumberLimit;

        $limit->draw_id = $draw->getKey();

        $limit->bet_type = BetType::from((string) $definition['bet_type']);

        $limit->number = '19';

        $limit->max_amount = '100000.00';

        $limit->current_amount = '0.00';

        $limit->status = LimitStatus::Active;

        $limit->save();

        $items = [

            new BulkBetSelectionData(market: '2d_top', number: '19', stake: '100.00', potentialPayout: '9000.00'),

        ];

        $key = 'UNIQUE-SLIP-TOKEN-001';

        $bulk = $this->app->make(BulkBetService::class);

        $r1 = $bulk->placeBulkBet($user, $draw, $items, $key);

        $r2 = $bulk->placeBulkBet($user, $draw, $items, $key);

        $this->assertCount(1, $r1->bets, json_encode($r1->items));

        $this->assertCount(1, $r2->bets, json_encode($r2->items));

        $this->assertSame('purchased', $r1->items[0]['outcome']);

        $this->assertSame('replayed', $r2->items[0]['outcome']);

        $this->assertSame(1, Bet::query()->count());

        $this->assertSame('400.00', (string) $wallet->refresh()->balance); // debited once only

    }

}

