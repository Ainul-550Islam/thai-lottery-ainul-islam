<?php

declare(strict_types=1);

namespace Tests\Feature\Affiliate;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\KycStatus;
use App\Enums\WalletType;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Account\MemberBankAccountService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesEligiblePlayer;
use Tests\TestCase;

final class ReferralPayoutRequestTest extends TestCase
{
    use MakesEligiblePlayer;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            RolePermissionSeeder::class,
            LedgerAccountSeeder::class,
        ]);
    }

    public function test_delegates_commission_payout_to_canonical_withdrawal_flow_with_idempotency_and_balance_check(): void
    {
        $member = User::factory()->create([
            'name' => 'Arthit Charoen',
            'kyc_status' => KycStatus::Verified,
        ]);
        $this->makeEligiblePlayer($member);

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $bankService = app(MemberBankAccountService::class);
        $bank = $bankService->add($member, 'SCB', 'Arthit Charoen', '1112223334', true);
        $bankService->verify($bank, $admin, 'Approved');

        $wallets = app(WalletService::class);
        $commissionWallet = $wallets->getOrCreateWallet($member, Currency::THB, WalletType::Commission);
        $wallets->credit(
            $commissionWallet,
            Money::of('500.00', Currency::THB),
            FinancialTransactionType::Commission,
            'seed-comm-wallet-0001',
        );

        Sanctum::actingAs($member);

        // 1. Insufficient commission wallet balance -> 422 insufficient_balance
        $this->postJson('/api/v1/me/referral-center/payout-requests', [
            'amount' => '800.00',
            'idempotency_key' => 'ref-payout-over-01',
            'member_bank_account_id' => $bank->getKey(),
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'insufficient_balance');

        // 2. Valid commission payout request -> creates canonical Withdrawal row + initial hold
        $first = $this->postJson('/api/v1/me/referral-center/payout-requests', [
            'amount' => '300.00',
            'idempotency_key' => 'ref-payout-valid-01',
            'member_bank_account_id' => $bank->getKey(),
        ]);

        $first->assertStatus(201)
            ->assertJsonPath('data.withdrawal.amount', '300.00')
            ->assertJsonPath('data.withdrawal.member_bank_account_id', $bank->getKey())
            ->assertJsonPath('data.replayed', false);

        // 3. Idempotent replay -> 200, replayed=true, still only 1 Withdrawal row
        $replay = $this->postJson('/api/v1/me/referral-center/payout-requests', [
            'amount' => '300.00',
            'idempotency_key' => 'ref-payout-valid-01',
            'member_bank_account_id' => $bank->getKey(),
        ]);

        $replay->assertOk()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.withdrawal.id', $first->json('data.withdrawal.id'));

        $this->assertSame(1, Withdrawal::query()->where('user_id', $member->getKey())->count());
        $this->assertSame('300.00', bcadd((string) $commissionWallet->fresh()?->locked_balance, '0.00', 2));
    }
}
