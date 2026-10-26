<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PayoutTransferData;
use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PayoutStatus;
use App\Enums\PrizePayoutMethod;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Exceptions\PayoutTransferException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Services\Compliance\FinancialComplianceGateService;
use App\Services\Finance\ExternalPayoutCompletionService;
use App\Services\Finance\PayoutApprovalGuard;
use App\Services\Finance\PayoutApprovalService;
use App\Services\Prize\PrizeClaimCompletionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payment-abstraction lane for external prize payouts (BankTransfer, Cheque).
 */
class PayoutTransferService
{
    public const METADATA_KEY = 'transfer';

    /**
     * Execute (open) a transfer lane for a payout.
     *
     * @return array<string, mixed>
     *
     * @throws PayoutTransferException
     */
    public function transfer(PayoutTransferData $data): array
    {
        if ($data->method === PrizePayoutMethod::Wallet) {
            throw PayoutTransferException::gatewayRefused(
                $data->payoutReference,
                'wallet-method payouts settle on the internal wallet lane, never on the external transfer lane',
            );
        }

        if (! $data->amountIsWellFormed()) {
            throw PayoutTransferException::gatewayRefused(
                $data->payoutReference,
                sprintf('transfer amount [%s] is not a well-formed 2-decimal string', $data->amount),
            );
        }

        return DB::transaction(function () use ($data): array {
            $payout = Payout::query()->lockForUpdate()
                ->where('reference_number', $data->payoutReference)
                ->first();

            if (! $payout instanceof Payout) {
                throw PayoutTransferException::missingPayout($data->payoutReference);
            }

            $guard = app(PayoutTransferGuard::class);
            $guard->identity($payout, $data);
            $factsHash = app(PayoutApprovalGuard::class)->fingerprint($payout);
            $accountScope = $guard->account($payout);

            $existing = $this->recordFor($payout);

            if (is_array($existing) && ($existing['idempotency_key'] ?? null) === $data->idempotencyKey) {
                $guard->payment($payout, $existing);
                if (in_array($existing['lane_status'] ?? null, ['completed', 'failed'], true)) {
                    app(PayoutEvidenceService::class)->assertAttached($payout, $existing);
                }

                return $existing;
            }

            if (is_array($existing)) {
                throw PayoutTransferException::duplicateTransfer($data->idempotencyKey);
            }

            if (bccomp(bcadd($data->amount, '0.00', 2), (string) $payout->amount, 2) !== 0) {
                throw PayoutTransferException::amountDrift(
                    $data->payoutReference,
                    (string) $payout->amount,
                    bcadd($data->amount, '0.00', 2),
                );
            }

            if (! in_array($payout->status, [PayoutStatus::Pending, PayoutStatus::Processing], true)) {
                throw PayoutTransferException::payoutStateForbids(
                    $data->payoutReference,
                    $payout->status->value,
                );
            }

            $method = $this->methodOf($data->method);
            $gateway = $this->gatewayOf($data->method);

            $payment = new Payment;
            $payment->fill([
                'reference_number' => $this->derivePaymentReference($data->idempotencyKey),
                'user_id' => $data->beneficiaryUserId,
                'payable_type' => Payout::class,
                'payable_id' => (int) $payout->getKey(),
                'method' => $method->value,
                'amount' => bcadd($data->amount, '0.00', 2),
                'fee' => '0.00',
                'gateway' => $gateway,
                'gateway_reference' => null,
                'metadata' => [
                    'payout_transfer' => true,
                    'batch_key' => $data->batchKey,
                    'idempotency_key' => $data->idempotencyKey,
                    'account_scope' => $accountScope,
                    'context' => $data->context,
                ],
            ]);
            $payment->currency = $data->currency;
            $payment->status = PaymentStatus::Pending;
            $payment->save();

            $payout->status = PayoutStatus::Processing;

            $record = [
                'idempotency_key' => $data->idempotencyKey,
                'batch_key' => $data->batchKey,
                'payout_reference' => $data->payoutReference,
                'payment_reference' => (string) $payment->reference_number,
                'method' => $data->method->value,
                'amount' => bcadd($data->amount, '0.00', 2),
                'currency' => $data->currency->value,
                'facts_hash' => $factsHash,
                'account_scope' => $accountScope,
                'gateway_reference' => null,
                'lane_status' => 'open',
                'opened_at' => Carbon::now()->toIso8601String(),
                'completed_at' => null,
            ];

            $this->writeRecord($payout, $record);

            $this->recordAudit($payout, sprintf(
                'Payout transfer opened: %s %s via %s (payment %s, batch %s).',
                bcadd($data->amount, '0.00', 2),
                $data->currency->value,
                $data->method->value,
                $payment->reference_number,
                $data->batchKey,
            ), RiskLevel::High);

            return $record;
        });
    }

