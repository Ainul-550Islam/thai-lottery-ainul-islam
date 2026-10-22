<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\DTOs\Betting\BulkBetSelectionData;
use App\Enums\Currency;
use App\Enums\GloClaimStatus;
use App\Exceptions\GloClaimException;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Betting\BulkBetService;
use App\Services\Finance\WalletReservationService;
use App\Services\Finance\WalletService;
use App\Services\Lottery\GloPrizeClaimService;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;

/** Local contract regressions, not production/merchant/concurrency certification. */
final class FinancialMasterBoundaryTest extends FinancialMasterTestCase
{
    public function test_bulk_selection_preserves_zeroes_and_discards_client_payout_authority(): void
    {
        $selection = new BulkBetSelectionData(
            number: '007', stake: '50.00', market: ' 3D_DIRECT ', potentialPayout: '999999999.00',
        );
        $this->assertSame('3d_direct', $selection->marketKey);
        $this->assertSame('007', $selection->number);
        $payload = $selection->toPurchasePayload(1, 'boundary-purchase');
        $this->assertArrayNotHasKey('potential_payout', $payload);
        $this->assertArrayNotHasKey('multiplier', $payload);

        $user = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::THB);
        $this->creditFixture($wallet, '500.00', 'Boundary stake fixture');
        $draw = $this->openBulkDrawForTest([$selection]);
        $result = app(BulkBetService::class)->purchase($user->id, $draw->id, [$selection], 'boundary-server-pricing');
        $this->assertSame(1, $result['purchased']);
        $this->assertSame('50.00', $result['total_charged']);
        $bet = Bet::query()->sole();
        $item = $bet->items()->sole();
        $this->assertSame('007', (string) $item->number);
        $this->assertSame((int) config('lottery.markets.3d_direct.payout_multiplier'), $item->payout_multiplier);
        $this->assertSame(bcmul('50.00', (string) $item->payout_multiplier, 2), (string) $item->potential_payout);
        $this->assertNotSame('999999999.00', (string) $bet->potential_payout);
        $this->assertSame('450.00', (string) $wallet->fresh()->balance);
    }

    public static function refusedMarkets(): array
    {
        return [
            'undeclared legacy label' => ['top_3'],
            'ambiguous family' => ['3d'],
            'nonexistent 3D bottom' => ['3d_bottom'],
            'unknown market' => ['not_a_market'],
        ];
    }

    #[DataProvider('refusedMarkets')]
    public function test_unknown_market_is_not_guessed_or_charged(string $market): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::THB);
        $this->creditFixture($wallet, '500.00', 'Unknown-market refusal fixture');
        $draw = $this->openBulkDrawForTest([]);
        $selection = new BulkBetSelectionData(marketKey: $market, number: '007', stake: '50.00');
        $before = FinancialTransaction::query()->count();
        $bulk = app(BulkBetService::class);
        $quote = $bulk->quote($draw->id, [$selection]);
        $this->assertCount(1, $quote->refusals);
        $result = $bulk->purchase($user->id, $draw->id, [$selection], 'boundary-refuse-'.$market);
        $this->assertSame(0, $result['purchased']);
        $this->assertSame(1, $result['refused']);
        $this->assertSame('0.00', $result['total_charged']);
        $this->assertSame('500.00', (string) $wallet->fresh()->balance);
        $this->assertSame($before, FinancialTransaction::query()->count());
        $this->assertSame(0, Bet::query()->count());
    }

    public function test_mixed_bulk_cart_replays_only_the_accepted_item_without_another_debit(): void
    {
        $user = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::THB);
        $this->creditFixture($wallet, '500.00', 'Mixed-cart fixture');
        $good = new BulkBetSelectionData(marketKey: '3d_direct', number: '007', stake: '50.00');
        $bad = new BulkBetSelectionData(marketKey: 'not_a_market', number: '007', stake: '50.00');
        $draw = $this->openBulkDrawForTest([$good]);
        $bulk = app(BulkBetService::class);
        $first = $bulk->purchase($user->id, $draw->id, [$good, $bad], 'boundary-mixed-cart');
        $this->assertSame(1, $first['purchased']);
        $this->assertSame(1, $first['refused']);
        $this->assertSame('50.00', $first['total_charged']);
        $transactions = FinancialTransaction::query()->count();
        $replay = $bulk->purchase($user->id, $draw->id, [$good, $bad], 'boundary-mixed-cart');
        $this->assertSame(1, $replay['replayed']);
        $this->assertSame(1, $replay['refused']);
        $this->assertSame('0.00', $replay['total_charged']);
        $this->assertSame(1, Bet::query()->count());
        $this->assertSame($transactions, FinancialTransaction::query()->count());
        $this->assertSame('450.00', (string) $wallet->fresh()->balance);
    }

    public function test_usd_reservation_consumption_and_replay_do_not_touch_thb_wallet(): void
    {
        $user = User::factory()->create();
        $wallets = app(WalletService::class);
        $thb = $wallets->getOrCreateWallet($user, Currency::THB);
        $usd = $wallets->getOrCreateWallet($user, Currency::USD);
        $this->creditFixture($thb, '1000.00', 'THB isolation fixture');
        $this->creditFixture($usd, '50.00', 'USD isolation fixture');
        $reservations = app(WalletReservationService::class);
        $reservation = $reservations->reserve($user->id, '10.00', 'USD', 'USD test hold');
        $this->assertSame('10.00', (string) $usd->fresh()->locked_balance);
        $this->assertSame('0.00', (string) $thb->fresh()->locked_balance);
        $this->assertTrue($reservations->consume($reservation->id, 'Test USD consumption'));
        $count = FinancialTransaction::query()->count();
        $this->assertTrue($reservations->consume($reservation->id, 'Test USD consumption'));
        $this->assertSame($count, FinancialTransaction::query()->count());
        $this->assertSame('40.00', (string) $usd->fresh()->balance);
        $this->assertSame('0.00', (string) $usd->fresh()->locked_balance);
        $this->assertSame('1000.00', (string) $thb->fresh()->balance);
        $this->assertSame('0.00', (string) $thb->fresh()->locked_balance);
    }

    public function test_claim_approval_rechecks_tampered_net_after_eligible_review(): void
    {
        $f = $this->verifiedClaimFixtureForMaster();
        $service = app(GloPrizeClaimService::class);
        $claim = $service->reviewEligible($f['claim'], $f['approver']);
        $claim->forceFill(['net_prize' => '1.00'])->save();
        try {
            $service->approve($claim, $f['approver']);
            $this->fail('A tampered net prize must not be approved.');
        } catch (GloClaimException $error) {
            $this->assertSame(422, $error->getCode());
            $this->assertSame(GloClaimException::stampDutyMissing()->getMessage(), $error->getMessage());
        }
        $this->assertSame(GloClaimStatus::Eligible, $claim->fresh()->status);
        $this->assertNull($claim->fresh()->approved_at);
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    public function test_claimant_without_operator_permission_cannot_approve_through_http(): void
    {
        $f = $this->verifiedClaimFixtureForMaster();
        Sanctum::actingAs($f['claimant']);
        $this->postJson('/api/v1/glo/claims/'.$f['claim']->claim_reference.'/approve')->assertForbidden();
        $this->assertSame(GloClaimStatus::Pending, $f['claim']->fresh()->status);
        $this->assertNull($f['claim']->fresh()->approved_at);
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    public function test_operator_can_review_and_approve_but_approval_does_not_grant_payment_permission(): void
    {
        $f = $this->verifiedClaimFixtureForMaster();
        Sanctum::actingAs($f['approver']);
        $url = '/api/v1/glo/claims/'.$f['claim']->claim_reference;
        $this->postJson($url.'/review')->assertOk();
        $this->assertSame(GloClaimStatus::Eligible, $f['claim']->fresh()->status);
        $this->postJson($url.'/approve')->assertOk();
        $this->assertSame(GloClaimStatus::Approved, $f['claim']->fresh()->status);
        $this->postJson($url.'/pay')->assertForbidden();
        $this->assertSame(GloClaimStatus::Approved, $f['claim']->fresh()->status);
        $this->assertNull($f['claim']->fresh()->paid_at);
        $this->assertSame(0, FinancialTransaction::query()->count());
    }
}
