<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\WithdrawalReconciliationFacts;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Exceptions\FinancialException;
use App\Models\Payment;
use App\Models\User;
use App\Models\WithdrawalReconciliation;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Payment\WithdrawalProviderReferenceService;
use App\Services\Payment\WithdrawalReconciliationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Controlled historical fixtures only; never a migration that guesses/repairs production owners. */
final class WithdrawalReferenceLegacyCompatibilityTest extends PaymentTestCase
{
    private function row(array $options = [])
    {
        $p = $this->createPlayer('1000.00', Currency::THB);
        $row = app(WithdrawalService::class)->request($p['wallet'], Money::of('400.00', Currency::THB), PaymentMethod::BankTransfer, options: $options);
        app(WithdrawalApprovalService::class)->approve($row);

        return $row->fresh();
    }

    private function payment($row, string $suffix = ''): Payment
    {
        return Payment::query()->create(['reference_number' => 'SYNTHETIC-LEGACY-'.bin2hex(random_bytes(6)).$suffix,
            'user_id' => $row->user_id, 'payable_type' => $row::class, 'payable_id' => $row->id,
            'method' => PaymentMethod::BankTransfer, 'gateway' => 'bank_transfer', 'status' => PaymentStatus::Pending,
            'currency' => Currency::THB, 'amount' => '400.00', 'fee' => (string) $row->fee]);
    }

