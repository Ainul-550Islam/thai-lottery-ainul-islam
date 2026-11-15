<?php

declare(strict_types=1);

namespace Tests\Feature\Cashback;

use App\Enums\CashbackAccrualStatus;
use App\Enums\Currency;
use App\Enums\WalletType;
use App\Models\CashbackAccrual;
use App\Models\CashbackPolicy;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Finance\WalletService;
use Database\Seeders\CashbackPolicySeeder;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class CashbackClaimTest extends TestCase
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

    public function test_claims_unexpired_accruals_into_main_wallet_with_balanced_ledger_and_excludes_expired(): void
    {
        $member = User::factory()->create();
        /** @var CashbackPolicy $policy */
        $policy = CashbackPolicy::query()->where('grade', 'bronze')->firstOrFail();

        $claimable = CashbackAccrual::query()->create([
            'member_id' => $member->getKey(),
            'policy_id' => $policy->getKey(),
            'period_start' => Carbon::now()->subDays(2)->startOfDay(),
            'period_end' => Carbon::now()->subDays(2)->endOfDay(),
            'basis_amount' => '3000.00',
            'accrual_amount' => '15.00',
            'status' => CashbackAccrualStatus::Accrued,
            'expires_at' => Carbon::now()->addDays(10),
        ]);

        $expired = CashbackAccrual::query()->create([
            'member_id' => $member->getKey(),
            'policy_id' => $policy->getKey(),
            'period_start' => Carbon::now()->subDays(40)->startOfDay(),
            'period_end' => Carbon::now()->subDays(40)->endOfDay(),
            'basis_amount' => '4000.00',
            'accrual_amount' => '20.00',
            'status' => CashbackAccrualStatus::Accrued,
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        Sanctum::actingAs($member);

        $response = $this->postJson('/api/v1/me/cashback/claim', [
            'idempotency_key' => 'cb-claim-key-0001',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.claimed_amount', '15.00')
            ->assertJsonPath('data.claimed_count', 1)
            ->assertJsonPath('data.wallet_type', 'main')
            ->assertJsonPath('data.replayed', false);

        $mainWallet = app(WalletService::class)->getOrCreateWallet($member, Currency::THB, WalletType::Primary);
        $bonusWallet = app(WalletService::class)->getOrCreateWallet($member, Currency::THB, WalletType::Bonus);

        $this->assertSame('15.00', bcadd((string) $mainWallet->fresh()?->balance, '0.00', 2));
        $this->assertSame('0.00', bcadd((string) $bonusWallet->fresh()?->balance, '0.00', 2));
        $this->assertSame(CashbackAccrualStatus::Claimed, $claimable->fresh()?->status);
        $this->assertSame(CashbackAccrualStatus::Accrued, $expired->fresh()?->status);

        $txId = (int) $response->json('data.transaction_id');
        $this->assertSame(2, LedgerEntry::query()->where('financial_transaction_id', $txId)->count());
    }

    public function test_rejects_claim_below_policy_min_claim_threshold(): void
    {
        $member = User::factory()->create();
        /** @var CashbackPolicy $policy */
        $policy = CashbackPolicy::query()->where('grade', 'bronze')->firstOrFail();

        CashbackAccrual::query()->create([
            'member_id' => $member->getKey(),
            'policy_id' => $policy->getKey(),
            'period_start' => Carbon::now()->subDay()->startOfDay(),
            'period_end' => Carbon::now()->subDay()->endOfDay(),
            'basis_amount' => '1000.00',
            'accrual_amount' => '5.00',
            'status' => CashbackAccrualStatus::Accrued,
            'expires_at' => Carbon::now()->addDays(10),
        ]);

        Sanctum::actingAs($member);

        $this->postJson('/api/v1/me/cashback/claim', [
            'idempotency_key' => 'cb-below-min-0001',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'cashback_below_minimum_claim');
    }

    public function test_respects_real_money_gate_when_closed(): void
    {
        config(['real_money.enabled' => false]);

        $member = User::factory()->create();
        /** @var CashbackPolicy $policy */
        $policy = CashbackPolicy::query()->where('grade', 'bronze')->firstOrFail();

        $accrual = CashbackAccrual::query()->create([
            'member_id' => $member->getKey(),
            'policy_id' => $policy->getKey(),
            'period_start' => Carbon::now()->subDay()->startOfDay(),
            'period_end' => Carbon::now()->subDay()->endOfDay(),
            'basis_amount' => '4000.00',
            'accrual_amount' => '20.00',
            'status' => CashbackAccrualStatus::Accrued,
            'expires_at' => Carbon::now()->addDays(10),
        ]);

        Sanctum::actingAs($member);

        $this->postJson('/api/v1/me/cashback/claim', [
            'idempotency_key' => 'cb-rm-closed-0001',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'real_money_disabled');

        $mainWallet = app(WalletService::class)->getOrCreateWallet($member, Currency::THB, WalletType::Primary);
        $this->assertSame('0.00', bcadd((string) $mainWallet->fresh()?->balance, '0.00', 2));
        $this->assertSame(CashbackAccrualStatus::Accrued, $accrual->fresh()?->status);
    }
}
