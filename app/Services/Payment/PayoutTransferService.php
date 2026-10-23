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
use App\Exceptions\PayoutTransferException;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Services\Finance\ExternalPayoutCompletionService;
use App\Services\Finance\PayoutApprovalGuard;
use App\Services\Finance\PayoutApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PayoutTransferService
{
    public const METADATA_KEY = 'transfer';

    /**
     * @return array<string, mixed>
     *
     * @throws PayoutTransferException
     */
    public function transfer(PayoutTransferData $data): array
    {
        if (DB::transactionLevel() > 0) {
            throw PayoutTransferException::alreadyRunning((int) DB::transactionLevel());
        }

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

            if (! $payout instanceof Payout || $payout->trashed()) {
                throw PayoutTransferException::missingPayout($data->payoutReference);
            }

            $guard = app(PayoutTransferGuard::class);
            $rawMeta = $payout->getRawOriginal('metadata');
            if ($rawMeta !== null && ! is_array($payout->metadata)) {
                $guard->refuse($payout);
            }
            $metadata = is_array($payout->metadata) ? $payout->metadata : [];
            if (array_key_exists(self::METADATA_KEY, $metadata) && ! is_array($metadata[self::METADATA_KEY])) {
                $guard->refuse($payout);
            }

            $guard->identity($payout, $data);

            $existing = $metadata[self::METADATA_KEY] ?? null;
            if (is_array($existing)) {
                if (($existing['idempotency_key'] ?? null) !== $data->idempotencyKey
                    || ($existing['batch_key'] ?? null) !== $data->batchKey
                    || ($existing['payout_reference'] ?? null) !== $data->payoutReference
                    || ($existing['method'] ?? null) !== $data->method->value
                    || ($existing['currency'] ?? null) !== $data->currency->value
                    || bccomp((string) ($existing['amount'] ?? ''), (string) $payout->amount, 2) !== 0) {
                    throw PayoutTransferException::duplicateTransfer($data->idempotencyKey);
                }

                $guard->payment($payout, $existing);
                $laneStatus = $existing['lane_status'] ?? null;

                if ($laneStatus === 'open' && $payout->status === PayoutStatus::Processing && $payout->financial_transaction_id === null) {
                    return $existing;
                }
                if ($laneStatus === 'failed' && $payout->status === PayoutStatus::Failed && $payout->financial_transaction_id === null) {
                    app(PayoutEvidenceService::class)->assertAttached($payout, $existing);

                    return $existing;
                }
                if ($laneStatus === 'completed' && $payout->status === PayoutStatus::Completed && $payout->financial_transaction_id !== null) {
                    app(PayoutEvidenceService::class)->assertAttached($payout, $existing);

                    return $existing;
                }

                $guard->refuse($payout);
            }

            if ($payout->status !== PayoutStatus::Pending
                || $payout->financial_transaction_id !== null
                || Payment::withTrashed()->where('payable_type', Payout::class)->where('payable_id', $payout->id)->exists()) {
                $guard->refuse($payout);
            }

            $method = $this->methodOf($data->method);
            $gateway = $guard->gateway($payout);
            $scope = $guard->account($payout);
            $factsHash = app(PayoutApprovalGuard::class)->fingerprint($payout);

            $payment = new Payment;
            $payment->fill([
                'reference_number' => $this->derivePaymentReference($data->idempotencyKey),
                'user_id' => (int) $payout->user_id,
                'payable_type' => Payout::class,
                'payable_id' => (int) $payout->getKey(),
                'method' => $method->value,
                'amount' => (string) $payout->amount,
                'fee' => '0.00',
                'gateway' => $gateway,
                'gateway_reference' => null,
                'metadata' => [
                    'payout_transfer' => true,
                    'batch_key' => $data->batchKey,
                    'idempotency_key' => $data->idempotencyKey,
                    'account_scope' => $scope,
                    'facts_hash' => $factsHash,
                ],
            ]);
            $payment->currency = $payout->currency;
            $payment->status = PaymentStatus::Pending;
            $payment->save();

            $record = [
                'idempotency_key' => $data->idempotencyKey,
                'batch_key' => $data->batchKey,
                'payout_reference' => $payout->reference_number,
                'payment_reference' => (string) $payment->reference_number,
                'method' => $data->method->value,
                'amount' => (string) $payout->amount,
                'currency' => $payout->currency->value,
                'account_scope' => $scope,
                'facts_hash' => $factsHash,
                'gateway_reference' => null,
                'lane_status' => 'open',
                'opened_at' => Carbon::now()->toIso8601String(),
                'completed_at' => null,
            ];

            $payout->status = PayoutStatus::Processing;
            $this->writeRecord($payout, $record);

            $this->audit($payout, null, sprintf(
                'Payout transfer record opened: %s %s via %s (payment %s).',
                (string) $payout->amount,
                $payout->currency->value,
                $data->method->value,
                $payment->reference_number,
            ));

            return $record;
        }, 3);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PayoutTransferException
     */
    public function complete(
        PayoutTransferData $data,
        string $gatewayReference,
        ?User $actor = null,
        bool $evidenceVerified = false,
        string $note = '',
        ?int $evidenceId = null,
    ): array {
        $reviewer = app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::REVIEW);

        if (! $evidenceVerified
            || mb_strlen(trim($note)) < 15
            || mb_strlen($note) > 1000
            || $gatewayReference === ''
            || trim($gatewayReference) !== $gatewayReference
            || strlen($gatewayReference) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $gatewayReference) === 1) {
            throw PayoutTransferException::gatewayRefused(
                $data->payoutReference,
                'Independent confirmation, verified evidence, note and exact provider reference are required.',
            );
        }

        return DB::transaction(function () use ($data, $gatewayReference, $reviewer, $note, $evidenceId): array {
            $current = app(PayoutTransferAccess::class)->authorize($reviewer, PayoutTransferAccess::REVIEW);
            $payout = Payout::query()->lockForUpdate()
                ->where('reference_number', $data->payoutReference)
                ->first();

            if (! $payout instanceof Payout || $payout->trashed()) {
                throw PayoutTransferException::missingPayout($data->payoutReference);
            }

            $guard = app(PayoutTransferGuard::class);
            $guard->identity($payout, $data);

            $approval = $payout->metadata['approval'] ?? [];
            if ((int) $current->id === (int) $payout->user_id || (int) $current->id === (int) ($approval['maker_user_id'] ?? 0)) {
                $guard->refuse($payout);
            }

            $record = $this->recordFor($payout);
            if (! is_array($record) || ($record['idempotency_key'] ?? null) !== $data->idempotencyKey) {
                $guard->refuse($payout);
            }

            $payment = $guard->payment($payout, $record);

            if (($record['lane_status'] ?? null) === 'completed') {
                if ($payout->status !== PayoutStatus::Completed
                    || $payout->financial_transaction_id === null
                    || ($record['gateway_reference'] ?? null) !== $gatewayReference
                    || ($evidenceId !== null && (int) ($record['evidence_id'] ?? 0) !== $evidenceId)) {
                    $guard->refuse($payout);
                }

                app(PayoutEvidenceService::class)->consume($payout, $current, 'completed', $gatewayReference, $evidenceId ?? ($record['evidence_id'] ?? null), true);
                app(PayoutEvidenceService::class)->assertAttached($payout, $record);
                app(OutboundProviderReferenceService::class)->assertBound($payout, $payment->gateway, $record['account_scope'], $gatewayReference, $payment);
                app(PayoutApprovalService::class)->complete($payout, (int) $current->id);

                return $record;
            }

            if (($record['lane_status'] ?? null) !== 'open' || $payout->status !== PayoutStatus::Processing || $payout->financial_transaction_id !== null) {
                $guard->refuse($payout);
            }

            $consumedEvidenceId = app(PayoutEvidenceService::class)->consume($payout, $current, 'completed', $gatewayReference, $evidenceId, false);
            app(PayoutTransferReferenceService::class)->bind($payout, $payment, $gatewayReference);
            $tx = app(ExternalPayoutCompletionService::class)->post($payout);

            $payment->status = PaymentStatus::Captured;
            $payment->gateway_reference = $gatewayReference;
            $payment->captured_at = Carbon::now();
            $payment->save();

            $payout->financial_transaction_id = $tx->id;
            $payout->status = PayoutStatus::Completed;
            $payout->processed_at = Carbon::now();

            $record = array_merge($record, [
                'lane_status' => 'completed',
                'gateway_reference' => $gatewayReference,
                'evidence_id' => $consumedEvidenceId,
                'reviewed_by' => (int) $current->id,
                'review_note_sha256' => hash('sha256', $note),
                'completed_at' => Carbon::now()->toIso8601String(),
            ]);

            $this->writeRecord($payout, $record);
            app(PayoutApprovalService::class)->complete($payout, (int) $current->id);

            $this->audit($payout, $current, 'Independently confirmed external prize transfer completed.', [
                'evidence_id' => $consumedEvidenceId,
                'note_sha256' => hash('sha256', $note),
                'financial_transaction_id' => (int) $tx->id,
            ]);

            return $record;
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function fail(
        PayoutTransferData $data,
        string $reason,
        ?User $actor = null,
        bool $evidenceVerified = false,
        ?int $evidenceId = null,
    ): array {
        $reviewer = app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::REVIEW);

        if (! $evidenceVerified || mb_strlen(trim($reason)) < 15 || mb_strlen($reason) > 1000) {
            throw PayoutTransferException::gatewayRefused(
                $data->payoutReference,
                'Independent final refusal attestation and note are required.',
            );
        }

        return DB::transaction(function () use ($data, $reason, $reviewer, $evidenceId): array {
            $current = app(PayoutTransferAccess::class)->authorize($reviewer, PayoutTransferAccess::REVIEW);
            $payout = Payout::query()->lockForUpdate()
                ->where('reference_number', $data->payoutReference)
                ->first();

            if (! $payout instanceof Payout || $payout->trashed()) {
                throw PayoutTransferException::missingPayout($data->payoutReference);
            }

            $guard = app(PayoutTransferGuard::class);
            $guard->identity($payout, $data);

            $approval = $payout->metadata['approval'] ?? [];
            if ((int) $current->id === (int) $payout->user_id || (int) $current->id === (int) ($approval['maker_user_id'] ?? 0)) {
                $guard->refuse($payout);
            }

            $record = $this->recordFor($payout);
            if (! is_array($record) || ($record['idempotency_key'] ?? null) !== $data->idempotencyKey) {
                $guard->refuse($payout);
            }

            $payment = $guard->payment($payout, $record);

            if (($record['lane_status'] ?? null) === 'failed') {
                if ($payout->status !== PayoutStatus::Failed
                    || $payout->financial_transaction_id !== null
                    || ($evidenceId !== null && (int) ($record['evidence_id'] ?? 0) !== $evidenceId)) {
                    $guard->refuse($payout);
                }

                app(PayoutEvidenceService::class)->consume($payout, $current, 'failed', null, $evidenceId ?? ($record['evidence_id'] ?? null), true);
                app(PayoutEvidenceService::class)->assertAttached($payout, $record);

                return $record;
            }

            if (($record['lane_status'] ?? null) !== 'open' || $payout->status !== PayoutStatus::Processing || $payout->financial_transaction_id !== null) {
                $guard->refuse($payout);
            }

            $consumedEvidenceId = app(PayoutEvidenceService::class)->consume($payout, $current, 'failed', null, $evidenceId, false);

            $payment->status = PaymentStatus::Failed;
            $payment->failed_at = Carbon::now();
            $payment->failure_reason = 'independently_confirmed_final_refusal';
            $payment->save();

            $payout->status = PayoutStatus::Failed;
            $payout->failed_at = Carbon::now();
            $payout->failure_reason = 'independently_confirmed_final_refusal';

            $record = array_merge($record, [
                'lane_status' => 'failed',
                'failure_reason' => 'independently_confirmed_final_refusal',
                'evidence_id' => $consumedEvidenceId,
                'reviewed_by' => (int) $current->id,
                'review_note_sha256' => hash('sha256', $reason),
                'completed_at' => null,
            ]);
            $this->writeRecord($payout, $record);

            $this->audit($payout, $current, 'Independently confirmed external prize transfer final refusal.', [
                'evidence_id' => $consumedEvidenceId,
                'note_sha256' => hash('sha256', $reason),
            ]);

            return $record;
        }, 3);
    }

    public function destinationForReview(Payout $payout, ?User $actor): array
    {
        $reviewer = app(PayoutTransferAccess::class)->authorize($actor, PayoutTransferAccess::REVIEW);

        return DB::transaction(function () use ($payout, $reviewer): array {
            $row = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            $current = app(PayoutTransferAccess::class)->authorize($reviewer, PayoutTransferAccess::REVIEW);
            if ((int) $row->user_id === (int) $current->id) {
                app(PayoutTransferGuard::class)->refuse($row);
            }
            app(PayoutApprovalGuard::class)->assertApproved($row, $row->status === PayoutStatus::Completed);
            $details = app(PayoutApprovalGuard::class)->beneficiary($row);
            $this->audit($row, $current, 'Authorized operator inspected original external prize destination.');

            return $details;
        }, 3);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function recordFor(Payout $payout): ?array
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $record = $metadata[self::METADATA_KEY] ?? null;

        return is_array($record) ? $record : null;
    }

    public function hasOpenLane(Payout $payout): bool
    {
        $record = $this->recordFor($payout);

        return is_array($record) && ($record['lane_status'] ?? null) === 'open';
    }

    public function audit(Payout $payout, ?User $actor, string $description, array $extra = []): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => $actor?->id,
            'action' => AuditAction::Payout,
            'risk_level' => RiskLevel::High,
            'auditable_type' => Payout::class,
            'auditable_id' => $payout->getKey(),
            'description' => $description,
            'metadata' => array_merge([
                'payout_id' => (int) $payout->getKey(),
                'payout_reference' => (string) $payout->reference_number,
                'lane' => self::METADATA_KEY,
                'action' => 'payout_transfer',
            ], $extra),
        ]);

        $log->save();
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

    private function derivePaymentReference(string $idempotencyKey): string
    {
        return 'PT-'.strtoupper(substr(hash('sha256', 'payout-transfer-payment:'.$idempotencyKey), 0, 61));
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
}
