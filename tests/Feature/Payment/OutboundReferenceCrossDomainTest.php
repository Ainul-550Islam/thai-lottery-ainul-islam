<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\WebhookEventType;
use App\Exceptions\FinancialException;
use App\Exceptions\PayoutTransferException;
use App\Models\Payment;
use App\Models\PayoutBatch;
use App\Services\Finance\PayoutApprovalIntakeService;
use App\Services\Finance\PayoutBatchService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Payment\PayoutTransferGuard;
use App\Services\Payment\PayoutTransferReferenceService;
use App\Services\Payment\PayoutTransferService;
use App\Services\Payment\WithdrawalProviderReferenceService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesExternalPayout;

final class OutboundReferenceCrossDomainTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    public function test_prize_owner_without_a_payment_reference_blocks_withdrawal_before_debit(): void
    {
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        DB::transaction(fn () => app(PayoutTransferReferenceService::class)->bind($f['row']->fresh(), Payment::query()->sole(), 'SYNTHETIC-COMMON-OWNER'));
        $w = $this->approvedReferenceWithdrawal();
        try {
            app(WithdrawalCompletionService::class)->complete($w['row'], options: ['provider_reference' => 'SYNTHETIC-COMMON-OWNER']);
            $this->fail('Shared transfer consumed twice.');
        } catch (FinancialException) {
            $this->assertSame('1000.00', (string) $w['wallet']->fresh()->balance);
            $this->assertSame('400.00', (string) $w['wallet']->fresh()->locked_balance);
            $this->assertDatabaseCount('financial_transactions', 0);
            $this->assertDatabaseCount('outbound_provider_references', 1);
            $this->assertDatabaseCount('withdrawal_provider_references', 0);
        }
    }

    public function test_withdrawal_owner_without_payment_blocks_prize_finality(): void
    {
        $w = $this->approvedReferenceWithdrawal();
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($w['row'], 'bank_transfer', 'SYNTHETIC-WITHDRAWAL-OWNER'));
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        try {
            $service->complete($f['data'], 'SYNTHETIC-WITHDRAWAL-OWNER', $f['reviewer'], true, 'Independent synthetic final reference review.');
            $this->fail('Foreign withdrawal reference completed a prize.');
        } catch (PayoutTransferException) {
            $this->assertSame('processing', $f['row']->fresh()->status->value);
            $this->assertDatabaseCount('financial_transactions', 0);
            $this->assertDatabaseCount('payout_transfer_references', 0);
            $this->assertDatabaseCount('outbound_provider_references', 1);
        }
    }

    public function test_unindexed_soft_deleted_withdrawal_reference_blocks_new_prize(): void
    {
        $w = $this->approvedReferenceWithdrawal();
        $w['row']->update(['provider_reference' => 'SYNTHETIC-LEGACY-ONLY']);
        $w['row']->delete();
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        $this->expectException(PayoutTransferException::class);
        try {
            $service->complete($f['data'], 'SYNTHETIC-LEGACY-ONLY', $f['reviewer'], true, 'Deleted history cannot surrender a reference.');
        } finally {
            $this->assertDatabaseCount('outbound_provider_references', 0);
            $this->assertDatabaseCount('financial_transactions', 0);
        }
    }

    public function test_legacy_prize_metadata_without_payment_reference_blocks_withdrawal(): void
    {
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        $metadata = $f['row']->fresh()->metadata;
        $metadata['transfer']['gateway_reference'] = 'SYNTHETIC-PRIZE-METADATA';
        $f['row']->update(['metadata' => $metadata]);
        $w = $this->approvedReferenceWithdrawal();
        $this->expectException(FinancialException::class);
        try {
            app(WithdrawalCompletionService::class)->complete($w['row'], options: ['provider_reference' => 'SYNTHETIC-PRIZE-METADATA']);
        } finally {
            $this->assertSame('400.00', (string) $w['wallet']->fresh()->locked_balance);
            $this->assertDatabaseCount('outbound_provider_references', 0);
        }
    }

    public function test_manual_and_bank_aliases_use_the_same_common_namespace(): void
    {
        $w = $this->approvedReferenceWithdrawal();
        DB::transaction(fn () => app(WithdrawalProviderReferenceService::class)->bind($w['row'], 'manual', 'SYNTHETIC-ALIAS-REFERENCE'));
        $f = $this->approvedIntake('cheque');
        $batch = app(PayoutApprovalIntakeService::class)->batch($f['reviewer'], [$f['row']->id]);
        app(PayoutBatchService::class)->claim($batch);
        $data = app(PayoutTransferGuard::class)->fromRow($f['row']->fresh());
        $service = app(PayoutTransferService::class);
        $service->transfer($data);
        $this->expectException(PayoutTransferException::class);
        $service->complete($data, 'SYNTHETIC-ALIAS-REFERENCE', $f['reviewer'], true, 'Cheque alias cannot reuse a bank reference.');
    }

    public function test_outer_rollback_retains_no_local_or_common_binding(): void
    {
        $w = $this->approvedReferenceWithdrawal();
        DB::beginTransaction();
        app(WithdrawalProviderReferenceService::class)->bind($w['row'], 'bank_transfer', 'SYNTHETIC-ROLLBACK');
        $this->assertDatabaseCount('outbound_provider_references', 1);
        DB::rollBack();
        $this->assertDatabaseCount('outbound_provider_references', 0);
        $this->assertDatabaseCount('withdrawal_provider_references', 0);
        $this->assertSame('400.00', (string) $w['wallet']->fresh()->locked_balance);
    }

    public function test_compatible_completed_withdrawal_replay_checks_common_ownership_and_does_not_debit_again(): void
    {
        $w = $this->approvedReferenceWithdrawal();
        $service = app(WithdrawalCompletionService::class);
        $service->complete($w['row'], options: ['provider_reference' => 'SYNTHETIC-REPLAY']);
        $out = $service->complete($w['row']->fresh());
        $this->assertFalse($out['debited']);
        $this->assertSame('600.00', (string) $w['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('outbound_provider_references', 1);
    }

    public static function callbackOutcomes(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('callbackOutcomes')]
    public function test_foreign_prize_reference_cannot_debit_or_release_a_withdrawal_via_callback(bool $success): void
    {
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        $ref = 'SYNTHETIC-PRIZE-CALLBACK-CONFLICT';
        DB::transaction(fn () => app(PayoutTransferReferenceService::class)->bind($f['row']->fresh(), Payment::query()->sole(), $ref));
        $w = $this->approvedReferenceWithdrawal();
        $out = $this->webhookService()->processWebhookPayload(new WebhookPayload(
            gateway: 'bank_transfer', eventId: 'SYNTHETIC-CROSS-DOMAIN-'.bin2hex(random_bytes(8)),
            eventType: $success ? WebhookEventType::WithdrawalSuccess : WebhookEventType::WithdrawalFailed,
            providerReference: $ref, internalReference: $w['row']->reference_number, amount: '400.00', currency: Currency::THB, isSuccess: $success));
        $this->assertFalse($out->success);
        $this->assertSame('1000.00', (string) $w['wallet']->fresh()->balance);
        $this->assertSame('400.00', (string) $w['wallet']->fresh()->locked_balance);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('withdrawal_provider_references', 0);
        $this->assertDatabaseCount('outbound_provider_references', 1);
    }

    public function test_caught_refusal_inside_outer_transaction_does_not_commit_a_local_poison_binding(): void
    {
        $w = $this->approvedReferenceWithdrawal();
        $f = $this->externalFixture();
        app(PayoutTransferService::class)->transfer($f['data']);
        $ref = 'SYNTHETIC-CAUGHT-REFUSAL';
        DB::transaction(fn () => app(PayoutTransferReferenceService::class)->bind($f['row']->fresh(), Payment::query()->sole(), $ref));
        DB::transaction(function () use ($w, $ref): void {
            try {
                app(WithdrawalProviderReferenceService::class)->bind($w['row'], 'bank_transfer', $ref);
                $this->fail('A foreign common owner must refuse.');
            } catch (FinancialException) {
                $this->assertDatabaseCount('withdrawal_provider_references', 0);
            }
        });
        $this->assertDatabaseCount('withdrawal_provider_references', 0);
        $this->assertDatabaseCount('outbound_provider_references', 1);
    }

    public function test_completed_external_projection_requires_common_binding_without_repairing_it(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);
        $service->complete($f['data'], 'SYNTHETIC-COMMON-PROJECTION', $f['reviewer'], true, 'Independent synthetic original result review.');
        DB::table('outbound_provider_references')->delete();
        $batch = PayoutBatch::query()->sole();
        $this->expectException(FinancialException::class);
        try {
            app(PayoutBatchService::class)->refresh($batch);
        } finally {
            $this->assertDatabaseCount('outbound_provider_references', 0);
            $this->assertDatabaseCount('financial_transactions', 1);
        }
    }

    public function test_completed_withdrawal_replay_does_not_ignore_corrupt_common_ownership(): void
    {
        $w = $this->approvedReferenceWithdrawal();
        $service = app(WithdrawalCompletionService::class);
        $service->complete($w['row'], options: ['provider_reference' => 'SYNTHETIC-REPLAY-DRIFT']);
        DB::table('outbound_provider_references')->update(['reference_hash' => str_repeat('0', 64)]);
        $this->expectException(FinancialException::class);
        try {
            $service->complete($w['row']->fresh());
        } finally {
            $this->assertSame('600.00', (string) $w['wallet']->fresh()->balance);
            $this->assertDatabaseCount('financial_transactions', 1);
        }
    }
}
