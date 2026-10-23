<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\DTOs\Finance\PayoutApprovalData;
use App\DTOs\Finance\PayoutRequestData;
use App\Enums\AuditAction;
use App\Enums\PayoutApprovalStatus;
use App\Enums\PayoutStatus;
use App\Enums\PrizePayoutMethod;
use App\Enums\RiskLevel;
use App\Exceptions\PayoutApprovalException;
use App\Exceptions\PayoutTransferException;
use App\Exceptions\PrizeClaimException;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Models\User;
use App\Services\Payment\PayoutTransferAccess;
use App\Services\Prize\PrizeClaimCompletionService;
use App\Services\Prize\PrizeClaimGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PayoutApprovalService
{
    public const METADATA_KEY = 'approval';

    /**
     * @return array<string, mixed>
     */
    public function request(Payout $payout, PayoutRequestData $data): array
    {
        $maker = app(PayoutTransferAccess::class)->authorize(
            User::query()->find($data->requesterUserId),
            PayoutTransferAccess::REQUEST,
        );

        return DB::transaction(function () use ($payout, $data, $maker): array {
            $row = Payout::query()->lockForUpdate()->find((int) $payout->getKey());
            if (! $row instanceof Payout || $row->trashed()) {
                throw PayoutApprovalException::requestNotFound((int) $payout->getKey());
            }

            $currentMaker = app(PayoutTransferAccess::class)->authorize($maker, PayoutTransferAccess::REQUEST);
            $this->assertRequestFacts($row, $data, (int) $currentMaker->id);

            $rawMeta = $row->getRawOriginal('metadata');
            if ($rawMeta !== null && ! is_array($row->metadata)) {
                $this->refuseShape($row);
            }
            $metadata = is_array($row->metadata) ? $row->metadata : [];
            if ((array_key_exists('claim', $metadata) && ! is_array($metadata['claim']))
                || (array_key_exists(self::METADATA_KEY, $metadata) && ! is_array($metadata[self::METADATA_KEY]))) {
                $this->refuseShape($row);
            }

            $target = $this->normalizeBeneficiary($row, $data->method, $data->beneficiary);
            $claim = is_array($metadata['claim'] ?? null) ? $metadata['claim'] : [];
            if (array_key_exists('payout_method', $claim) && $claim['payout_method'] !== $data->method->value) {
                $this->refuseShape($row);
            }
            if (array_key_exists('beneficiary', $claim) && $claim['beneficiary'] !== null) {
                if (! is_array($claim['beneficiary'])) {
                    $this->refuseShape($row);
                }
                $existingTarget = $claim['beneficiary'];
                ksort($existingTarget);
                if ($existingTarget !== $target) {
                    $this->refuseShape($row);
                }
            }

            $existing = $metadata[self::METADATA_KEY] ?? null;
            if (is_array($existing)) {
                $status = $this->statusOf($existing);
                try {
                    app(PrizeClaimGuard::class)->assertPayableClaim($row);
                    app(PayoutApprovalGuard::class)->assertBeneficiary($row);
                    $currentHash = app(PayoutApprovalGuard::class)->fingerprint($row);
                } catch (PayoutTransferException) {
                    $this->refuseShape($row);
                }

                if ($status === PayoutApprovalStatus::Pending
                    && ($existing['request_key'] ?? null) === $data->requestKey
                    && (int) ($existing['maker_user_id'] ?? 0) === (int) $currentMaker->id
                    && is_string($existing['facts_hash'] ?? null)
                    && hash_equals($currentHash, $existing['facts_hash'])) {
                    $this->syncCaller($payout, $row);

                    return $existing;
                }

                if ($status === PayoutApprovalStatus::Pending) {
                    throw new PayoutApprovalException(
                        sprintf(
                            'Payout #%d already carries a DIFFERENT pending request; amending a lane the checker may be reading is refused.',
                            (int) $row->getKey(),
                        ),
                        PayoutApprovalException::CODE_NOT_PENDING,
                        [
                            'request_id' => (int) $row->getKey(),
                            'current_status' => PayoutApprovalStatus::Pending->value,
                        ],
                    );
                }

                throw PayoutApprovalException::notPending(
                    (int) $row->getKey(),
                    $status?->value ?? 'unknown',
                );
            }

            if ($row->status !== PayoutStatus::Pending || $row->financial_transaction_id !== null) {
                throw PayoutApprovalException::notPending((int) $row->getKey(), $row->status->value);
            }

            $claim['payout_method'] = $data->method->value;
            $claim['beneficiary'] = $target;
            $metadata['claim'] = $claim;
            $row->metadata = $metadata;

            try {
                app(PrizeClaimGuard::class)->assertPayableClaim($row);
                app(PayoutApprovalGuard::class)->assertBeneficiary($row);
                $factsHash = app(PayoutApprovalGuard::class)->fingerprint($row);
            } catch (PayoutTransferException) {
                $this->refuseShape($row);
            }

            $record = [
                'request_key' => $data->requestKey,
                'facts_hash' => $factsHash,
                'status' => PayoutApprovalStatus::Pending->value,
                'maker_user_id' => (int) $currentMaker->id,
                'checker_user_id' => null,
                'reason' => $data->reason,
                'decision_reason' => null,
                'requested_at' => Carbon::now()->toIso8601String(),
                'decided_at' => null,
                'completed_at' => null,
                'request_context' => $data->context,
            ];

            $metadata[self::METADATA_KEY] = $record;
            $row->metadata = $metadata;
            $row->save();

            $this->recordAudit(
                $row,
                (int) $currentMaker->id,
                sprintf('Payout requested by maker #%d (%s)', (int) $currentMaker->id, $data->method->value),
                RiskLevel::Low,
            );

            $this->syncCaller($payout, $row);

            return $record;
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function decide(Payout $payout, PayoutApprovalData $data): array
    {
        return DB::transaction(function () use ($payout, $data): array {
            $row = Payout::query()->lockForUpdate()->find((int) $payout->getKey());
            if (! $row instanceof Payout || $row->trashed()) {
                throw PayoutApprovalException::requestNotFound((int) $payout->getKey());
            }

            $record = $this->recordFor($row);
            if (! is_array($record)) {
                throw PayoutApprovalException::requestNotFound((int) $row->getKey());
            }

            $status = $this->statusOf($record);
            if (($record['decided_at'] ?? null) !== null || in_array($status, [PayoutApprovalStatus::Approved, PayoutApprovalStatus::Rejected, PayoutApprovalStatus::Completed], true)) {
                throw PayoutApprovalException::duplicateDecision((int) $row->getKey());
            }

            if ($status !== PayoutApprovalStatus::Pending || $row->status !== PayoutStatus::Pending || $row->financial_transaction_id !== null) {
                throw PayoutApprovalException::notPending(
                    (int) $row->getKey(),
                    $status?->value ?? 'unknown',
                );
            }

            if (! is_int($record['maker_user_id'] ?? null)) {
                $this->refuseShape($row);
            }

            if ($data->actorUserId === (int) $record['maker_user_id']) {
                throw PayoutApprovalException::makerEqualsChecker((int) $row->getKey(), $data->actorUserId);
            }

            $checker = app(PayoutTransferAccess::class)->authorize(
                User::query()->find($data->actorUserId),
                PayoutTransferAccess::APPROVE,
            );
            app(PayoutTransferAccess::class)->authorize(
                User::query()->find($record['maker_user_id']),
                PayoutTransferAccess::REQUEST,
            );

            if ((int) $checker->id === (int) $row->user_id || (int) $record['maker_user_id'] === (int) $row->user_id) {
                $this->refuseShape($row);
            }

            if (! $data->decisionIsDecisive()) {
                throw PayoutApprovalException::decisionShapeInvalid((int) $row->getKey(), $data->decision->value);
            }

            if ($data->isRejection() && ! $data->reasonIsStated()) {
                throw PayoutApprovalException::rejectionWithoutReason((int) $row->getKey());
            }

            $derivedNow = PayoutRequestData::deriveRequestKey(
                (string) $row->reference_number,
                (string) $row->amount,
                $row->currency,
                (int) $row->user_id,
            );

            $requestKey = (string) ($record['request_key'] ?? '');
            if (! hash_equals($requestKey, $derivedNow) || ! is_string($record['facts_hash'] ?? null)) {
                $this->refuseShape($row);
            }

            try {
                app(PrizeClaimGuard::class)->assertPayableClaim($row);
                app(PayoutApprovalGuard::class)->assertBeneficiary($row);
                $currentHash = app(PayoutApprovalGuard::class)->fingerprint($row);
            } catch (PayoutTransferException) {
                $this->refuseShape($row);
            }

            if (! hash_equals($currentHash, $record['facts_hash'])) {
                $this->refuseShape($row);
            }

            $decided = array_merge($record, [
                'status' => $data->decision->value,
                'checker_user_id' => (int) $checker->id,
                'decision_reason' => $data->reason,
                'decided_at' => Carbon::now()->toIso8601String(),
                'decision_context' => $data->context,
            ]);

            $this->writeRecord($row, $decided);
            $this->recordAudit(
                $row,
                (int) $checker->id,
                sprintf(
                    'Payout %s by checker #%d',
                    $data->decision === PayoutApprovalStatus::Approved ? 'approved' : 'rejected',
                    (int) $checker->id,
                ),
                $data->isApproval() ? RiskLevel::Medium : RiskLevel::Low,
            );

            $this->syncCaller($payout, $row);

            return $decided;
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function complete(Payout $payout, ?int $executorUserId = null): array
    {
        return DB::transaction(function () use ($payout, $executorUserId): array {
            $row = Payout::query()->lockForUpdate()->find((int) $payout->getKey());
            if (! $row instanceof Payout || $row->trashed()) {
                throw PayoutApprovalException::requestNotFound((int) $payout->getKey());
            }

            $record = $this->recordFor($row);
            if (! is_array($record)) {
                throw PayoutApprovalException::requestNotFound((int) $row->getKey());
            }

            if ($row->status !== PayoutStatus::Completed || $row->financial_transaction_id === null) {
                throw PayoutApprovalException::notPending((int) $row->getKey(), 'missing_canonical_money');
            }

            try {
                app(PrizeClaimCompletionService::class)->assertEvidence($row);
            } catch (PrizeClaimException) {
                throw PayoutApprovalException::notPending((int) $row->getKey(), 'missing_canonical_money');
            }

            $status = $this->statusOf($record);
            if ($status === PayoutApprovalStatus::Completed) {
                try {
                    app(PayoutApprovalGuard::class)->assertApproved($row, true);
                } catch (PayoutTransferException) {
                    $this->refuseShape($row);
                }
                app(PrizeClaimCompletionService::class)->complete($row);
                $row->refresh();
                $this->syncCaller($payout, $row);

                return $this->recordFor($row) ?? $record;
            }

            if ($status !== PayoutApprovalStatus::Approved || ! $status?->canTransitionTo(PayoutApprovalStatus::Completed)) {
                throw PayoutApprovalException::notPending(
                    (int) $row->getKey(),
                    $status?->value ?? 'unknown',
                );
            }

            try {
                app(PayoutApprovalGuard::class)->assertApproved($row, false);
            } catch (PayoutTransferException) {
                $this->refuseShape($row);
            }

            $completed = array_merge($record, [
                'status' => PayoutApprovalStatus::Completed->value,
                'completed_at' => Carbon::now()->toIso8601String(),
                'executed_by_user_id' => $executorUserId,
            ]);

            $this->writeRecord($row, $completed);
            $this->recordAudit($row, $executorUserId, 'Payout approval lane completed (money released)', RiskLevel::Low);
            app(PrizeClaimCompletionService::class)->complete($row);
            $row->refresh();
            $this->syncCaller($payout, $row);

            return $this->recordFor($row) ?? $completed;
        }, 3);
    }

    public function statusFor(Payout $payout): ?PayoutApprovalStatus
    {
        $record = $this->recordFor($payout);

        return is_array($record) ? $this->statusOf($record) : null;
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

    public function isApproved(Payout $payout): bool
    {
        if ($this->statusFor($payout) !== PayoutApprovalStatus::Approved) {
            return false;
        }
        try {
            app(PayoutApprovalGuard::class)->assertApproved($payout, false);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function isPending(Payout $payout): bool
    {
        return $this->statusFor($payout) === PayoutApprovalStatus::Pending;
    }

    /**
     * @throws PayoutApprovalException
     */
    public function recordCancellationPetition(Payout $payout, int $petitionerUserId, ?string $reason): array
    {
        return DB::transaction(function () use ($payout, $petitionerUserId, $reason): array {
            $locked = Payout::query()->lockForUpdate()->find((int) $payout->getKey());

            if (! $locked instanceof Payout || $locked->trashed()) {
                throw PayoutApprovalException::requestNotFound((int) $payout->getKey());
            }

            if ($locked->status !== PayoutStatus::Pending) {
                throw PayoutApprovalException::cancellationRefused(
                    (int) $locked->getKey(),
                    $locked->status->value,
                    'only an at-rest (pending) obligation accepts a cancellation petition',
                );
            }

            if ((int) $locked->user_id !== $petitionerUserId) {
                throw PayoutApprovalException::cancellationRefused(
                    (int) $locked->getKey(),
                    'foreign',
                    'a petitioner may only petition their own obligation',
                );
            }

            $lane = $this->recordFor($locked) ?? [];

            if (($lane['status'] ?? null) === 'pending' && ($lane['decision_reason'] ?? null) === null
                && is_string($lane['request_key'] ?? null)) {
                throw PayoutApprovalException::cancellationRefused(
                    (int) $locked->getKey(),
                    'approval_live',
                    'an approval request is already in flight for this obligation',
                );
            }

            $petitionKey = hash('sha256', sprintf('payout-petition:%d:%d', (int) $locked->getKey(), $petitionerUserId));
            $existing = is_array($lane['cancellation_petition'] ?? null) ? $lane['cancellation_petition'] : null;

            if (is_array($existing) && ($existing['petition_key'] ?? null) === $petitionKey) {
                $this->syncCaller($payout, $locked);

                return $existing;
            }

            $petition = [
                'petition_key' => $petitionKey,
                'petitioned_by_user_id' => $petitionerUserId,
                'reason' => $reason,
                'petitioned_at' => Carbon::now()->toIso8601String(),
                'outcome' => 'pending',
            ];

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $record = is_array($metadata[self::METADATA_KEY] ?? null) ? $metadata[self::METADATA_KEY] : [];
            $record['cancellation_petition'] = $petition;
            $metadata[self::METADATA_KEY] = $record;
            $locked->metadata = $metadata;
            $locked->save();

            $this->recordAudit(
                $locked,
                $petitionerUserId,
                sprintf('Cancellation petition recorded by player #%d', $petitionerUserId),
                RiskLevel::Medium,
            );

            $this->syncCaller($payout, $locked);

            return $petition;
        }, 3);
    }

    private function assertRequestFacts(Payout $row, PayoutRequestData $data, int $makerId): void
    {
        $expectedKey = PayoutRequestData::deriveRequestKey(
            (string) $row->reference_number,
            (string) $row->amount,
            $row->currency,
            (int) $row->user_id,
        );

        if ($data->prizeReference !== (string) $row->reference_number
            || (int) $data->beneficiaryUserId !== (int) $row->user_id
            || $makerId === (int) $row->user_id
            || $data->currency !== $row->currency
            || strlen($data->amount) > 21
            || preg_match('/^\d+\.\d{2}$/D', $data->amount) !== 1
            || bccomp($data->amount, '0', 2) <= 0
            || bccomp($data->amount, (string) $row->amount, 2) !== 0
            || ! $data->method->supportsCurrency($row->currency)
            || ! hash_equals($expectedKey, $data->requestKey)) {
            $this->refuseShape($row);
        }
    }

    private function normalizeBeneficiary(Payout $row, PrizePayoutMethod $method, ?array $target): array
    {
        if ($method === PrizePayoutMethod::Wallet) {
            if ($target !== null && $target !== []) {
                $this->refuseShape($row);
            }

            return [];
        }

        $required = $method === PrizePayoutMethod::BankTransfer
            ? ['bank_name', 'account_name', 'account_number']
            : ['payee_name', 'address'];
        $allowed = array_merge($required, ['bank_code', 'branch', 'routing_number', 'iban', 'swift', 'country']);

        if (! is_array($target)
            || array_diff(array_keys($target), $allowed) !== []
            || array_diff($required, array_keys($target)) !== []
            || array_any($target, fn ($v) => ! is_string($v) || trim($v) === '' || strlen($v) > 500 || preg_match('/[\x00-\x1f\x7f]/', $v) === 1)) {
            $this->refuseShape($row);
        }

        ksort($target);

        return $target;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function statusOf(array $record): ?PayoutApprovalStatus
    {
        $status = $record['status'] ?? null;

        return is_string($status) ? PayoutApprovalStatus::tryFrom($status) : null;
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

    private function syncCaller(Payout $caller, Payout $fresh): void
    {
        $caller->setRawAttributes($fresh->getAttributes(), true);
        $caller->syncChanges();
    }

    private function refuseShape(Payout $row): never
    {
        throw new PayoutApprovalException(
            sprintf('Payout approval request #%d: obligation, beneficiary, currency or authority facts are incompatible.', (int) $row->getKey()),
            PayoutApprovalException::CODE_DECISION_SHAPE,
            ['request_id' => (int) $row->getKey()],
        );
    }

    private function recordAudit(Payout $payout, ?int $userId, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => $userId,
            'action' => AuditAction::Payout,
            'risk_level' => $riskLevel,
            'auditable_type' => Payout::class,
            'auditable_id' => $payout->getKey(),
            'description' => sprintf(
                '%s on payout #%d (%s %s)',
                $description,
                (int) $payout->getKey(),
                (string) $payout->amount,
                $payout->currency->value,
            ),
            'metadata' => [
                'payout_id' => (int) $payout->getKey(),
                'payout_reference' => (string) $payout->reference_number,
                'amount' => (string) $payout->amount,
                'currency' => $payout->currency->value,
                'lane' => self::METADATA_KEY,
            ],
        ]);

        $log->save();
    }
}
