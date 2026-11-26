<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Enums\BankAccountStatus;
use App\Exceptions\FinancialException;
use App\Models\MemberBankAccount;
use App\Models\User;
use App\Services\Account\MemberBankAccountService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BankAccountGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([LedgerAccountSeeder::class]);
    }

    public function test_cross_member_duplicate_is_refused_with_a_neutral_message(): void
    {
        $service = app(MemberBankAccountService::class);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $service->add($owner, 'SCB', 'Owner Name', '1112223334');

        try {
            $service->add($other, 'SCB', 'Other Name', '1112223334');
            $this->fail('Duplicate must be refused.');
        } catch (FinancialException $e) {
            $this->assertSame('bank_account_duplicate', $e->errorCode());
            $this->assertStringNotContainsStringIgnoringCase('already registered', $e->getMessage());
        }
    }

    public function test_database_unique_key_blocks_a_racing_duplicate_and_removal_frees_it(): void
    {
        $service = app(MemberBankAccountService::class);
        $owner = User::factory()->create();
        $account = $service->add($owner, 'SCB', 'Owner Name', '5556667778');

        // Simulates the losing side of a race that slipped past the application check.
        $this->expectException(QueryException::class);
        try {
            MemberBankAccount::query()->create([
                'member_id' => User::factory()->create()->getKey(),
                'bank_code' => 'SCB',
                'account_name' => 'Racer',
                'account_number' => '5556667778',
                'account_number_hash' => $account->account_number_hash,
                'active_hash_key' => $account->account_number_hash,
                'last4' => '7778',
                'status' => BankAccountStatus::PendingVerification,
            ]);
        } finally {
            // Removal releases the unique key so the owner can register the account again.
            $service->remove($owner, $account);
            $again = $service->add($owner, 'SCB', 'Owner Name', '5556667778');
            $this->assertNotSame($account->getKey(), $again->getKey());
        }
    }

    public function test_verify_only_from_pending_and_mismatch_needs_an_override_note(): void
    {
        $service = app(MemberBankAccountService::class);
        $admin = User::factory()->create();
        $member = User::factory()->create();

        $account = $service->add($member, 'SCB', 'Holder Name', '9998887776');
        $account->forceFill(['name_mismatch' => true])->save();

        try {
            $service->verify($account, $admin, 'ok');
            $this->fail('A mismatch needs an explanatory note.');
        } catch (FinancialException $e) {
            $this->assertSame('bank_account_mismatch_override_required', $e->errorCode());
        }

        $verified = $service->verify($account, $admin, 'Checked against bank statement');
        $this->assertSame(BankAccountStatus::Verified, $verified->status);

        // Idempotent for an already verified account; never re-opens a rejected one.
        $this->assertSame(BankAccountStatus::Verified, $service->verify($verified, $admin)->status);

        $second = $service->add($member, 'KBANK', 'Holder Name', '1010101010');
        $service->reject($second, $admin, 'Document unreadable');
        try {
            $service->verify($second, $admin, 'Trying to override a rejection');
            $this->fail('A rejected account cannot be verified directly.');
        } catch (FinancialException $e) {
            $this->assertSame('bank_account_not_verifiable', $e->errorCode());
        }
    }

    public function test_payout_time_recheck_rejects_revoked_or_removed_accounts(): void
    {
        $service = app(MemberBankAccountService::class);
        $admin = User::factory()->create();
        $member = User::factory()->create();

        $account = $service->add($member, 'SCB', 'Holder Name', '2223334445');
        $service->verify($account, $admin, 'Verified with statement');
        $this->assertSame($account->getKey(), $service->assertUsableForPayout((int) $member->getKey(), (int) $account->getKey())->getKey());

        $service->reject($account->fresh(), $admin, 'Fraud review');

        $this->expectException(\App\Exceptions\WithdrawalException::class);
        $service->assertUsableForPayout((int) $member->getKey(), (int) $account->getKey());
    }

    public function test_hash_secrets_fail_closed_when_no_key_is_configured(): void
    {
        config(['rewards.bank_accounts.blind_index_key' => null, 'app.key' => '']);

        $this->expectException(\RuntimeException::class);
        app(MemberBankAccountService::class)->blindIndexHash('SCB', '1234567890');
    }

    public function test_name_comparison_ignores_order_case_punctuation_and_honorifics(): void
    {
        $service = app(MemberBankAccountService::class);
        $method = new \ReflectionMethod($service, 'normaliseName');
        $method->setAccessible(true);

        $this->assertSame(
            $method->invoke($service, 'Somchai Jaidee'),
            $method->invoke($service, 'MR. JAIDEE,  Somchai'),
        );
        $this->assertNotSame(
            $method->invoke($service, 'Somchai Jaidee'),
            $method->invoke($service, 'Somchai Prasert'),
        );
    }
}
