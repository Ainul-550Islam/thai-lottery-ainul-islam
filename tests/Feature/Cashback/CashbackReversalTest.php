<?php

declare(strict_types=1);

namespace Tests\Feature\Cashback;

use App\Enums\BetStatus;
use App\Enums\CashbackAccrualStatus;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\WalletType;
use App\Models\Bet;
use App\Models\CashbackAccrual;
use App\Models\Draw;
use App\Models\User;
use App\Services\Cashback\CashbackAccrualService;
use App\Services\Cashback\CashbackClaimService;
use App\Services\Finance\WalletService;
use Database\Seeders\CashbackPolicySeeder;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class CashbackReversalTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $periodStart;

    private Carbon $periodEnd;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 02:00:00');
        $this->periodStart = Carbon::parse('2026-11-12 00:00:00');
        $this->periodEnd = Carbon::parse('2026-11-12 23:59:59');
        $this->seed([LedgerAccountSeeder::class, CashbackPolicySeeder::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * A lost bet whose draw was settled (Completed) at $settledAt.
     *
     * The accrual period of a bet is decided by its draw's completed_at, so the
     * settlement instant belongs on the draw, not on the bet's updated_at.
     */
    private function lostBet(User $member, string $stake, string $settledAt): Bet
    {
        $draw = Draw::factory()->completed($settledAt)->create();

        return Bet::factory()->lost()->create([
            'user_id' => $member->getKey(),
            'draw_id' => $draw->getKey(),
            'stake_amount' => $stake,
            'actual_payout' => '0.00',
            'placed_at' => Carbon::parse('2026-11-12 14:00:00'),
        ]);
    }

    private function expected(string $basis, CashbackAccrual $accrual): string
    {
        $rate = (int) $accrual->metadata['effective_rate_bps'];

        return bcdiv(bcmul($basis, (string) $rate, 4), '10000', 2);
    }

    public function test_cancelling_one_bet_reprices_the_accrual_instead_of_voiding_it(): void
    {
        $member = User::factory()->create();
        $this->lostBet($member, '2000.00', '2026-11-12 15:00:00');
        $cancelled = $this->lostBet($member, '1000.00', '2026-11-12 16:00:00');

        $accrual = app(CashbackAccrualService::class)->accrueForMember($member, $this->periodStart, $this->periodEnd, 'daily');
        $this->assertNotNull($accrual);
        $this->assertSame('3000.00', (string) $accrual->basis_amount);

        $cancelled->status = BetStatus::Cancelled;
        $cancelled->save();

        $accrual->refresh();
        $this->assertSame(CashbackAccrualStatus::Accrued, $accrual->status);
        $this->assertSame('2000.00', (string) $accrual->basis_amount);
        $this->assertSame($this->expected('2000.00', $accrual), (string) $accrual->accrual_amount);
        $this->assertNull($accrual->reversed_at);
    }

    public function test_reversal_finds_the_accrual_of_the_draw_period_even_when_the_bet_was_placed_earlier(): void
    {
        $member = User::factory()->create();
        $this->lostBet($member, '2000.00', '2026-11-12 15:00:00');

        // Placed the evening before, but its draw completed inside the period:
        // the accrual counted it, so the reversal has to look in that period too.
        $draw = Draw::factory()->completed('2026-11-12 16:00:00')->create();
        $early = Bet::factory()->lost()->create([
            'user_id' => $member->getKey(),
            'draw_id' => $draw->getKey(),
            'stake_amount' => '1000.00',
            'actual_payout' => '0.00',
            'placed_at' => Carbon::parse('2026-11-11 22:00:00'),
        ]);

        $accrual = app(CashbackAccrualService::class)->accrueForMember($member, $this->periodStart, $this->periodEnd, 'daily');
        $this->assertNotNull($accrual);
        $this->assertSame('3000.00', (string) $accrual->basis_amount);

        $early->status = BetStatus::Cancelled;
        $early->save();

        $accrual->refresh();
        $this->assertSame(CashbackAccrualStatus::Accrued, $accrual->status);
        $this->assertSame('2000.00', (string) $accrual->basis_amount);
    }

    public function test_cancelling_a_bet_after_claim_claws_back_only_the_over_paid_difference(): void
    {
        $member = User::factory()->create();
        $this->lostBet($member, '20000.00', '2026-11-12 15:00:00');
        $cancelled = $this->lostBet($member, '10000.00', '2026-11-12 16:00:00');

        $accrual = app(CashbackAccrualService::class)->accrueForMember($member, $this->periodStart, $this->periodEnd, 'daily');
        $this->assertNotNull($accrual);
        $paid = $this->expected('30000.00', $accrual);

        app(CashbackClaimService::class)->claim($member, 'reversal-claim-key-0001');

        $wallet = app(WalletService::class)->getOrCreateWallet($member, Currency::THB, WalletType::Primary);
        $this->assertSame($paid, bcadd((string) $wallet->fresh()?->balance, '0.00', 2));

        $cancelled->status = BetStatus::Refunded;
        $cancelled->save();

        $entitled = $this->expected('20000.00', $accrual);
        $overPaid = bcsub($paid, $entitled, 2);

        $accrual->refresh();
        $this->assertSame(CashbackAccrualStatus::Claimed, $accrual->status);
        $this->assertSame($overPaid, (string) $accrual->clawback_amount);
        $this->assertSame('0.00', (string) $accrual->clawback_shortfall);
        $this->assertNotNull($accrual->clawback_transaction_id);
        $this->assertSame($entitled, bcadd((string) $wallet->fresh()?->balance, '0.00', 2));

        // Re-running the reversal for the same bet never claws back twice.
        $this->assertSame(0, app(CashbackAccrualService::class)->reverse($cancelled->fresh()));
        $this->assertSame($entitled, bcadd((string) $wallet->fresh()?->balance, '0.00', 2));
    }

    public function test_clawback_is_capped_at_available_balance_and_records_the_shortfall(): void
    {
        $member = User::factory()->create();
        $this->lostBet($member, '20000.00', '2026-11-12 15:00:00');
        $cancelled = $this->lostBet($member, '10000.00', '2026-11-12 16:00:00');

        $accrual = app(CashbackAccrualService::class)->accrueForMember($member, $this->periodStart, $this->periodEnd, 'daily');
        $this->assertNotNull($accrual);
        $paid = $this->expected('30000.00', $accrual);
        $overPaid = bcsub($paid, $this->expected('20000.00', $accrual), 2);

        app(CashbackClaimService::class)->claim($member, 'reversal-claim-key-0002');

        // The member spends almost everything before the bet is cancelled.
        $wallets = app(WalletService::class);
        $wallet = $wallets->getOrCreateWallet($member, Currency::THB, WalletType::Primary);
        $remaining = '1.00';
        $wallets->debit(
            $wallet->fresh(),
            bcsub($paid, $remaining, 2),
            FinancialTransactionType::Adjustment,
            'test-spend-down-0001',
            ['description' => 'Test spend-down'],
        );

        $cancelled->status = BetStatus::Cancelled;
        $cancelled->save();

        $accrual->refresh();
        $this->assertSame($remaining, (string) $accrual->clawback_amount);
        $this->assertSame(bcsub($overPaid, $remaining, 2), (string) $accrual->clawback_shortfall);
        $this->assertSame('0.00', bcadd((string) $wallet->fresh()?->balance, '0.00', 2));
    }
}