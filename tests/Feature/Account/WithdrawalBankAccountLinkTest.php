<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\KycStatus;
use App\Enums\PaymentMethod;
use App\Enums\WalletType;
use App\Models\User;
use App\Services\Account\MemberBankAccountService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesEligiblePlayer;
use Tests\TestCase;

final class WithdrawalBankAccountLinkTest extends TestCase
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

    public function test_enforces_verified_and_owned_bank_account_on_withdrawal(): void
    {
        $member = User::factory()->create([
            'name' => 'Niran Srisai',
            'kyc_status' => KycStatus::Verified,
        ]);
        $this->makeEligiblePlayer($member);

        $otherMember = User::factory()->create([
            'name' => 'Kanya Wattana',
            'kyc_status' => KycStatus::Verified,
        ]);
        $this->makeEligiblePlayer($otherMember);

        $admin = User::factory()->create();
        $admin->assignRole('super-admin');

        $wallets = app(WalletService::class);
        $wallet = $wallets->getOrCreateWallet($member, Currency::THB, WalletType::Primary);
        $wallets->credit($wallet, Money::of('2000.00', Currency::THB), FinancialTransactionType::Deposit, 'seed-wd-link-00001');

        $bankService = app(MemberBankAccountService::class);
        $ownAccount = $bankService->add($member, 'KBANK', 'Niran Srisai', '5556667778', true);
        $foreignAccount = $bankService->add($otherMember, 'SCB', 'Kanya Wattana', '4443332221', true);
        $bankService->verify($foreignAccount, $admin, 'Verified foreign account');

        Sanctum::actingAs($member);

        // 1. Unverified own bank account -> rejected
        $this->postJson('/api/v1/withdrawals', [
            'amount' => '300.00',
            'method' => PaymentMethod::Manual->value,
            'member_bank_account_id' => $ownAccount->getKey(),
            'idempotency_key' => 'wd-unverified-bank-0001',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'withdrawal_refused');

        // 2. Verified bank account owned by ANOTHER member -> rejected
        $this->postJson('/api/v1/withdrawals', [
            'amount' => '300.00',
            'method' => PaymentMethod::Manual->value,
            'member_bank_account_id' => $foreignAccount->getKey(),
            'idempotency_key' => 'wd-foreign-bank-000002',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'withdrawal_refused');

        // 3. Admin verifies own bank account -> withdrawal accepted and linked via member_bank_account_id
        $bankService->verify($ownAccount, $admin, 'Verified against statement');

        $accepted = $this->postJson('/api/v1/withdrawals', [
            'amount' => '300.00',
            'method' => PaymentMethod::Manual->value,
            'member_bank_account_id' => $ownAccount->getKey(),
            'idempotency_key' => 'wd-verified-bank-00004',
        ]);

        $accepted->assertStatus(201)
            ->assertJsonPath('data.withdrawal.member_bank_account_id', $ownAccount->getKey());
    }

    public function test_null_member_bank_account_id_remains_backward_compatible(): void
    {
        $member = User::factory()->create([
            'name' => 'Somchai Prasert',
            'kyc_status' => KycStatus::Verified,
        ]);
        $this->makeEligiblePlayer($member);

        $wallets = app(WalletService::class);
        $wallet = $wallets->getOrCreateWallet($member, Currency::THB, WalletType::Primary);
        $wallets->credit($wallet, Money::of('1000.00', Currency::THB), FinancialTransactionType::Deposit, 'seed-wd-null-compat-01');

        Sanctum::actingAs($member);

        $legacy = $this->postJson('/api/v1/withdrawals', [
            'amount' => '250.00',
            'method' => PaymentMethod::Manual->value,
            'payout_details' => ['bank_name' => 'KBANK', 'account_last4' => '7778'],
            'idempotency_key' => 'wd-null-compat-bank-003',
        ]);

        $legacy->assertStatus(201)
            ->assertJsonPath('data.withdrawal.member_bank_account_id', null);
    }
}
