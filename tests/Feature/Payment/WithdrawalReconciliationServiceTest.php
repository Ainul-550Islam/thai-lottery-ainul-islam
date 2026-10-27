<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\WebhookPayload;
use App\DTOs\Payment\WithdrawalReconciliationFacts;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserStatus;
use App\Enums\WebhookEventType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\WithdrawalReconciliationException;
use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\User;
use App\Models\WithdrawalReconciliation;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Payment\WithdrawalReconciliationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class WithdrawalReconciliationServiceTest extends PaymentTestCase
{
    private function fixture(Currency $currency = Currency::THB, ?array $destination = null): array
    {
        $this->seed(RolePermissionSeeder::class);
        $maker = User::factory()->create();
        $checker = User::factory()->create();
        $maker->assignRole('admin');
        $checker->assignRole('admin');
        $player = $this->createPlayer('1000.00', $currency);
        $row = app(WithdrawalService::class)->request($player['wallet'], Money::of('400.00', $currency), PaymentMethod::BankTransfer,
            options: ['payout_details' => $destination ?? ['bank_name' => 'SYNTHETIC', 'account_number' => 'PRIVATE-TEST-DESTINATION']]);
        app(WithdrawalApprovalService::class)->approve($row);
        $driver = $this->createMock(PaymentGatewayInterface::class);
        $driver->method('name')->willReturn('bank_transfer');
        $driver->method('supportsCurrency')->willReturn(true);
        $driver->expects($this->once())->method('initiateWithdrawal')->willReturn(GatewayWithdrawalResponse::uncertain());
        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('withdrawalDriver')->willReturn($driver);
        $this->app->instance(PaymentGatewayManager::class, $manager);
        app(WithdrawalDisbursementService::class)->disburse($row);
        $row->refresh();
        $facts = ['internal_reference' => $row->reference_number, 'provider' => 'bank_transfer', 'provider_reference' => 'SYNTHETIC-FINAL-'.$row->id,
            'currency' => $currency->value, 'gross_amount' => '400.00', 'net_amount' => (string) $row->net_amount,
            'outcome' => 'completed', 'observed_at' => now()->toIso8601String()];

        return [$row, $player['wallet'], $maker, $checker, $facts];
    }

    private function file(string $suffix = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('provider-proof.txt', 'SYNTHETIC final provider evidence. Not a payment. '.$suffix);
    }

    private function submit(array $fixture, array $override = []): WithdrawalReconciliation
    {
        return app(WithdrawalReconciliationService::class)->request($fixture[2], $fixture[0]->id,
            WithdrawalReconciliationFacts::fromArray(array_replace($fixture[4], $override)), $this->file());
    }

    private function apply(User $checker, WithdrawalReconciliation $case): WithdrawalReconciliation
    {
        return app(WithdrawalReconciliationService::class)->decide($checker, $case, 'apply', 'Independently verified synthetic provider evidence.', true);
    }

    public function test_submission_is_idempotent_and_does_not_change_money(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f);
        $again = $this->submit($f);
        $this->assertSame($case->id, $again->id);
        $this->assertSame('pending', $case->status);
        $this->assertSame('400.00', (string) $f[1]->fresh()->locked_balance);
        $this->assertSame('1000.00', (string) $f[1]->fresh()->balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->assertSame(1, WithdrawalReconciliation::query()->count());
    }

    #[DataProvider('currencies')]
    public function test_independent_completion_consumes_hold_and_debits_exactly_once(string $currency): void
    {
        $f = $this->fixture(Currency::from($currency));
        $case = $this->submit($f);
        $applied = $this->apply($f[3], $case);
        $txCount = FinancialTransaction::query()->count();
        $entryCount = LedgerEntry::query()->count();
        $this->assertSame('applied', $applied->status);
        $this->assertSame($f[3]->id, $applied->checker_id);
        $this->assertSame(WithdrawalStatus::Completed, $f[0]->fresh()->status);
        $this->assertSame('600.00', (string) $f[1]->fresh()->balance);
        $this->assertSame('0.00', (string) $f[1]->fresh()->locked_balance);
        $this->assertSame(1, $txCount);
        $this->assertGreaterThanOrEqual(2, $entryCount);
        $this->assertSame(PaymentStatus::Captured, Payment::query()->sole()->status);
        $this->assertSame('completed', $f[0]->fresh()->metadata['outbound_dispatch']['state']);
        $this->assertSame($case->id, $this->apply($f[3], $case)->id);
        $this->assertSame($txCount, FinancialTransaction::query()->count());
        $this->assertSame($entryCount, LedgerEntry::query()->count());
        $this->assertSame(2, AuditLog::query()->where('auditable_type', WithdrawalReconciliation::class)->count());
    }

    public static function currencies(): array
    {
        return [['THB'], ['USD'], ['BDT']];
    }

    public function test_definitive_rejection_releases_hold_without_refund_credit_or_resend(): void
    {
        $f = $this->fixture();
        $this->apply($f[3], $this->submit($f, ['outcome' => 'definitively_rejected']));
        $this->assertSame(WithdrawalStatus::Failed, $f[0]->fresh()->status);
        $this->assertSame('1000.00', (string) $f[1]->fresh()->balance);
        $this->assertSame('0.00', (string) $f[1]->fresh()->locked_balance);
        $this->assertSame(PaymentStatus::Failed, Payment::query()->sole()->status);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
        $this->expectException(FinancialException::class);
        app(WithdrawalDisbursementService::class)->disburse($f[0]->fresh(), ['force' => true]);
    }

    public function test_rejecting_an_evidence_case_keeps_original_uncertainty_and_hold(): void
    {
        $f = $this->fixture();
        $case = app(WithdrawalReconciliationService::class)->decide($f[3], $this->submit($f), 'reject', 'Provider evidence was insufficient for a final result.', false);
        $this->assertSame('rejected', $case->status);
        $this->assertSame(WithdrawalStatus::Processing, $f[0]->fresh()->status);
        $this->assertSame('unknown', $f[0]->fresh()->metadata['outbound_dispatch']['state']);
        $this->assertSame('400.00', (string) $f[1]->fresh()->locked_balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
    }

    public function test_maker_cannot_check_even_with_super_admin_role(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f);
        $f[2]->assignRole('super-admin');
        $this->expectException(WithdrawalReconciliationException::class);
        $this->expectExceptionMessage('maker_cannot_check');
        $this->apply($f[2], $case);
    }

    public function test_beneficiary_cannot_submit_or_check_own_money(): void
    {
        $f = $this->fixture();
        $owner = $f[0]->user;
        $owner->assignRole('admin');
        $case = $this->submit($f);
        try {
            $this->apply($owner, $case);
            $this->fail('Beneficiary checked own withdrawal.');
        } catch (WithdrawalReconciliationException $e) {
            $this->assertSame('beneficiary_cannot_reconcile_own_withdrawal', $e->reason);
        }
        $this->expectExceptionMessage('beneficiary_cannot_reconcile_own_withdrawal');
        app(WithdrawalReconciliationService::class)->request($owner, $f[0]->id, WithdrawalReconciliationFacts::fromArray($f[4]), $this->file());
    }

    #[DataProvider('badFacts')]
    public function test_incomplete_or_foreign_facts_cannot_open_a_case(string $field, string $value): void
    {
        $f = $this->fixture();
        $this->expectException(WithdrawalReconciliationException::class);
        $this->submit($f, [$field => $value]);
    }

    public static function badFacts(): array
    {
        return [['outcome', 'unknown'], ['outcome', 'not_found'], ['outcome', 'pending'], ['outcome', 'timeout'],
            ['gross_amount', '4e2'], ['gross_amount', '400'], ['gross_amount', '401.00'], ['net_amount', '0.00'],
            ['currency', 'USD'], ['provider', 'stripe'], ['provider_reference', 'bad private ref'], ['internal_reference', 'FOREIGN'],
            ['observed_at', '2020-01-01T00:00:00+00:00'], ['observed_at', '2026-02-30T12:00:00+00:00']];
    }

    public function test_lost_checker_role_is_rechecked_from_persisted_authority(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f);
        $f[3]->load('roles');
        User::query()->findOrFail($f[3]->id)->syncRoles(['player']);
        $this->expectException(AuthorizationException::class);
        $this->apply($f[3], $case);
    }

    public function test_suspended_super_admin_cannot_apply_evidence(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f);
        $f[3]->assignRole('super-admin');
        $f[3]->status = UserStatus::Suspended;
        $f[3]->save();
        $this->expectException(AuthorizationException::class);
        $this->apply($f[3], $case);
    }

    public function test_revoked_maker_authority_blocks_money_but_allows_case_rejection(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f);
        $f[2]->syncRoles(['player']);
        try {
            $this->apply($f[3], $case);
            $this->fail('Revoked maker evidence applied.');
        } catch (AuthorizationException) {
            $this->assertSame('pending', $case->fresh()->status);
        }
        $this->assertSame('rejected', app(WithdrawalReconciliationService::class)->decide($f[3], $case, 'reject', 'Maker authorization was revoked; evidence not applied.', false)->status);
    }

    public function test_missing_independent_attestation_cannot_move_money(): void
    {
        $f = $this->fixture();
        $this->expectExceptionMessage('independent_evidence_verification_required');
        app(WithdrawalReconciliationService::class)->decide($f[3], $this->submit($f), 'apply', 'A sufficiently long review note.', false);
    }

    public function test_missing_or_corrupt_private_artifact_rolls_back_application(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f);
        $path = storage_path('app/private/withdrawal-reconciliation/'.$case->evidence_sha256);
        $original = file_get_contents($path);
        file_put_contents($path, 'CORRUPTED SYNTHETIC EVIDENCE');
        try {
            $this->apply($f[3], $case);
            $this->fail('Corrupt artifact applied.');
        } catch (WithdrawalReconciliationException $e) {
            $this->assertSame('private_evidence_missing_or_corrupt', $e->reason);
            $this->assertSame('pending', $case->fresh()->status);
            $this->assertSame('400.00', (string) $f[1]->fresh()->locked_balance);
            $this->assertSame(0, FinancialTransaction::query()->count());
        } finally {
            file_put_contents($path, $original);
        }
    }

    public function test_original_evidence_is_encrypted_and_model_facts_cannot_be_rewritten(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f);
        $path = storage_path('app/private/withdrawal-reconciliation/'.$case->evidence_sha256);
        $this->assertStringNotContainsString('SYNTHETIC final provider', file_get_contents($path));
        $this->assertStringNotContainsString($f[4]['provider_reference'], $case->getRawOriginal('facts'));
        $this->assertStringNotContainsString('PRIVATE-TEST-DESTINATION', json_encode(AuditLog::query()->get()->toArray(), JSON_THROW_ON_ERROR));
        $this->expectException(\LogicException::class);
        $case->forceFill(['facts' => ['forged' => true]])->save();
    }

    public function test_case_evidence_cannot_be_deleted(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f);
        $this->expectException(\LogicException::class);
        $case->delete();
    }

    public function test_destination_change_after_maker_submission_is_refused(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f);
        $f[0]->payout_details = ['account_number' => 'ALTERED-TEST-DESTINATION'];
        $f[0]->save();
        $this->expectException(FinancialException::class);
        $this->apply($f[3], $case);
    }

    public function test_two_conflicting_cases_cannot_release_money_after_completion(): void
    {
        $f = $this->fixture();
        $paid = $this->submit($f);
        $failed = $this->submit($f, ['outcome' => 'definitively_rejected']);
        $this->apply($f[3], $paid);
        try {
            $this->apply($f[3], $failed);
            $this->fail('Second conflicting final result applied.');
        } catch (WithdrawalReconciliationException $e) {
            $this->assertSame('evidence_obligation_conflict', $e->reason);
        }
        $this->assertSame('600.00', (string) $f[1]->fresh()->balance);
        $this->assertSame('0.00', (string) $f[1]->fresh()->locked_balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame('pending', $failed->fresh()->status);
    }

    public function test_audit_failure_rolls_back_money_payment_claim_and_case_together(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f);
        AuditLog::creating(function (AuditLog $log): void {
            if ($log->auditable_type === WithdrawalReconciliation::class) {
                throw new \RuntimeException('SYNTHETIC AUDIT FAILURE');
            }
        });
        try {
            $this->apply($f[3], $case);
            $this->fail('Audit failure did not abort the transaction.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SYNTHETIC AUDIT FAILURE', $e->getMessage());
            $this->assertSame('pending', $case->fresh()->status);
            $this->assertSame(WithdrawalStatus::Processing, $f[0]->fresh()->status);
            $this->assertSame('1000.00', (string) $f[1]->fresh()->balance);
            $this->assertSame('400.00', (string) $f[1]->fresh()->locked_balance);
            $this->assertSame(PaymentStatus::Pending, Payment::query()->sole()->status);
            $this->assertSame(0, FinancialTransaction::query()->count());
            $this->assertSame(0, LedgerEntry::query()->count());
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_read_only_claim_inspection_requires_a_transaction(): void
    {
        $f = $this->fixture();
        $this->expectException(FinancialException::class);
        app(WithdrawalDisbursementService::class)->inspectReconciliationClaim($f[0], $f[1], Payment::query()->sole());
    }

    public function test_same_provider_reference_cannot_settle_two_different_withdrawals(): void
    {
        $first = $this->fixture();
        $second = $this->fixture();
        $second[4]['provider_reference'] = $first[4]['provider_reference'];
        $a = $this->submit($first);
        $b = $this->submit($second);
        $this->apply($first[3], $a);
        try {
            $this->apply($second[3], $b);
            $this->fail('Provider evidence reference was reused for different money.');
        } catch (WithdrawalReconciliationException $e) {
            $this->assertSame('provider_reference_already_bound', $e->reason);
        }
        $this->assertSame('pending', $b->fresh()->status);
        $this->assertSame('1000.00', (string) $second[1]->fresh()->balance);
        $this->assertSame('400.00', (string) $second[1]->fresh()->locked_balance);
        $this->assertSame(1, DB::table('withdrawal_reconciliation_references')->count());
    }

    public function test_changed_claim_snapshot_requires_new_evidence_case(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f);
        $metadata = $f[0]->fresh()->metadata;
        $metadata['outbound_dispatch']['state'] = 'pending';
        $f[0]->metadata = $metadata;
        $f[0]->save();
        $this->expectExceptionMessage('evidence_snapshot_changed');
        $this->apply($f[3], $case);
    }

    public function test_missing_private_artifact_cannot_settle_money(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f);
        $path = storage_path('app/private/withdrawal-reconciliation/'.$case->evidence_sha256);
        $bytes = file_get_contents($path);
        unlink($path);
        try {
            $this->apply($f[3], $case);
            $this->fail('Missing evidence settled money.');
        } catch (WithdrawalReconciliationException $e) {
            $this->assertSame('private_evidence_missing_or_corrupt', $e->reason);
            $this->assertSame('400.00', (string) $f[1]->fresh()->locked_balance);
        } finally {
            file_put_contents($path, $bytes);
        }
    }

    public function test_pending_without_one_bound_payment_is_not_fabricated_into_evidence(): void
    {
        $f = $this->fixture();
        Payment::query()->sole()->delete();
        $this->expectExceptionMessage('one_bound_payment_required');
        $this->submit($f);
    }

    public function test_later_rollback_does_not_leave_a_provider_reference_binding(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f);
        DB::beginTransaction();
        $this->apply($f[3], $case);
        $this->assertSame(1, DB::table('withdrawal_reconciliation_references')->count());
        DB::rollBack();
        $this->assertSame(0, DB::table('withdrawal_reconciliation_references')->count());
        $this->assertSame('pending', $case->fresh()->status);
        $this->assertSame('1000.00', (string) $f[1]->fresh()->balance);
        $this->assertSame('400.00', (string) $f[1]->fresh()->locked_balance);
    }

    public function test_callback_completion_after_submission_cannot_be_overwritten_by_human_failure_evidence(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f, ['outcome' => 'definitively_rejected']);
        $callback = $this->webhookService()->processWebhookPayload(new WebhookPayload(
            gateway: 'bank_transfer', eventId: 'SYNTHETIC-CALLBACK-BEFORE-REVIEW',
            eventType: WebhookEventType::WithdrawalSuccess, providerReference: $f[4]['provider_reference'],
            internalReference: $f[0]->reference_number, amount: '400.00', currency: Currency::THB, isSuccess: true,
        ));
        $this->assertTrue($callback->success);
        try {
            $this->apply($f[3], $case);
            $this->fail('Human evidence overwrote a completed callback.');
        } catch (WithdrawalReconciliationException $e) {
            $this->assertSame('evidence_obligation_conflict', $e->reason);
        }
        $this->assertSame('600.00', (string) $f[1]->fresh()->balance);
        $this->assertSame('0.00', (string) $f[1]->fresh()->locked_balance);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame('pending', $case->fresh()->status);
    }

    public function test_independent_checker_can_inspect_bound_original_destination_without_moving_money(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f);
        $target = app(WithdrawalReconciliationService::class)->destinationForReview($f[3], $case);
        $this->assertSame('PRIVATE-TEST-DESTINATION', $target['payout_details']['account_number']);
        $this->assertSame('pending', $case->fresh()->status);
        $this->assertSame('400.00', (string) $f[1]->fresh()->locked_balance);
        $this->assertSame(0, FinancialTransaction::query()->count());
        $this->expectExceptionMessage('independent_pending_review_required');
        app(WithdrawalReconciliationService::class)->destinationForReview($f[2], $case);
    }

    public function test_final_case_cannot_disclose_private_destination_again(): void
    {
        $f = $this->fixture();
        $case = $this->submit($f);
        $this->apply($f[3], $case);
        $this->expectExceptionMessage('independent_pending_review_required');
        app(WithdrawalReconciliationService::class)->destinationForReview($f[3], $case);
    }

    public function test_unknown_or_secret_destination_fields_require_adapter_review_not_blind_attestation(): void
    {
        $f = $this->fixture(Currency::THB, ['account_number' => 'SYNTHETIC', 'private_api_token' => 'MUST-NOT-DISCLOSE']);
        $this->expectExceptionMessage('payout_schema_requires_private_adapter_review');
        $this->submit($f);
    }
}
