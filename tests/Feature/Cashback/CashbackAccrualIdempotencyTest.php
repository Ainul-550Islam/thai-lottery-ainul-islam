<?php

declare(strict_types=1);

namespace Tests\Feature\Cashback;

use App\Enums\BetStatus;
use App\Enums\CashbackAccrualStatus;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\RewardClaimStatus;
use App\Enums\RewardType;
use App\Enums\WalletType;
use App\Jobs\Cashback\AccrueCashbackJob;
use App\Models\Bet;
use App\Models\CashbackAccrual;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Cashback\CashbackAccrualService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Rewards\FreeBetVoucherService;
use Database\Seeders\CashbackPolicySeeder;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class CashbackAccrualIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            LedgerAccountSeeder::class,
            CashbackPolicySeeder::class,
        ]);
    }

    public function test_rerunning_same_period_creates_only_one_accrual_row(): void
    {
        Carbon::setTestNow('2026-11-13 02:00:00');
        $member = User::factory()->create();

        $periodStart = Carbon::parse('2026-11-12 00:00:00');
        $periodEnd = Carbon::parse('2026-11-12 23:59:59');

        Bet::factory()->lost()->create([
            'user_id' => $member->getKey(),
            'stake_amount' => '1000.00',
            'actual_payout' => '0.00',
            'updated_at' => Carbon::parse('2026-11-12 16:00:00'),
        ]);

        $service = app(CashbackAccrualService::class);
        $first = $service->accrueForMember($member, $periodStart, $periodEnd, 'daily');
        $second = $service->accrueForMember($member, $periodStart, $periodEnd, 'daily');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, CashbackAccrual::query()->where('member_id', $member->getKey())->count());
        $this->assertSame('5.00', (string) $first->accrual_amount);

        Carbon::setTestNow();
    }

    public function test_settled_net_loss_excludes_bonus_funded_and_free_bet_funded_stakes_and_applies_boost(): void
    {
        $member = User::factory()->create();
        $start = Carbon::parse('2026-11-12 00:00:00');
        $end = Carbon::parse('2026-11-12 23:59:59');
        Carbon::setTestNow('2026-11-12 14:00:00');

        // 1. Settled real-money lost bet: +400.00 stake, 0.00 payout
        Bet::factory()->lost()->create([
            'user_id' => $member->getKey(),
            'stake_amount' => '400.00',
            'actual_payout' => '0.00',
            'updated_at' => Carbon::parse('2026-11-12 12:00:00'),
        ]);

        // 2. Settled real-money won bet: +100.00 stake, 150.00 payout -> net loss = 350.00
        Bet::factory()->won('150.00')->create([
            'user_id' => $member->getKey(),
            'stake_amount' => '100.00',
            'won_at' => Carbon::parse('2026-11-12 13:00:00'),
        ]);

        // 3. Bonus-wallet-funded bet -> MUST BE EXCLUDED
        $wallets = app(WalletService::class);
        $bonusWallet = $wallets->getOrCreateWallet($member, Currency::THB, WalletType::Bonus);
        $wallets->credit($bonusWallet, Money::of('300.00', Currency::THB), FinancialTransactionType::Adjustment, 'seed-bonus-calc-test-0001');

        $bonusBet = Bet::factory()->lost()->create([
            'user_id' => $member->getKey(),
            'stake_amount' => '300.00',
            'updated_at' => Carbon::parse('2026-11-12 13:15:00'),
        ]);
        $wallets->debit($bonusWallet, Money::of('300.00', Currency::THB), FinancialTransactionType::BetDebit, 'debit-bonus-calc-test-0001', [
            'reference_type' => Bet::class,
            'reference_id' => (int) $bonusBet->getKey(),
        ]);

        // 4. Free-bet voucher-funded bet -> MUST BE EXCLUDED
        $freeBet = Bet::factory()->lost()->create([
            'user_id' => $member->getKey(),
            'stake_amount' => '200.00',
            'updated_at' => Carbon::parse('2026-11-12 13:30:00'),
        ]);
        $voucher = app(FreeBetVoucherService::class)->issue(
            $member,
            null,
            Money::of('200.00', Currency::THB),
            Carbon::parse('2026-11-20 00:00:00'),
        );
        app(FreeBetVoucherService::class)->consume($voucher, $freeBet, Carbon::parse('2026-11-12 13:30:00'));

        // Active boost (+75 bps)
        $boostItem = RewardCatalogItem::query()->create([
            'code' => 'calc_boost_test',
            'name' => 'Boost',
            'type' => RewardType::CashbackBoost,
            'points_cost' => 500,
            'payload' => ['extra_bps' => 75, 'duration_days' => 7],
            'active' => true,
        ]);
        RewardClaim::query()->create([
            'member_id' => $member->getKey(),
            'item_id' => $boostItem->getKey(),
            'points_spent' => 500,
            'status' => RewardClaimStatus::Completed,
            'idempotency_key' => 'boost-calc-1',
            'payload' => [
                'extra_bps' => 75,
                'duration_days' => 7,
                'boost_starts_at' => '2026-11-10T00:00:00+00:00',
                'boost_ends_at' => '2026-11-17T00:00:00+00:00',
            ],
            'completed_at' => Carbon::now(),
        ]);

        $service = app(CashbackAccrualService::class);
        $this->assertSame('350.00', $service->settledRealMoneyNetLoss($member, $start, $end));
        $this->assertSame(75, $service->activeBoostBpsForMember($member, $end));

        Carbon::setTestNow();
    }

    public function test_bet_cancellation_or_refund_reverses_unclaimed_accrual(): void
    {
        $member = User::factory()->create();
        $periodStart = Carbon::parse('2026-11-12 00:00:00');
        $periodEnd = Carbon::parse('2026-11-12 23:59:59');

        $bet = Bet::factory()->lost()->create([
            'user_id' => $member->getKey(),
            'stake_amount' => '2000.00',
            'placed_at' => Carbon::parse('2026-11-12 14:00:00'),
            'updated_at' => Carbon::parse('2026-11-12 16:00:00'),
        ]);

        $accrual = app(CashbackAccrualService::class)->accrueForMember($member, $periodStart, $periodEnd, 'daily');
        $this->assertNotNull($accrual);
        $this->assertSame(CashbackAccrualStatus::Accrued, $accrual->status);

        $bet->status = BetStatus::Refunded;
        $bet->save();

        $accrual->refresh();
        $this->assertSame(CashbackAccrualStatus::Reversed, $accrual->status);
        $this->assertNotNull($accrual->reversed_at);
    }

    public function test_command_dry_run_and_chunked_resumable_job_accrual(): void
    {
        $m1 = User::factory()->create();
        $m2 = User::factory()->create();

        Bet::factory()->lost()->create([
            'user_id' => $m1->getKey(),
            'stake_amount' => '1000.00',
            'updated_at' => Carbon::parse('2026-11-12 15:00:00'),
        ]);
        Bet::factory()->lost()->create([
            'user_id' => $m2->getKey(),
            'stake_amount' => '2000.00',
            'updated_at' => Carbon::parse('2026-11-12 16:00:00'),
        ]);

        // 1. Dry-run previews without persisting rows
        $this->artisan('cashback:accrue', [
            '--period' => 'daily',
            '--date' => '2026-11-12',
            '--dry-run' => true,
        ])->assertExitCode(0);
        $this->assertSame(0, CashbackAccrual::query()->count());

        // 2. Chunk size = 1 exercises resumable cursor continuation across both members
        $job = new AccrueCashbackJob(
            period: 'daily',
            periodStartIso: '2026-11-12T00:00:00+00:00',
            periodEndIso: '2026-11-12T23:59:59+00:00',
            afterMemberId: 0,
            chunkSize: 1,
        );
        $job->handle(app(CashbackAccrualService::class));

        $this->assertSame(2, CashbackAccrual::query()->count());
    }
}
