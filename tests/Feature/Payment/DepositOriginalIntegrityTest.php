<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Exceptions\PaymentVerificationException;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\Payment\DepositProviderAccountService;
use App\Services\Payment\FinancialProviderReferenceService;
use App\Services\Payment\PaymentVerificationService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class DepositOriginalIntegrityTest extends PaymentTestCase
{
    private function fixture(string $amount = '400.00'): array
    {
        $owner = $this->createPlayer('100.00');
        $row = app(DepositService::class)->request($owner['wallet'], Money::of($amount, Currency::THB), PaymentMethod::BankTransfer);
        app(DepositApprovalService::class)->approve($row);

        return ['row' => $row->fresh(), 'wallet' => $owner['wallet'], 'user' => $owner['user']];
    }

    public function test_original_net_credit_and_terminal_replay_have_one_immutable_reference_and_balanced_transaction(): void
    {
        config(['finance.deposit.fee_percentage' => '2.00']);
        $f = $this->fixture('500.00');
        $service = app(DepositCompletionService::class);
        $out = $service->complete($f['row'], options: ['provider_reference' => 'SYNTHETIC-ORIGINAL-NET', 'metadata' => ['source_event_id' => 'SYNTHETIC-ORIGINAL-EVENT', 'deposit_id' => 999999]]);
        $this->assertTrue($out['credited']);
        $this->assertSame('SYNTHETIC-ORIGINAL-EVENT', $out['transaction']->metadata['source_event_id']);
        $this->assertSame($f['row']->id, $out['transaction']->metadata['deposit_id']);
        $this->assertSame('490.00', $out['amount']);
        $this->assertSame('590.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount(FinancialProviderReferenceService::TABLE, 1);
        $before = $f['row']->fresh()->getRawOriginal();
        $this->assertTrue($service->complete($f['row']->fresh())['replayed']);
        $this->assertSame($before, $f['row']->fresh()->getRawOriginal());
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertStringNotContainsString('SYNTHETIC-ORIGINAL-NET', json_encode(DB::table(FinancialProviderReferenceService::TABLE)->first()));
    }

    public static function corruptOriginalFacts(): array
    {
        return array_map(fn ($x) => [$x], ['owner', 'currency', 'net', 'fee', 'provider', 'reference', 'custom_key', 'no_reference']);
    }

    #[DataProvider('corruptOriginalFacts')]
    public function test_conflicting_original_facts_cannot_credit_or_reserve_ownership(string $kind): void
    {
        $f = $this->fixture();
        $row = $f['row'];
        $options = ['provider_reference' => 'SYNTHETIC-NOT-PAYABLE'];
        $key = null;
        match ($kind) {
            'owner' => $row->user_id = User::factory()->create()->id,
            'currency' => $row->currency = Currency::USD,
            'net' => $row->net_amount = '999.00',
            'fee' => $row->fee = '1.00',
            'provider' => $options['provider'] = 'stripe',
            'reference' => $row->provider_reference = 'OTHER ORIGINAL REFERENCE',
            'custom_key' => $key = 'caller-chosen-foreign-credit',
            'no_reference' => $options = [],
        };
        $row->save();
        $this->expectException(FinancialException::class);
        try {
            app(DepositCompletionService::class)->complete($row, $key, $options);
        } finally {
            $this->assertSame('100.00', $f['wallet']->fresh()->balance);
            $this->assertDatabaseCount('financial_transactions', 0);
            $this->assertDatabaseCount(FinancialProviderReferenceService::TABLE, 0);
        }
    }

    public function test_foreign_balanced_transaction_cannot_heal_or_replay_an_unrelated_deposit(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $out = app(DepositCompletionService::class)->complete($a['row'], options: ['provider_reference' => 'SYNTHETIC-FOREIGN-TX']);
        $b['row']->financial_transaction_id = $out['transaction']->id;
        $b['row']->provider_reference = 'SYNTHETIC-SECOND-TX';
        $b['row']->save();
        foreach ([DepositStatus::Approved, DepositStatus::Confirmed] as $state) {
            $b['row']->status = $state;
            $b['row']->confirmed_at = now();
            $b['row']->save();
            try {
                app(DepositCompletionService::class)->complete($b['row']);
                $this->fail('Foreign transaction must never establish deposit completion.');
            } catch (FinancialException) {
                $this->assertSame('100.00', $b['wallet']->fresh()->balance);
                $this->assertSame($state, $b['row']->fresh()->status);
            }
        }
        $this->assertDatabaseCount('financial_transactions', 1);
    }

    public function test_missing_original_binding_or_deleted_ledger_is_not_repaired_on_terminal_replay(): void
    {
        $f = $this->fixture();
        $service = app(DepositCompletionService::class);
        $service->complete($f['row'], options: ['provider_reference' => 'SYNTHETIC-LOST-ORIGINAL']);
        DB::table(FinancialProviderReferenceService::TABLE)->delete();
        try {
            $service->complete($f['row']->fresh());
            $this->fail('A missing terminal binding must remain a controlled hold.');
        } catch (FinancialException) {
            $this->assertDatabaseCount(FinancialProviderReferenceService::TABLE, 0);
        }
        LedgerEntry::query()->firstOrFail()->delete();
        $this->expectException(FinancialException::class);
        try {
            $service->verifySettled($f['row']);
        } finally {
            $this->assertSame('500.00', $f['wallet']->fresh()->balance);
            $this->assertDatabaseCount('financial_transactions', 1);
        }
    }

    public function test_ledger_failure_rolls_back_new_cross_domain_owner_and_credit(): void
    {
        $f = $this->fixture();
        LedgerAccount::query()->update(['is_active' => false]);
        $this->expectException(FinancialException::class);
        try {
            app(DepositCompletionService::class)->complete($f['row'], options: ['provider_reference' => 'SYNTHETIC-ROLLBACK']);
        } finally {
            $this->assertDatabaseCount(FinancialProviderReferenceService::TABLE, 0);
            $this->assertDatabaseCount('financial_transactions', 0);
            $this->assertSame('100.00', $f['wallet']->fresh()->balance);
            $this->assertSame(DepositStatus::Approved, $f['row']->fresh()->status);
        }
    }

    public function test_server_owns_provider_account_metadata_and_replay_refuses_account_rotation(): void
    {
        config(['payment.deposit_reference_accounts.bank_transfer' => 'APPROVED-ACCOUNT']);
        $owner = $this->createPlayer('100.00');
        $service = app(DepositService::class);
        $amount = Money::of('400.00', Currency::THB);
        $row = $service->request($owner['wallet'], $amount, PaymentMethod::BankTransfer, 'account-scope-proof', [
            'metadata' => [DepositProviderAccountService::KEY => 'CALLER-OVERRIDE', 'note' => 'retained'],
        ]);
        $this->assertSame('APPROVED-ACCOUNT', $row->metadata[DepositProviderAccountService::KEY]);
        $this->assertSame('retained', $row->metadata['note']);
        $this->assertSame($row->getKey(), $service->request(
            $owner['wallet'], $amount, PaymentMethod::BankTransfer, 'account-scope-proof',
        )->getKey());

        config(['payment.deposit_reference_accounts.bank_transfer' => 'ROTATED-ACCOUNT']);
        try {
            $service->request($owner['wallet'], $amount, PaymentMethod::BankTransfer, 'account-scope-proof');
            $this->fail('A replay must never be reinterpreted under a different provider account.');
        } catch (FinancialException) {
            $this->assertSame('100.00', $owner['wallet']->fresh()->balance);
            $this->assertDatabaseCount('deposits', 1);
            $this->assertDatabaseCount('financial_transactions', 0);
        }
    }

    public function test_account_rotation_holds_original_work_instead_of_reinterpreting_old_reference(): void
    {
        $f = $this->fixture();
        config(['payment.deposit_reference_accounts.bank_transfer' => 'NEW-SYNTHETIC-ACCOUNT']);
        $this->expectException(FinancialException::class);
        try {
            app(DepositCompletionService::class)->complete($f['row'], options: ['provider_reference' => 'SYNTHETIC-ACCOUNT-ROTATION']);
        } finally {
            $this->assertDatabaseCount('financial_transactions', 0);
        }
    }

    public function test_manual_record_matching_cannot_fall_back_from_a_wrong_internal_document(): void
    {
        $f = $this->fixture();
        $f['row']->update(['provider_reference' => 'SYNTHETIC-MANUAL-ORIGINAL']);
        $this->expectException(PaymentVerificationException::class);
        try {
            app(PaymentVerificationService::class)->verify([
                'deposit_reference' => 'DP-WRONG-INTERNAL-DOCUMENT', 'provider_reference' => 'SYNTHETIC-MANUAL-ORIGINAL',
                'amount' => '400.00', 'currency' => 'THB',
            ]);
        } finally {
            $this->assertDatabaseCount('financial_transactions', 0);
            $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        }
    }

    public function test_ambiguous_historical_manual_reference_does_not_select_first_or_rebuild_evidence(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        foreach ([$a, $b] as $f) {
            $f['row']->update(['provider_reference' => 'SYNTHETIC-AMBIGUOUS-LEGACY']);
        }
        $b['row']->delete();
        $this->expectException(PaymentVerificationException::class);
        try {
            app(PaymentVerificationService::class)->verify([
                'provider_reference' => 'SYNTHETIC-AMBIGUOUS-LEGACY', 'amount' => '400.00', 'currency' => 'THB',
            ]);
        } finally {
            $this->assertDatabaseCount('financial_transactions', 0);
            $this->assertDatabaseCount(FinancialProviderReferenceService::TABLE, 0);
        }
    }
}
