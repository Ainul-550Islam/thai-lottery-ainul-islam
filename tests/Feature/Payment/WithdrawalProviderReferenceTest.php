<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\Payment;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\WithdrawalProviderReferenceService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class WithdrawalProviderReferenceTest extends PaymentTestCase
{
    private function row(Currency $currency = Currency::THB)
    {
        $p = $this->createPlayer('1000.00', $currency);
        $row = app(WithdrawalService::class)->request($p['wallet'], Money::of('400.00', $currency), PaymentMethod::BankTransfer);
        app(WithdrawalApprovalService::class)->approve($row);

        return $row->fresh();
    }

    public function test_binding_requires_sql_transaction_and_changes_no_money(): void
    {
        $row = $this->row();
        try {
            app(WithdrawalProviderReferenceService::class)->bind($row, 'bank_transfer', 'SYNTHETIC-REFERENCE');
            $this->fail('Unowned transaction accepted.');
        } catch (FinancialException) {
            $this->assertSame(0, DB::table('withdrawal_provider_references')->count());
        }
        $id = DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($row, 'bank_transfer', 'SYNTHETIC-REFERENCE'));
        $again = DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($row, 'manual', 'SYNTHETIC-REFERENCE'));
        $this->assertSame($id, $again);
        $this->assertSame('1000.00', (string) $row->wallet->fresh()->balance);
        $this->assertSame('400.00', (string) $row->wallet->fresh()->locked_balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    #[DataProvider('badReferences')]
    public function test_malformed_or_empty_reference_is_not_normalized_into_ownership(string $reference): void
    {
        $row = $this->row();
        $this->expectException(FinancialException::class);
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($row, 'bank_transfer', $reference));
    }

    public static function badReferences(): array
    {
        return [[''], [' leading'], ['trailing '], ["unsafe\nreference"], [str_repeat('x', 256)]];
    }

    public function test_one_obligation_cannot_rotate_to_another_reference(): void
    {
        $row = $this->row();
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($row, 'bank_transfer', 'SYNTHETIC-ONE'));
        $this->expectException(FinancialException::class);
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($row, 'bank_transfer', 'SYNTHETIC-TWO'));
    }

    public function test_unknown_provider_cannot_be_bound_to_bank_transfer(): void
    {
        $row = $this->row();
        $this->expectException(FinancialException::class);
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($row, 'stripe', 'SYNTHETIC-REFERENCE'));
    }

    public function test_currency_is_not_a_namespace_for_reusing_the_same_external_transfer(): void
    {
        $a = $this->row(Currency::THB);
        $b = $this->row(Currency::USD);
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($a, 'bank_transfer', 'SYNTHETIC-REFERENCE'));
        $this->expectException(FinancialException::class);
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($b, 'bank_transfer', 'SYNTHETIC-REFERENCE'));
    }

    public function test_soft_deleted_historical_owner_is_not_forgotten(): void
    {
        $old = $this->row();
        $old->provider_reference = 'SYNTHETIC-LEGACY';
        $old->provider = 'bank_transfer';
        $old->save();
        $old->delete();
        $fresh = $this->row();
        $this->expectException(FinancialException::class);
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($fresh, 'bank_transfer', 'SYNTHETIC-LEGACY'));
    }

    public function test_legacy_payment_only_reference_blocks_reuse_without_rewriting_history(): void
    {
        $old = $this->row();
        $payment = Payment::query()->create(['reference_number' => 'SYNTHETIC-LEGACY-PAYMENT', 'user_id' => $old->user_id,
            'payable_type' => $old::class, 'payable_id' => $old->id, 'method' => PaymentMethod::BankTransfer,
            'status' => PaymentStatus::Pending, 'currency' => Currency::THB, 'amount' => '400.00', 'fee' => (string) $old->fee,
            'gateway' => 'bank_transfer', 'gateway_reference' => 'SYNTHETIC-LEGACY']);
        $payment->delete();
        $fresh = $this->row();
        try {
            DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($fresh, 'bank_transfer', 'SYNTHETIC-LEGACY'));
            $this->fail('Forgot soft-deleted payment evidence.');
        } catch (FinancialException) {
            $this->assertNull($old->fresh()->provider_reference);
            $this->assertSame('SYNTHETIC-LEGACY', Payment::withTrashed()->findOrFail($payment->id)->gateway_reference);
        }
    }

    public function test_duplicate_legacy_facts_fail_closed_instead_of_choosing_a_winner(): void
    {
        $a = $this->row();
        $b = $this->row();
        foreach ([$a, $b] as $row) {
            $row->provider_reference = 'SYNTHETIC-LEGACY-DUPLICATE';
            $row->save();
        }
        try {
            DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($a, 'bank_transfer', 'SYNTHETIC-LEGACY-DUPLICATE'));
            $this->fail('Arbitrarily chose a legacy duplicate winner.');
        } catch (FinancialException) {
            $this->assertSame(0, DB::table('withdrawal_provider_references')->count());
            $this->assertSame(WithdrawalStatus::Approved, $a->fresh()->status);
            $this->assertSame(WithdrawalStatus::Approved, $b->fresh()->status);
        }
    }

    public function test_later_failure_rolls_back_identity_and_financial_effects_together(): void
    {
        $row = $this->row();
        DB::beginTransaction();
        app(WithdrawalCompletionService::class)->complete($row, options: ['provider' => 'bank_transfer', 'provider_reference' => 'SYNTHETIC-ROLLBACK']);
        $this->assertSame(1, DB::table('withdrawal_provider_references')->count());
        DB::rollBack();
        $this->assertSame(0, DB::table('withdrawal_provider_references')->count());
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame('1000.00', (string) $row->wallet->fresh()->balance);
        $this->assertSame('400.00', (string) $row->wallet->fresh()->locked_balance);
    }

    public function test_known_reference_free_completion_remains_the_existing_internal_manual_lane(): void
    {
        $row = $this->row();
        app(WithdrawalCompletionService::class)->complete($row);
        $this->assertSame(WithdrawalStatus::Completed, $row->fresh()->status);
        $this->assertSame(0, DB::table('withdrawal_provider_references')->count());
        $this->assertSame(1, FinancialTransaction::query()->count());
    }
}
