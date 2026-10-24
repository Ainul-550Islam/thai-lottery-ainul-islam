<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\Enums\AuditAction;
use App\Enums\BetStatus;
use App\Enums\PrizeClaimStatus;
use App\Enums\RiskLevel;
use App\Exceptions\PrizeClaimException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Payout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PrizeClaimService
{
    public function __construct(
        private readonly ClaimWindowService $windowService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function claim(int $betId, int $claimantUserId): array
    {
        return DB::transaction(function () use ($betId, $claimantUserId): array {
            $bet = Bet::withTrashed()->lockForUpdate()->find($betId);

            if (! $bet instanceof Bet || $bet->trashed()) {
                throw PrizeClaimException::betNotClaimable($betId, 'missing');
            }

            if ($bet->status !== BetStatus::Won || bccomp((string) $bet->actual_payout, '0.00', 2) <= 0) {
                throw PrizeClaimException::betNotClaimable($betId, $bet->status?->value ?? 'unknown');
            }

            if ((int) $bet->user_id !== $claimantUserId) {
                throw PrizeClaimException::notBetOwner($betId, $claimantUserId);
            }

            $guard = app(PrizeClaimGuard::class);
            $payout = $guard->resolve($bet, true);

            if (! $payout instanceof Payout) {
                throw PrizeClaimException::noWinningBet($betId, 'has no canonical payout obligation recorded');
            }

            $guard->assertClaimant($payout, $claimantUserId);

            $metadata = is_array($payout->metadata) ? $payout->metadata : [];
            $existing = $metadata['claim'] ?? null;

            if (array_key_exists('claim', $metadata) && ! is_array($existing)) {
                $guard->refuse($payout);
            }

            if (is_array($existing) && (
                array_key_exists('claimant_user_id', $existing)
                || array_key_exists('claimed_at', $existing)
                || array_key_exists('status', $existing)
            )) {
                $existingClaimant = isset($existing['claimant_user_id']) ? (int) $existing['claimant_user_id'] : 0;
                if ($existingClaimant !== $claimantUserId) {
                    throw PrizeClaimException::alreadyClaimed((int) $bet->getKey(), (int) $payout->getKey());
                }

                $guard->assertSnapshot($payout, $existing);

                $existingStatus = isset($existing['status'])
                    ? PrizeClaimStatus::tryFrom((string) $existing['status'])
                    : null;

                if ($existingStatus === null) {
                    $guard->refuse($payout);
                }

                if ($existingStatus->isTerminal()) {
                    return $this->resultRow($bet, $payout, $existing, noOp: true);
                }

                if (! $this->windowService->allowsClaim($payout)) {
                    throw PrizeClaimException::windowClosed(
                        (int) $bet->getKey(),
                        isset($existing['window_closes_at']) ? (string) $existing['window_closes_at'] : 'unknown',
                    );
                }

                return $this->resultRow($bet, $payout, $existing, noOp: true);
            }

            $guard->assertMutable($payout);

            $window = $this->windowService->register($payout, $bet);
            $payout->refresh();

            if (! $this->windowService->allowsClaim($payout)) {
                throw PrizeClaimException::windowClosed(
                    (int) $bet->getKey(),
                    isset($window['window_closes_at']) ? (string) $window['window_closes_at'] : null,
                );
            }

            $initialStatus = $guard->submissionStatus((string) $payout->amount);
            $nowIso = Carbon::now()->toIso8601String();

            $claimRecord = array_merge(is_array($existing) ? $existing : [], [
                'bet_id' => (int) $bet->getKey(),
                'claimant_user_id' => $claimantUserId,
                'status' => $initialStatus->value,
                'submitted_status' => $initialStatus->value,
                'claimed_at' => $nowIso,
                'window_closes_at' => (string) $window['window_closes_at'],
                'window_facts_hash' => (string) $window['facts_hash'],
                'facts_hash' => $guard->fingerprint($payout),
                'approved_at' => $initialStatus === PrizeClaimStatus::Approved ? $nowIso : null,
                'approved_by' => null,
                'approval_note' => $initialStatus === PrizeClaimStatus::Approved
                    ? 'auto-approved below manual review threshold'
                    : null,
            ]);

            $metadata = is_array($payout->metadata) ? $payout->metadata : [];
            $metadata['claim'] = $claimRecord;
            $payout->metadata = $metadata;
            $payout->save();

            $this->recordAudit(
                payout: $payout,
                userId: $claimantUserId,
                action: AuditAction::Create,
                riskLevel: $initialStatus === PrizeClaimStatus::UnderReview ? RiskLevel::Medium : RiskLevel::Low,
                description: sprintf(
                    'Prize claim submitted for bet #%d (payout #%d, %s %s); initial status %s.',
                    (int) $bet->getKey(),
                    (int) $payout->getKey(),
                    (string) $payout->amount,
                    $payout->currency->value,
                    $initialStatus->value,
                ),
                oldValues: null,
                newValues: ['claim' => $claimRecord],
            );

            return $this->resultRow($bet, $payout, $claimRecord, noOp: false);
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(int $payoutId, int $reviewerId, ?string $note = null, bool $originalFactsVerified = false): array
    {
        if (! $originalFactsVerified) {
            throw PrizeClaimException::stateForbids($payoutId, 'unverified_facts', 'approve');
        }

        $cleanNote = is_string($note)
            ? mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]/', '', $note)), 0, 1000)
            : '';

        if (mb_strlen($cleanNote) < 15) {
            throw PrizeClaimException::stateForbids($payoutId, 'invalid_note', 'approve');
        }

        return DB::transaction(function () use ($payoutId, $reviewerId, $cleanNote): array {
            /** @var Payout $payout */
            $payout = Payout::withTrashed()->lockForUpdate()->findOrFail($payoutId);
            $guard = app(PrizeClaimGuard::class);
            $guard->assertMutable($payout);
            $guard->reviewActor($payout, $reviewerId);

            $metadata = is_array($payout->metadata) ? $payout->metadata : [];
            $claim = $metadata['claim'] ?? null;

            if (! is_array($claim) || ! array_key_exists('status', $claim)) {
                throw PrizeClaimException::stateForbids($payoutId, 'missing_claim', 'approve');
            }

            $guard->assertSnapshot($payout, $claim);

            if (! $this->windowService->allowsClaim($payout)) {
                $guard->refuse($payout);
            }

            $current = PrizeClaimStatus::tryFrom((string) $claim['status']);
            if ($current === null || ! $current->canTransitionTo(PrizeClaimStatus::Approved)) {
                throw PrizeClaimException::stateForbids($payoutId, (string) ($claim['status'] ?? 'unknown'), 'approve');
            }

            $old = $claim;
            $claim['status'] = PrizeClaimStatus::Approved->value;
            $claim['approved_at'] = Carbon::now()->toIso8601String();
            $claim['approved_by'] = $reviewerId;
            $claim['approval_note_hash'] = hash('sha256', $cleanNote);
            unset($claim['approval_note']);

            $metadata['claim'] = $claim;
            $payout->metadata = $metadata;
            $payout->save();

            $this->recordAudit(
                payout: $payout,
                userId: $reviewerId,
                action: AuditAction::Update,
                riskLevel: RiskLevel::Medium,
                description: sprintf('Prize claim on payout #%d approved by user #%d.', $payoutId, $reviewerId),
                oldValues: ['claim' => $old],
                newValues: ['claim' => $claim],
            );

            return $this->resultRowFromPayout($payout, $claim, noOp: false);
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(int $payoutId, int $reviewerId, string $reason, bool $originalFactsVerified = false): array
    {
        if (! $originalFactsVerified) {
            throw PrizeClaimException::stateForbids($payoutId, 'unverified_facts', 'reject');
        }

        $cleanReason = mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f]/', '', $reason)), 0, 1000);
        if (mb_strlen($cleanReason) < 15) {
            throw PrizeClaimException::stateForbids($payoutId, 'invalid_reason', 'reject');
        }

        return DB::transaction(function () use ($payoutId, $reviewerId, $cleanReason): array {
            /** @var Payout $payout */
            $payout = Payout::withTrashed()->lockForUpdate()->findOrFail($payoutId);
            $guard = app(PrizeClaimGuard::class);
            $guard->assertMutable($payout);
            $guard->reviewActor($payout, $reviewerId);

            $metadata = is_array($payout->metadata) ? $payout->metadata : [];
            $claim = $metadata['claim'] ?? null;

            if (! is_array($claim) || ! array_key_exists('status', $claim)) {
                throw PrizeClaimException::stateForbids($payoutId, 'missing_claim', 'reject');
            }

            $guard->assertSnapshot($payout, $claim);

            if (! $this->windowService->allowsClaim($payout)) {
                $guard->refuse($payout);
            }

            $current = PrizeClaimStatus::tryFrom((string) $claim['status']);
            if ($current === null || ! $current->canTransitionTo(PrizeClaimStatus::Rejected)) {
                throw PrizeClaimException::stateForbids($payoutId, (string) ($claim['status'] ?? 'unknown'), 'reject');
            }

            $old = $claim;
            $claim['status'] = PrizeClaimStatus::Rejected->value;
            $claim['rejected_at'] = Carbon::now()->toIso8601String();
            $claim['rejected_by'] = $reviewerId;
            $claim['rejection_reason_hash'] = hash('sha256', $cleanReason);
            unset($claim['rejection_reason']);

            $metadata['claim'] = $claim;
            $payout->metadata = $metadata;
            $payout->save();

            $this->recordAudit(
                payout: $payout,
                userId: $reviewerId,
                action: AuditAction::Update,
                riskLevel: RiskLevel::Medium,
                description: sprintf('Prize claim on payout #%d rejected by user #%d.', $payoutId, $reviewerId),
                oldValues: ['claim' => $old],
                newValues: ['claim' => $claim],
            );

            return $this->resultRowFromPayout($payout, $claim, noOp: false);
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function markPaid(int $payoutId, ?Carbon $paidAt = null): array
    {
        $payout = Payout::withTrashed()->findOrFail($payoutId);
        $completion = app(PrizeClaimCompletionService::class);
        $completion->assertEvidence($payout);
        $completion->complete($payout);

        $fresh = $payout->fresh();
        $claim = $this->claimFor($payoutId);
        if (! is_array($claim) || ($claim['status'] ?? null) !== PrizeClaimStatus::Paid->value) {
            app(PrizeClaimGuard::class)->refuse($fresh ?? $payout);
        }

        return $claim;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function claimFor(int $payoutId): ?array
    {
        $payout = Payout::withTrashed()->find($payoutId);
        if (! $payout instanceof Payout || $payout->trashed()) {
            return null;
        }

        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $claim = $metadata['claim'] ?? null;

        if (! is_array($claim) || ! array_key_exists('status', $claim)) {
            return null;
        }

        $status = PrizeClaimStatus::tryFrom((string) $claim['status']);
        if ($status === null) {
            return null;
        }

        $guard = app(PrizeClaimGuard::class);

        try {
            $guard->assertSnapshot($payout, $claim);
        } catch (PrizeClaimException) {
            return null;
        }

        return $this->resultRowFromPayout($payout, $claim, noOp: false);
    }

    /**
     * @param  array<string, mixed>  $claim
     * @return array<string, mixed>
     */
    private function resultRow(Bet $bet, Payout $payout, array $claim, bool $noOp): array
    {
        return [
            'bet_id' => (int) $bet->getKey(),
            'payout_id' => (int) $payout->getKey(),
            'payout_reference' => (string) $payout->reference_number,
            'payout_status' => $payout->status->value,
            'claimant_user_id' => (int) ($claim['claimant_user_id'] ?? $bet->user_id),
            'status' => (string) $claim['status'],
            'amount' => (string) $payout->amount,
            'currency' => $payout->currency->value,
            'window_closes_at' => isset($claim['window_closes_at']) ? (string) $claim['window_closes_at'] : null,
            'window_open' => $this->windowService->allowsClaim($payout),
            'claimed_at' => (string) ($claim['claimed_at'] ?? ''),
            'approved_at' => $claim['approved_at'] ?? null,
            'approved_by' => $claim['approved_by'] ?? null,
            'paid_at' => $claim['paid_at'] ?? null,
            'rejected_at' => $claim['rejected_at'] ?? null,
            'rejection_reason_hash' => $claim['rejection_reason_hash'] ?? null,
            'expired_at' => $claim['expired_at'] ?? null,
            'no_op' => $noOp,
        ];
    }

    /**
     * @param  array<string, mixed>  $claim
     * @return array<string, mixed>
     */
    private function resultRowFromPayout(Payout $payout, array $claim, bool $noOp): array
    {
        return [
            'bet_id' => (int) ($claim['bet_id'] ?? $payout->bet_id),
            'payout_id' => (int) $payout->getKey(),
            'payout_reference' => (string) $payout->reference_number,
            'payout_status' => $payout->status->value,
            'claimant_user_id' => (int) ($claim['claimant_user_id'] ?? $payout->user_id),
            'status' => (string) $claim['status'],
            'amount' => (string) $payout->amount,
            'currency' => $payout->currency->value,
            'window_closes_at' => isset($claim['window_closes_at']) ? (string) $claim['window_closes_at'] : null,
            'window_open' => $this->windowService->allowsClaim($payout),
            'claimed_at' => (string) ($claim['claimed_at'] ?? ''),
            'approved_at' => $claim['approved_at'] ?? null,
            'approved_by' => $claim['approved_by'] ?? null,
            'paid_at' => $claim['paid_at'] ?? null,
            'rejected_at' => $claim['rejected_at'] ?? null,
            'rejection_reason_hash' => $claim['rejection_reason_hash'] ?? null,
            'expired_at' => $claim['expired_at'] ?? null,
            'no_op' => $noOp,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function recordAudit(
        Payout $payout,
        int $userId,
        AuditAction $action,
        RiskLevel $riskLevel,
        string $description,
        ?array $oldValues,
        ?array $newValues,
    ): void {
        AuditLog::query()->create([
            'user_id' => $userId,
            'action' => $action,
            'risk_level' => $riskLevel,
            'auditable_type' => Payout::class,
            'auditable_id' => (int) $payout->getKey(),
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => [
                'payout_id' => (int) $payout->getKey(),
                'payout_reference' => (string) $payout->reference_number,
                'bet_id' => (int) $payout->bet_id,
                'amount' => (string) $payout->amount,
                'currency' => $payout->currency->value,
            ],
        ]);
    }
}
