<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\ComplianceCaseStatus;
use App\Exceptions\PayoutTransferException;
use App\Models\AuditLog;
use App\Models\ComplianceCase;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Services\Compliance\FinancialComplianceGateService;
use App\Services\Payment\PayoutTransferService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\Support\CreatesExternalPayout;

/**
 * Wiring tests for the compliance gate that now guards PayoutTransferService::complete().
 *
 * PayoutTransferCompletionTest already proves the completion lane books balanced
 * external entries; it is silent about the gate because it does not have to know
 * one exists. These tests exist so the call site inside PayoutTransferService
 * cannot be removed or reordered without a failure:
 *
 *   1. the gate class is resolvable and the completion still posts when it passes,
 *   2. a closed real-money lane refuses the payout before any money moves,
 *   3. a revoked identity or an open compliance case is refused by the gate,
 *   4. every refusal leaves an audit row that names the reason code.
 */
final class PayoutTransferComplianceGateTest extends PaymentTestCase
{
    use CreatesExternalPayout;

    /**
     * The shipped config bounds are environment driven; pin them so the fixture
     * amount always sits inside the gate's payout bounds window.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.withdrawal.min', '10.00');
        Config::set('payment.withdrawal.max', '10000.00');
    }

    public function test_gate_service_resolves_and_an_open_lane_still_posts_the_external_books(): void
    {
        $this->assertInstanceOf(FinancialComplianceGateService::class, app(FinancialComplianceGateService::class));

        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);

        $record = $service->complete(
            $f['data'],
            'SYNTHETIC-SETTLED',
            $f['reviewer'],
            true,
            'Independently verified final provider evidence.',
        );

        $this->assertSame('completed', $record['lane_status']);
        $this->assertSame('completed', $f['row']->fresh()->status->value);
        $this->assertSame('captured', Payment::query()->sole()->status->value);
        $this->assertSame(1, FinancialTransaction::query()->count());
        $this->assertSame(2, LedgerEntry::query()->count());
    }

    public function test_closed_real_money_lane_refuses_the_payout_and_moves_no_money(): void
    {
        Config::set('real_money.enabled', false);

        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);

        try {
            $service->complete(
                $f['data'],
                'SYNTHETIC-REFUSED',
                $f['reviewer'],
                true,
                'Independently verified final provider evidence.',
            );
            $this->fail('A payout completed while the real-money lane was closed.');
        } catch (PayoutTransferException $e) {
            $this->assertSame('gateway_refused', $e->errorCode());
            $this->assertStringContainsString('real_money_disabled', $e->getMessage());
        }

        // Nothing moved: the transaction rolled back, the reference is unbound and
        // the provider reference was never recorded against a captured payment.
        $this->assertSame('processing', $f['row']->fresh()->status->value);
        $this->assertDatabaseCount('payout_transfer_references', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        $this->assertSame('pending', Payment::query()->sole()->status->value);
    }

    public function test_revoked_beneficiary_kyc_is_refused_by_the_gate_before_the_own_kyc_recheck(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);

        // A revoked document with a still-pending reference binding: only the
        // gate can refuse this, and it refuses before the service binds the
        // provider reference.
        $f['owner']['user']->kycDocuments()->update(['status' => 'rejected']);

        try {
            $service->complete(
                $f['data'],
                'SYNTHETIC-KYC-REVOKED',
                $f['reviewer'],
                true,
                'Independently verified final provider evidence.',
            );
            $this->fail('A payout completed for a beneficiary whose KYC was revoked.');
        } catch (PayoutTransferException $e) {
            $this->assertSame('gateway_refused', $e->errorCode());
            $this->assertStringContainsString('payout_kyc_not_verified', $e->getMessage());
        }

        $this->assertSame('processing', $f['row']->fresh()->status->value);
        $this->assertDatabaseCount('payout_transfer_references', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_an_open_compliance_case_freezes_the_payout_at_the_gate(): void
    {
        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);

        $case = new ComplianceCase;
        $case->fill([
            'case_key' => 'case-'.Str::lower(Str::random(12)),
            'subject_user_id' => $f['owner']['user']->id,
            'case_type' => 'aml_review',
            'risk_level' => 'high',
            'trigger_reference' => 'test-trigger',
            'evidence_fingerprint' => Str::lower(Str::random(32)),
        ]);
        $case->status = ComplianceCaseStatus::Open;
        $case->save();

        try {
            $service->complete(
                $f['data'],
                'SYNTHETIC-CASE-OPEN',
                $f['reviewer'],
                true,
                'Independently verified final provider evidence.',
            );
            $this->fail('A payout completed while a compliance case was open.');
        } catch (PayoutTransferException $e) {
            $this->assertSame('gateway_refused', $e->errorCode());
            $this->assertStringContainsString('compliance_case_open', $e->getMessage());
        }

        $this->assertSame('processing', $f['row']->fresh()->status->value);
        $this->assertDatabaseCount('payout_transfer_references', 0);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('ledger_entries', 0);

        $refusal = AuditLog::query()->where('description', 'like', '%compliance_case_open%')->sole();
        $this->assertSame('compliance_case_open', $refusal->metadata['reason_code']);
    }

    public function test_every_gate_refusal_writes_an_audit_row_naming_the_reason_code(): void
    {
        Config::set('real_money.enabled', false);

        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);

        try {
            $service->complete(
                $f['data'],
                'SYNTHETIC-AUDIT',
                $f['reviewer'],
                true,
                'Independently verified final provider evidence.',
            );
            $this->fail('A payout completed while the real-money lane was closed.');
        } catch (PayoutTransferException) {
            // Expected: the refusal is the subject under test.
        }

        // Filtered by the gate's own wording: an approval or batch row from the
        // fixture is a different event and must not be mistaken for a refusal.
        $refusals = AuditLog::query()
            ->where('description', 'like', '%real_money_disabled%')
            ->get();

        $this->assertCount(1, $refusals, 'Exactly one refusal must be recorded, naming the rule that fired.');

        $log = $refusals->first();
        $this->assertSame('payout', $log->action->value);
        $this->assertSame('high', $log->risk_level->value);
        $this->assertSame((int) $f['owner']['user']->getKey(), (int) $log->user_id);
        $this->assertStringContainsString('real_money_disabled', (string) $log->description);
        $this->assertSame('financial_compliance', $log->metadata['gate']);
        $this->assertSame('real_money_disabled', $log->metadata['reason_code']);
        $this->assertSame('prize_payout', $log->metadata['operation']);
        $this->assertSame('payout', $log->metadata['lane']);
        $this->assertSame((int) $f['row']->getKey(), (int) $log->metadata['context_payout_id']);
    }

    public function test_a_replay_after_a_refusal_is_still_refused_by_the_gate(): void
    {
        Config::set('real_money.enabled', false);

        $f = $this->externalFixture();
        $service = app(PayoutTransferService::class);
        $service->transfer($f['data']);

        foreach (['SYNTHETIC-FIRST', 'SYNTHETIC-SECOND'] as $reference) {
            try {
                $service->complete($f['data'], $reference, $f['reviewer'], true, 'Independently verified final provider evidence.');
                $this->fail('A payout completed while the real-money lane was closed.');
            } catch (PayoutTransferException $e) {
                $this->assertSame('gateway_refused', $e->errorCode());
            }
        }

        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('pending', Payment::query()->sole()->status->value);
        // Each refused attempt leaves its own audit row: a replay is never silently
        // swallowed, and the refusal trail shows every attempt.
        $this->assertSame(
            2,
            AuditLog::query()->where('description', 'like', '%real_money_disabled%')->count(),
            'Each refused attempt must leave its own refusal trail.',
        );
    }
}