    public function test_unscoped_history_can_be_lazily_indexed_without_rewriting_metadata_or_money(): void
    {
        $row = $this->row();
        $metadata = $row->metadata;
        unset($metadata['outbound_provider_account']);
        $row->metadata = $metadata;
        $row->provider_reference = 'SYNTHETIC-UNSCOPED-REF';
        $row->save();
        $before = $row->fresh()->getRawOriginal();
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($row, 'bank_transfer', $row->provider_reference));
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $this->assertSame('legacy-primary', DB::table('withdrawal_provider_references')->sole()->account_scope);
        $this->assertSame('1000.00', (string) $row->wallet->fresh()->balance);
        $this->assertSame('400.00', (string) $row->wallet->fresh()->locked_balance);
    }

    public function test_part28_index_only_evidence_is_honored_without_choosing_another_owner(): void
    {
        $old = $this->row();
        $payment = $this->payment($old);
        $case = new WithdrawalReconciliation;
        $case->forceFill(['withdrawal_id' => $old->id, 'payment_id' => $payment->id, 'maker_id' => $old->user_id,
            'request_key' => hash('sha256', 'SYNTHETIC-CASE'), 'snapshot_hash' => hash('sha256', 'SYNTHETIC-SNAPSHOT'),
            'evidence_sha256' => hash('sha256', 'SYNTHETIC-EVIDENCE'), 'evidence_bytes' => 1, 'facts' => [], 'status' => 'pending'])->save();
        DB::table('withdrawal_reconciliation_references')->insert(['provider_key' => hash('sha256', json_encode(['bank_transfer', 'SYNTHETIC-OLD-INDEX'], JSON_THROW_ON_ERROR)),
            'withdrawal_id' => $old->id, 'payment_id' => $payment->id, 'reconciliation_id' => $case->id, 'created_at' => now()]);
        $new = $this->row();
        try {
            DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($new, 'bank_transfer', 'SYNTHETIC-OLD-INDEX'));
            $this->fail('Reassigned old index-only evidence.');
        } catch (FinancialException) {
            $this->assertSame(0, DB::table('withdrawal_provider_references')->count());
            $this->assertSame($old->id, DB::table('withdrawal_reconciliation_references')->sole()->withdrawal_id);
        }
    }

    public function test_null_payment_binding_can_attach_its_one_real_payment_but_never_a_replacement(): void
    {
        $row = $this->row();
        $id = DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($row, 'bank_transfer', 'SYNTHETIC-ATTACH'));
        $payment = $this->payment($row);
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($row, 'bank_transfer', 'SYNTHETIC-ATTACH', $payment));
        $this->assertSame($payment->id, DB::table('withdrawal_provider_references')->find($id)->payment_id);
        $payment->delete();
        $replacement = $this->payment($this->row());
        $this->expectException(FinancialException::class);
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($row, 'bank_transfer', 'SYNTHETIC-ATTACH', $replacement));
    }

    public function test_soft_deleted_original_payment_still_blocks_a_second_payment_for_the_same_payable(): void
    {
        $row = $this->row();
        $this->payment($row)->delete();
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->payment($row);
    }

    public function test_trashed_own_payment_with_incompatible_gateway_cannot_disappear_from_historical_checks(): void
    {
        $row = $this->row();
        $payment = $this->payment($row);
        $payment->gateway = 'crypto';
        $payment->gateway_reference = 'SYNTHETIC-OLD-GATEWAY';
        $payment->save();
        $payment->delete();
        try {
            DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($row, 'bank_transfer', 'SYNTHETIC-NEW-GATEWAY'));
            $this->fail('Ignored incompatible own historical Payment.');
        } catch (FinancialException) {
            $this->assertSame(0, DB::table('withdrawal_provider_references')->count());
            $this->assertSame('400.00', (string) $row->wallet->fresh()->locked_balance);
        }
    }

    public function test_mutating_metadata_cannot_reassign_an_already_bound_obligation(): void
    {
        $row = $this->row();
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($row, 'bank_transfer', 'SYNTHETIC-ORIGINAL'));
        $metadata = $row->metadata;
        $metadata['outbound_provider_account'] = 'OTHER-ACCOUNT';
        $row->metadata = $metadata;
        $row->save();
        config(['payment.withdrawal_reference_accounts.bank_transfer' => 'OTHER-ACCOUNT']);
        $this->expectException(FinancialException::class);
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($row, 'bank_transfer', 'SYNTHETIC-ORIGINAL'));
    }

    public function test_populated_shared_ownership_prevents_destructive_migration_rollback(): void
    {
        $row = $this->row();
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($row, 'bank_transfer', 'SYNTHETIC-ROLLBACK-GUARD'));
        $migration = require base_path('database/migrations/2026_10_15_000200_create_withdrawal_provider_references_table.php');
        try {
            $migration->down();
            $this->fail('Dropped financial reference ownership.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('Refused: preserve shared withdrawal provider-reference ownership', $e->getMessage());
            $this->assertSame(1, DB::table('withdrawal_provider_references')->count());
        }
    }

    public function test_actual_web_destination_shape_can_be_independently_reviewed_without_dropping_method_facts(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $maker->assignRole('admin');
        $checker->assignRole('admin');
        $row = $this->row(['payout_details' => ['bank_name' => 'SYNTHETIC BANK', 'account_number' => 'NOT-PAYABLE',
            'account_name' => 'SYNTHETIC USER', 'method' => 'bank_transfer']]);
        $driver = $this->createMock(PaymentGatewayInterface::class);
        $driver->method('name')->willReturn('bank_transfer');
        $driver->method('supportsCurrency')->willReturn(true);
        $driver->expects($this->once())->method('initiateWithdrawal')->willReturn(GatewayWithdrawalResponse::uncertain());
        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('withdrawalDriver')->willReturn($driver);
        $this->app->instance(PaymentGatewayManager::class, $manager);
        app(WithdrawalDisbursementService::class)->disburse($row);
        $case = app(WithdrawalReconciliationService::class)->request($maker, $row->id,
            WithdrawalReconciliationFacts::fromArray(['internal_reference' => $row->reference_number,
                'provider' => 'bank_transfer', 'provider_reference' => 'SYNTHETIC-WEB-SHAPED-PROOF', 'currency' => 'THB',
                'gross_amount' => '400.00', 'net_amount' => (string) $row->net_amount, 'outcome' => 'completed', 'observed_at' => now()->toIso8601String()]),
            UploadedFile::fake()->createWithContent('proof.txt', 'SYNTHETIC WEB-SHAPED PROVIDER EVIDENCE'));
        $target = app(WithdrawalReconciliationService::class)->destinationForReview($checker, $case);
        $this->assertSame('bank_transfer', $target['payout_details']['method']);
        $this->assertSame('NOT-PAYABLE', $target['payout_details']['account_number']);
        $this->assertSame('pending', $case->fresh()->status);
    }
}