    /**
     * Confirm an external transfer settled independently.
     *
     * @return array<string, mixed>
     */
    public function complete(
        PayoutTransferData $data,
        string $gatewayReference,
        ?User $actor = null,
        bool $evidenceVerified = false,
        ?string $note = null,
        ?int $evidenceId = null,
    ): array {
        $current = app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::REVIEW);

        $refusal = null;
        try {
            return DB::transaction(function () use ($data, $gatewayReference, $current, $evidenceVerified, $note, $evidenceId, &$refusal): array {
            $current = app(PayoutTransferAccess::class)->authorize($current, PayoutTransferAccess::REVIEW);

            $payout = Payout::query()->lockForUpdate()
                ->where('reference_number', $data->payoutReference)
                ->first();

            if (! $payout instanceof Payout) {
                throw PayoutTransferException::missingPayout($data->payoutReference);
            }

            $guard = app(PayoutTransferGuard::class);
            $guard->identity($payout, $data, false);

            $makerId = (int) ($payout->metadata['approval']['maker_user_id'] ?? 0);
            if (! $evidenceVerified
                || (int) $current->id === $makerId
                || (int) $current->id === (int) $payout->user_id
                || ! is_string($note)
                || mb_strlen(trim($note)) < 15
                || mb_strlen(trim($note)) > 1000
                || $gatewayReference === ''
                || trim($gatewayReference) !== $gatewayReference
                || strlen($gatewayReference) > 255
                || preg_match('/[\x00-\x1f\x7f]/', $gatewayReference) === 1
            ) {
                $guard->refuse($payout);
            }

            $record = $this->recordFor($payout);

            if (! is_array($record) || ($record['idempotency_key'] ?? null) !== $data->idempotencyKey) {
                throw PayoutTransferException::gatewayRefused(
                    $data->payoutReference,
                    'no open transfer lane with this anchor exists; completion has nothing to land on',
                );
            }

            if (($record['lane_status'] ?? null) === 'completed') {
                if ($evidenceId !== null && $evidenceId !== ($record['evidence_id'] ?? null)) {
                    throw PayoutTransferException::duplicateTransfer($data->idempotencyKey);
                }
                if (($record['gateway_reference'] ?? null) !== $gatewayReference) {
                    $guard->refuse($payout);
                }
                $guard->payment($payout, $record);
                app(PayoutEvidenceService::class)->consume($payout, $current, 'completed', $gatewayReference, $evidenceId, true);

                return $record;
            }

            if (($record['lane_status'] ?? null) !== 'open') {
                throw PayoutTransferException::gatewayRefused(
                    $data->payoutReference,
                    sprintf('transfer lane is %s; only an open lane completes', (string) ($record['lane_status'] ?? 'unknown')),
                );
            }

            if (bccomp(bcadd($data->amount, '0.00', 2), (string) ($record['amount'] ?? ''), 2) !== 0) {
                throw PayoutTransferException::amountDrift(
                    $data->payoutReference,
                    (string) ($record['amount'] ?? ''),
                    bcadd($data->amount, '0.00', 2),
                );
            }

            $beneficiary = User::query()->lockForUpdate()->find($payout->user_id);
            if (! $beneficiary instanceof User) {
                $guard->refuse($payout);
            }
            try {
                app(FinancialComplianceGateService::class)->assertPayoutAllowed(
                    $beneficiary,
                    (string) $payout->amount,
                    $payout->currency->value,
                    'prize_payout',
                    ['payout_id' => (int) $payout->id],
                );
            } catch (FinancialException $failure) {
                $refusal = [$beneficiary, $failure, (int) $payout->id];
                throw PayoutTransferException::gatewayRefused($payout->reference_number,
                    $failure->errorCode() ?? 'payout_compliance_unavailable');
            }
            if (! $beneficiary->kycDocuments()->where('status', 'approved')->exists()
                && ! $beneficiary->kycDocuments()->where('status', 'verified')->exists()) {
                $guard->refuse($payout);
            }

            $payment = $guard->payment($payout, $record);
            $consumedEvidenceId = app(PayoutEvidenceService::class)->consume($payout, $current, 'completed', $gatewayReference, $evidenceId, false);
            app(PayoutTransferReferenceService::class)->bind($payout, $payment, $gatewayReference);

            $payment->status = PaymentStatus::Captured;
            $payment->gateway_reference = $gatewayReference;
            $payment->captured_at = Carbon::now();
            $payment->save();

            $tx = app(ExternalPayoutCompletionService::class)->post($payout);

            $payout->financial_transaction_id = (int) $tx->getKey();
            $payout->status = PayoutStatus::Completed;
            $payout->processed_at = Carbon::now();
            $payout->save();

            $record = array_merge($record, [
                'lane_status' => 'completed',
                'gateway_reference' => $gatewayReference,
                'completed_at' => Carbon::now()->toIso8601String(),
                'reviewed_by' => (int) $current->id,
                'evidence_id' => $consumedEvidenceId,
                'note_sha256' => hash('sha256', trim($note)),
            ]);
            $this->writeRecord($payout, $record);

            app(PayoutApprovalService::class)->complete($payout);
            app(PrizeClaimCompletionService::class)->complete($payout);

            $this->audit($payout, $current, sprintf(
                'Payout transfer COMPLETED: %s %s settled to beneficiary #%d (gateway ref %s).',
                (string) ($record['amount'] ?? ''),
                (string) ($record['currency'] ?? ''),
                $data->beneficiaryUserId,
                $gatewayReference,
            ), [
                'evidence_id' => $consumedEvidenceId,
                'note_sha256' => hash('sha256', trim($note)),
            ]);

            return $record;
            }, 3);
        } catch (PayoutTransferException $failure) {
            if ($refusal !== null) {
                // The money transaction rolled back, including its attempted audit.
                // Re-emit only the gate's redacted refusal, never money writes or a recheck.
                app(FinancialComplianceGateService::class)->auditPayoutRefusalAfterRollback(...$refusal);
            }
            throw $failure;
        }
    }

    /**
     * Mark an external transfer failed with verified evidence.
     *
     * @return array<string, mixed>
     */
    public function fail(
        PayoutTransferData $data,
        string $reason,
        ?User $actor = null,
        bool $evidenceVerified = false,
        ?int $evidenceId = null,
    ): array {
        $current = app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::REVIEW);

        return DB::transaction(function () use ($data, $reason, $current, $evidenceVerified, $evidenceId): array {
            $current = app(PayoutTransferAccess::class)->authorize($current, PayoutTransferAccess::REVIEW);

            $payout = Payout::query()->lockForUpdate()
                ->where('reference_number', $data->payoutReference)
                ->first();

            if (! $payout instanceof Payout) {
                throw PayoutTransferException::missingPayout($data->payoutReference);
            }

            $guard = app(PayoutTransferGuard::class);
            $guard->identity($payout, $data);

            $makerId = (int) ($payout->metadata['approval']['maker_user_id'] ?? 0);
            if (! $evidenceVerified
                || (int) $current->id === $makerId
                || (int) $current->id === (int) $payout->user_id
                || mb_strlen(trim($reason)) < 15
                || mb_strlen(trim($reason)) > 1000
            ) {
                $guard->refuse($payout);
            }

            $record = $this->recordFor($payout);

            if (! is_array($record) || ($record['idempotency_key'] ?? null) !== $data->idempotencyKey) {
                throw PayoutTransferException::gatewayRefused(
                    $data->payoutReference,
                    'no open transfer lane with this anchor exists; nothing to fail',
                );
            }

            if (($record['lane_status'] ?? null) === 'failed') {
                $guard->payment($payout, $record);
                app(PayoutEvidenceService::class)->consume($payout, $current, 'failed', null, $evidenceId, true);

                return $record;
            }

            if (($record['lane_status'] ?? null) !== 'open') {
                throw PayoutTransferException::gatewayRefused(
                    $data->payoutReference,
                    'a completed transfer cannot be failed; reversals own money coming back',
                );
            }

            $payment = $guard->payment($payout, $record);
            $consumedEvidenceId = app(PayoutEvidenceService::class)->consume($payout, $current, 'failed', null, $evidenceId, false);

            $payment->status = PaymentStatus::Failed;
            $payment->failed_at = Carbon::now();
            $payment->failure_reason = $reason;
            $payment->save();

            $payout->status = PayoutStatus::Failed;
            $payout->failed_at = Carbon::now();
            $payout->save();

            $record = array_merge($record, [
                'lane_status' => 'failed',
                'failure_reason' => $reason,
                'completed_at' => null,
                'reviewed_by' => (int) $current->id,
                'evidence_id' => $consumedEvidenceId,
                'note_sha256' => hash('sha256', trim($reason)),
            ]);
            $this->writeRecord($payout, $record);

            $this->audit($payout, $current, sprintf(
                'Payout transfer FAILED: %s %s (%s).',
                (string) ($record['amount'] ?? ''),
                (string) ($record['currency'] ?? ''),
                $reason,
            ), [
                'evidence_id' => $consumedEvidenceId,
                'note_sha256' => hash('sha256', trim($reason)),
            ]);

            return $record;
        });
    }

    /**
     * Separate authorized inspection of external destination details with append-only audit.
     *
     * @return array<string, mixed>
     */
    public function destinationForReview(Payout $payout, ?User $actor): array
    {
        $current = app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::REVIEW);

        return DB::transaction(function () use ($payout, $current): array {
            $current = app(PayoutTransferAccess::class)->authorize($current, PayoutTransferAccess::REVIEW);
            $row = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            $guard = app(PayoutApprovalGuard::class);
            $guard->assertApproved($row, $row->status === PayoutStatus::Completed);

            if ((int) $current->id === (int) $row->user_id || $guard->method($row) === PrizePayoutMethod::Wallet) {
                app(PayoutTransferGuard::class)->refuse($row);
            }

            $beneficiary = $guard->beneficiary($row);
            if (! is_array($beneficiary)) {
                app(PayoutTransferGuard::class)->refuse($row);
            }

            $this->audit($row, $current, 'Authorized operator inspected original external prize destination.');

            return $beneficiary;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function recordFor(Payout $payout): ?array
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $record = $metadata[self::METADATA_KEY] ?? null;
        if (array_key_exists(self::METADATA_KEY, $metadata) && ! is_array($record)) {
            app(PayoutTransferGuard::class)->refuse($payout);
        }

        return is_array($record) ? $record : null;
    }

    public function hasOpenLane(Payout $payout): bool
    {
        $record = $this->recordFor($payout);

        return is_array($record) && ($record['lane_status'] ?? null) === 'open';
    }

    private function methodOf(PrizePayoutMethod $method): PaymentMethod
    {
        return match ($method) {
            PrizePayoutMethod::BankTransfer => PaymentMethod::BankTransfer,
            PrizePayoutMethod::Cheque => PaymentMethod::Manual,
            default => throw PayoutTransferException::gatewayRefused(
                'unknown',
                sprintf('payout transfer lane cannot execute method [%s]', $method->value),
            ),
        };
    }

    private function gatewayOf(PrizePayoutMethod $method): string
    {
        return match ($method) {
            PrizePayoutMethod::BankTransfer => 'bank_transfer',
            PrizePayoutMethod::Cheque => 'manual',
            default => 'manual',
        };
    }

    private function derivePaymentReference(string $idempotencyKey): string
    {
        return 'PT-'.strtoupper(substr($idempotencyKey, 0, 20));
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function writeRecord(Payout $payout, array $record): void
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $metadata[self::METADATA_KEY] = $record;

        $payout->metadata = $metadata;
        $payout->save();
    }

    public function audit(Payout $payout, User $actor, string $description, array $metadata = []): void
    {
        $log = new AuditLog;
        $log->fill([
            'user_id' => (int) $actor->getKey(),
            'action' => AuditAction::Payout,
            'risk_level' => RiskLevel::High,
            'auditable_type' => Payout::class,
            'auditable_id' => (int) $payout->getKey(),
            'description' => $description,
            'metadata' => array_merge([
                'payout_id' => (int) $payout->getKey(),
                'payout_reference' => (string) $payout->reference_number,
                'amount' => (string) $payout->amount,
                'currency' => $payout->currency->value,
            ], $metadata),
        ]);
        $log->save();
    }

    private function recordAudit(Payout $payout, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => $riskLevel,
            'auditable_type' => Payout::class,
            'auditable_id' => $payout->getKey(),
            'description' => $description,
            'metadata' => [
                'payout_id' => (int) $payout->getKey(),
                'payout_reference' => (string) $payout->reference_number,
                'lane' => self::METADATA_KEY,
                'action' => 'payout_transfer',
            ],
        ]);

        $log->save();
    }
}
