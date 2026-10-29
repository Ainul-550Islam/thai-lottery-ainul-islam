<?php

declare(strict_types=1);

namespace Tests\Feature\Betting;

use App\DTOs\Betting\BulkBetSelectionData;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Models\NumberLimit;
use App\Models\User;
use App\Services\Betting\BulkBetService;
use App\Services\Wallet\WalletService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\Api\V1\ApiPurchaseTestCase;

/**
 * Bulk slip placement through BulkBetService::placeBulkBet().
 *
 * Lives apart from the suites because purchases must own their transaction
 * (BetPurchaseTransactionService invariant). DatabaseTruncation, inherited from
 * ApiPurchaseTestCase, keeps them outside a wrapping RefreshDatabase transaction.
 */
final class BulkBetPlacementTest extends ApiPurchaseTestCase
{
    public function test_p0_06_financial_idempotency_protection(): void
    {
        $user = User::factory()->create();
        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);

        $draw = Draw::query()->create([
            'draw_number' => 'GLO-20261001-03',
            'scheduled_at' => Carbon::now()->addDay(),
            'status' => DrawStatus::OPEN,
            'currency' => Currency::THB,
        ]);

        // This draw is built raw rather than through Draw::factory()->open(), so it
        // has to carry its betting window itself — and the window is set by direct
        // assignment because betting_open_at / betting_close_at are not fillable on
        // Draw (the same idiom ApiPurchaseTestCase::openDraw() uses).
        // DrawRiskAdmission fails closed on a missing window: a draw whose
        // betting_open_at/betting_close_at was never written is a draw nobody can
        // prove is currently taking bets, and the bulk slip path reserves capacity
        // through the same engine as every other purchase, so it is refused.
        $draw->betting_open_at = Carbon::now()->subHour();
        $draw->betting_close_at = Carbon::now()->addHour();
        $draw->opened_at = Carbon::now()->subHour();
        $draw->save();

        $walletService->credit($wallet, '500.00', 'deposit', 'phase-test-'.bin2hex(random_bytes(8)), 'Seed');

        // Each bet number needs a risk limit row on this draw.
        NumberLimit::query()->create([
            'draw_id' => $draw->id,
            'bet_type' => '2d',
            'number' => '19',
            'max_amount' => '100000.00',
            'maximum_payout_exposure' => '10000000.00',
        ]);

        /** @var BulkBetService $bulkService */
        $bulkService = $this->app->make(BulkBetService::class);

        $items = [
            new BulkBetSelectionData(market: '2d_top', number: '19', stake: '100.00', potentialPayout: '9000.00'),
        ];

        $key = 'UNIQUE-SLIP-TOKEN-001';

        $r1 = $bulkService->placeBulkBet($user, $draw, $items, $key);
        $r2 = $bulkService->placeBulkBet($user, $draw, $items, $key);

        $this->assertCount(1, $r1->bets);
        $this->assertCount(1, $r2->bets);

        $wallet->refresh();
        $this->assertSame('400.00', (string) $wallet->balance); // Debited once only
    }

    public function test_04_bulk_bet_placement_with_idempotency_and_satang_math(): void
    {
        $user = User::factory()->create();
        /** @var WalletService $walletService */
        $walletService = $this->app->make(WalletService::class);
        $wallet = $walletService->getOrCreateWallet($user, Currency::THB);
        $walletService->credit($wallet, '1000.00', 'seed', 'phase-test-'.bin2hex(random_bytes(8)), 'Initial balance');

        $draw = Draw::query()->create([
            'draw_number' => 'GLO-20260930-02',
            'scheduled_at' => Carbon::now()->addDay(),
            'status' => DrawStatus::OPEN,
            'currency' => Currency::THB,
        ]);

        // This draw is built raw rather than through Draw::factory()->open(), so it
        // has to carry its betting window itself — and the window is set by direct
        // assignment because betting_open_at / betting_close_at are not fillable on
        // Draw (the same idiom ApiPurchaseTestCase::openDraw() uses).
        // DrawRiskAdmission fails closed on a missing window: a draw whose
        // betting_open_at/betting_close_at was never written is a draw nobody can
        // prove is currently taking bets, and the bulk slip path reserves capacity
        // through the same engine as every other purchase, so it is refused.
        $draw->betting_open_at = Carbon::now()->subHour();
        $draw->betting_close_at = Carbon::now()->addHour();
        $draw->opened_at = Carbon::now()->subHour();
        $draw->save();

        // Each bet number needs a risk limit row on this draw.
        NumberLimit::query()->create([
            'draw_id' => $draw->id,
            'bet_type' => '2d',
            'number' => '42',
            'max_amount' => '100000.00',
            'maximum_payout_exposure' => '10000000.00',
        ]);
        NumberLimit::query()->create([
            'draw_id' => $draw->id,
            'bet_type' => '3d',
            'number' => '742',
            'max_amount' => '100000.00',
            'maximum_payout_exposure' => '10000000.00',
        ]);

        /** @var BulkBetService $betService */
        $betService = $this->app->make(BulkBetService::class);

        $items = [
            new BulkBetSelectionData(
                market: '2d_top',
                number: '42',
                stake: '50.00',
                potentialPayout: '4500.00'
            ),
            new BulkBetSelectionData(
                market: '3d_direct',
                number: '742',
                stake: '100.00',
                potentialPayout: '90000.00'
            ),
        ];

        $clientKey = 'IDEM-BET-'.bin2hex(random_bytes(6));

        $result = $betService->placeBulkBet(
            user: $user,
            draw: $draw,
            selections: $items,
            clientKey: $clientKey
        );

        $this->assertNotEmpty($result->bets);
        $this->assertCount(2, $result->bets);
        $this->assertSame('150.00', (string) $result->totalStake);

        $wallet->refresh();
        $this->assertSame('850.00', (string) $wallet->balance);

        // Idempotency: replay the same request and ensure no duplicate debit
        $replay = $betService->placeBulkBet(
            user: $user,
            draw: $draw,
            selections: $items,
            clientKey: $clientKey
        );

        $this->assertCount(2, $replay->bets);
        $wallet->refresh();
        $this->assertSame('850.00', (string) $wallet->balance);
    }
}
