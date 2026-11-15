<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Enums\BankAccountStatus;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\KycStatus;
use App\Enums\PaymentMethod;
use App\Enums\WalletType;
use App\Models\MemberBankAccount;
use App\Models\User;
use App\Services\Account\MemberBankAccountService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalRequestWithHoldService;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\MakesEligiblePlayer;
use Tests\TestCase;

final class BankAccountTest extends TestCase
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

    public function test_encrypts_at_rest_masks_response_detects_duplicates_flags_kyc_mismatch_switches_default_enforces_ownership_max_five_and_blocks_pending_withdrawal_delete(): void
    {
        $member = User::factory()->create([
            'name' => 'Somchai Jaidee',
            'kyc_status' => KycStatus::Verified,
        ]);
        $this->makeEligiblePlayer($member);
        $stranger = User::factory()->create();

        Sanctum::actingAs($member);

        // 1. Add account #1 with mismatched holder name -> flagged name_mismatch=true, encrypted at rest, masked in response, default=true
        $rawNumber = '1234567890';
        $res = $this->postJson('/api/v1/me/bank-accounts', [
            'bank_code' => 'KBANK',
            'account_name' => 'Different Holder Name',
            'account_number' => $rawNumber,
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('data.bank_account.last4', '7890')
            ->assertJsonPath('data.bank_account.masked_number', '••••7890')
            ->assertJsonPath('data.bank_account.status', BankAccountStatus::PendingVerification->value)
            ->assertJsonPath('data.bank_account.name_mismatch', true)
            ->assertJsonPath('data.bank_account.is_default', true)
            ->assertJsonMissing(['account_number' => $rawNumber]);

        $this->assertStringNotContainsString($rawNumber, (string) $res->getContent());

        $accountId = (int) $res->json('data.bank_account.id');
        $rawCipher = (string) DB::table('member_bank_accounts')->where('id', $accountId)->value('account_number');
        $this->assertNotSame($rawNumber, $rawCipher);

        // 2. Duplicate account via blind index rejected
        $this->postJson('/api/v1/me/bank-accounts', [
            'bank_code' => 'KBANK',
            'account_name' => 'Somchai Jaidee',
            'account_number' => '1234-5678-90',
        ])->assertStatus(422)->assertJsonPath('error.code', 'bank_account_duplicate');

        // 3. Add accounts #2..#5 (up to max 5 allowed); 6th rejected
        $secondAccountId = 0;
        for ($i = 2; $i <= 5; $i++) {
            $created = $this->postJson('/api/v1/me/bank-accounts', [
                'bank_code' => 'SCB',
                'account_name' => 'Somchai Jaidee',
                'account_number' => sprintf('999900000%d', $i),
            ])->assertStatus(201);

            if ($i === 2) {
                $secondAccountId = (int) $created->json('data.bank_account.id');
            }
        }

        $this->postJson('/api/v1/me/bank-accounts', [
            'bank_code' => 'BBL',
            'account_name' => 'Somchai Jaidee',
            'account_number' => '8888777766',
        ])->assertStatus(422)->assertJsonPath('error.code', 'bank_account_limit_exceeded');

        // 4. Default switch: switching default to account #2 unsets account #1
        $this->patchJson('/api/v1/me/bank-accounts/'.$secondAccountId.'/default')
            ->assertOk()
            ->assertJsonPath('data.bank_account.id', $secondAccountId)
            ->assertJsonPath('data.bank_account.is_default', true);
        $this->assertFalse((bool) MemberBankAccount::query()->findOrFail($accountId)->is_default);
        $this->assertTrue((bool) MemberBankAccount::query()->findOrFail($secondAccountId)->is_default);

        // 5. Ownership isolation: another member cannot switch default or delete $member's bank account
        Sanctum::actingAs($stranger);
        $this->patchJson('/api/v1/me/bank-accounts/'.$accountId.'/default')->assertStatus(404);
        $this->deleteJson('/api/v1/me/bank-accounts/'.$accountId)->assertStatus(404);

        // 6. Verify account #1 as admin, create a pending withdrawal referencing it -> delete blocked!
        Sanctum::actingAs($member);
        $admin = User::factory()->create();
        $admin->assignRole('super-admin');
        $accountModel = MemberBankAccount::query()->findOrFail($accountId);
        app(MemberBankAccountService::class)->verify($accountModel, $admin, 'KYC verified');

        $wallets = app(WalletService::class);
        $wallet = $wallets->getOrCreateWallet($member, Currency::THB, WalletType::Primary);
        $wallets->credit($wallet, Money::of('1000.00', Currency::THB), FinancialTransactionType::Deposit, 'seed-bank-wd-00001');

        app(WithdrawalRequestWithHoldService::class)->request(
            wallet: $wallet,
            amount: Money::of('200.00', Currency::THB),
            method: PaymentMethod::Manual,
            idempotencyKey: 'wd-pending-bank-lock-0001',
            options: ['member_bank_account_id' => $accountId],
        );

        $this->deleteJson('/api/v1/me/bank-accounts/'.$accountId)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'bank_account_in_use_by_pending_withdrawal');
    }
}
