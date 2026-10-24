<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\Enums\AuditAction;
use App\Enums\BetStatus;
use App\Enums\PrizeClaimStatus;
use App\Enums\RiskLevel;
use App\Events\PrizeClaimApproved;
use App\Exceptions\PrizeClaimException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Payout;
use Illuminate\Support\Facades\DB;

class PrizeClaimService
{
    public function __construct(
        private readonly ClaimWindowService $windows,
    ) {}

    /**
     * Claim a prize for a winning bet on behalf of a user.
     *
     * @return array{
     *     payout_id: int,
     *     bet_id: int,
     *     claimant_user_id: int,
     *     status: string,
     *     amount: string,
     *     currency: string,
     *     window_closes_at: string|null,
     *     claimed_at: string,
     *     no_op: bool
     * }
     *
     * @throws PrizeClaimException
     */
    public function claim(int $betId, int $claimantUserId): array
    {
        return DB::transaction(function () use ($betId, $claimantUserId): array {
            $bet = Bet::query()->lockForUpdate()->find($betId);

            if (! $bet instanceof Bet) {
                throw PrizeClaimException::noWinningBet($betId, 'does not exist');
            }

            if ((int) $bet->user_id !== $claimantUserId) {
                throw PrizeClaimException::notOwner($betId, $claimantUserId, (int) $bet->user_id);
            }

            if ($bet->status !== BetStatus::Won) {
                throw PrizeClaimException::noWinningBet($betId, sprintf('is %s, not won', $bet->status->value));
            }

            if (bccomp((string) $bet->actual_payout, '0.00', 2) <= 0) {
                throw PrizeClaimException::nothingToClaim($betId);
            }

            $guard = app(PrizeClaimGuard::class);
            $payout = $guard->resolve($bet, true);

            if (! $payout instanceof Payout) {
                throw PrizeClaimException::noWinningBet($betId, 'has no payout obligation recorded');
            }

            $guard->assertClaimant($payout, $claimantUserId);

            if (is_array($payout->metadata) && array_key_exists('claim', $payout->metadata) && ! is_array($payout->metadata['claim'])) {
                $guard->refuse($payout);
            }

            $existing = $this->claimRecord($payout);

            if (is_array($existing)) {
                if ((int) ($existing['claimant_user_id'] ?? 0) !== $claimantUserId) {
                    throw PrizeClaimException::alreadyClaimed($betId, (int) $payout->getKey());
                }

                $guard->assertSnapshot($payout, $existing);

                return $this->resultRow($payout, $existing, noOp: true);
            }

            $guard->assertMutable($payout);
            $this->windows->register($payout, $bet);

            if (! $this->windows->allowsClaim($payout)) {
                throw PrizeClaimException::windowClosed(
                    $betId,
                    $this->windows->closesAtFor($payout)?->toIso8601String() ?? 'unknown',
                );
            }

            $windowClosesAt = $this->windows->closesAtFor($payout);
            $status = $guard->submissionStatus((string) $bet->actual_payout);

            $rawClaim = is_array($payout->metadata['claim'] ?? null) ? $payout->metadata['claim'] : [];
            $claim = array_merge($rawClaim, [
                'status' => $status->value,
                'claimant_user_id' => $claimantUserId,
                'claimed_at' => now()->toIso8601String(),
                'window_closes_at' => $windowClosesAt?->toIso8601String(),
                'submitted_status' => $status->value,
                'facts_hash' => $guard->fingerprint($payout),
                'window_facts_hash' => data_get($payout->metadata, 'claim_window.facts_hash'),
            ]);

            $this->writeClaim($payout, $claim);

            $this->recordAudit($payout, $claim, $claimantUserId, sprintf(
                'Prize claim of %s %s for bet #%d submitted; status %s.',
                (string) $payout->amount,
                $payout->currency->value,
                $betId,
                $status->value,
            ));

            if ($status === PrizeClaimStatus::Approved) {
                event(new PrizeClaimApproved(
                    payout: $payout,
                    betId: $betId,
                    claimantUserId: $claimantUserId,
                    approvedByUserId: null,
                    autoApproved: true,
                ));
            }

            return $this->resultRow($payout, $claim, noOp: false);
        });
    }

    /**
     * Approve a claim that is waiting in UnderReview.
     *
     * @throws PrizeClaimException
     */
    public function approve(int $payoutId, int $operatorUserId, ?string $note = null, bool $verified = true): array
    {
        return DB::transaction(function () use ($payoutId, $operatorUserId, $note, $verified): array {
            $payout = Payout::query()->lockForUpdate()->find($payoutId);

            if (! $payout instanceof Payout) {
                throw PrizeClaimException::stateForbids($payoutId, 'missing', 'approved');
            }

            $guard = app(PrizeClaimGuard::class);

            if (! $verified || ($note !== null && trim($note) === '')) {
                $guard->refuse($payout);
            }

            $claim = $this->claimRecord($payout);

            if (! is_array($claim)) {
                throw PrizeClaimException::stateForbids($payoutId, 'no-claim', 'approved');
            }

            $guard->assertMutable($payout);
            $guard->assertSnapshot($payout, $claim);
            $guard->reviewActor($payout, $operatorUserId);

            $status = is_string($claim['status'] ?? null) ? $claim['status'] : '';

            if ($status !== PrizeClaimStatus::UnderReview->value) {
                throw PrizeClaimException::stateForbids($payoutId, $status === '' ? 'unknown' : $status, 'approved');
            }

            $claim['status'] = PrizeClaimStatus::Approved->value;
            $claim['approved_by'] = $operatorUserId;
            $claim['approved_at'] = now()->toIso8601String();
            if ($note !== null) {
                $claim['review_note_hash'] = hash('sha256', $note);
            }

            $this->writeClaim($payout, $claim);

            $this->recordAudit($payout, $claim, $operatorUserId, sprintf(
                'Prize claim of %s %s for payout #%d APPROVED.',
                (string) $payout->amount,
                $payout->currency->value,
                $payoutId,
            ));

            event(new PrizeClaimApproved(
                payout: $payout,
                betId: (int) ($payout->bet_id ?? 0),
                claimantUserId: (int) ($claim['claimant_user_id'] ?? 0),
                approvedByUserId: $operatorUserId,
                autoApproved: false,
            ));

            return $this->resultRow($payout, $claim, noOp: false);
        });
    }

    /**
     * Reject a claim in review.
     *
     * @throws PrizeClaimException
     */
    public function reject(int $payoutId, int $operatorUserId, string $reason, bool $verified = true): array
    {
        return DB::transaction(function () use ($payoutId, $operatorUserId, $reason, $verified): array {
            $payout = Payout::query()->lockForUpdate()->find($payoutId);

            if (! $payout instanceof Payout) {
                throw PrizeClaimException::stateForbids($payoutId, 'missing', 'rejected');
            }

            $guard = app(PrizeClaimGuard::class);

            if (! $verified || trim($reason) === '') {
                $guard->refuse($payout);
            }

            $claim = $this->claimRecord($payout);

            if (! is_array($claim)) {
                throw PrizeClaimException::stateForbids($payoutId, 'no-claim', 'rejected');
            }

            $guard->assertMutable($payout);
            $guard->assertSnapshot($payout, $claim);
            $guard->reviewActor($payout, $operatorUserId);

            $status = is_string($claim['status'] ?? null) ? $claim['status'] : '';

            if (! in_array($status, [PrizeClaimStatus::UnderReview->value, PrizeClaimStatus::Submitted->value], true)) {
                throw PrizeClaimException::stateForbids($payoutId, $status === '' ? 'unknown' : $status, 'rejected');
            }

            $claim['status'] = PrizeClaimStatus::Rejected->value;
            $claim['rejected_by'] = $operatorUserId;
            $claim['rejected_at'] = now()->toIso8601String();
            $claim['rejection_reason_hash'] = hash('sha256', $reason);
            unset($claim['rejection_reason']);

            $this->writeClaim($payout, $claim);

            $this->recordAudit($payout, $claim, $operatorUserId, sprintf(
                'Prize claim of %s %s for payout #%d REJECTED.',
                (string) $payout->amount,
                $payout->currency->value,
                $payoutId,
            ));

            return $this->resultRow($payout, $claim, noOp: false);
        });
    }

    /**
     * The player claim a payout carries, or null if none was ever submitted.
     *
     * @return array<string, mixed>|null
     */
    public function claimFor(int $payoutId): ?array
    {
        $payout = Payout::query()->find($payoutId);

        return $payout instanceof Payout ? $this->claimRecord($payout) : null;
    }

    /**
     * Where a payout's claim currently sits in the state machine.
     */
    public function statusOf(int $payoutId): ?PrizeClaimStatus
    {
        $claim = $this->claimFor($payoutId);

        if ($claim === null || ! isset($claim['status']) || ! is_string($claim['status'])) {
            return null;
        }

        return PrizeClaimStatus::tryFrom($claim['status']);
    }

    /**
     * Whether this claim window is still open.
     */
    public function windowIsOpen(?\Illuminate\Support\Carbon $windowClosesAt): bool
    {
        return $windowClosesAt === null || $windowClosesAt->isFuture();
    }

    /**
     * Only return a record when player claim keys exist (not maker routing alone).
     *
     * @return array<string, mixed>|null
     */
    private function claimRecord(Payout $payout): ?array
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $claim = $metadata['claim'] ?? null;

        if (! is_array($claim)) {
            return null;
        }

        if (! array_key_exists('claimant_user_id', $claim)
            && ! array_key_exists('claimed_at', $claim)
            && ! array_key_exists('status', $claim)) {
            return null;
        }

        return $claim;
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function writeClaim(Payout $payout, array $claim): void
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $existing = is_array($metadata['claim'] ?? null) ? $metadata['claim'] : [];
        $metadata['claim'] = array_merge($existing, $claim);

        $payout->metadata = $metadata;
        $payout->save();
    }

    /**
     * @param  array<string, mixed>  $claim
     *
     * @return array{payout_id: int, bet_id: int, claimant_user_id: int, status: string, amount: string, currency: string, window_closes_at: string|null, claimed_at: string, no_op: bool}
     */
    private function resultRow(Payout $payout, array $claim, bool $noOp): array
    {
        return [
            'payout_id' => (int) $payout->getKey(),
            'bet_id' => $payout->bet_id !== null ? (int) $payout->bet_id : 0,
            'claimant_user_id' => (int) ($claim['claimant_user_id'] ?? 0),
            'status' => (string) ($claim['status'] ?? ''),
            'amount' => (string) $payout->amount,
            'currency' => $payout->currency->value,
            'window_closes_at' => isset($claim['window_closes_at']) ? (string) $claim['window_closes_at'] : null,
            'claimed_at' => (string) ($claim['claimed_at'] ?? ''),
            'no_op' => $noOp,
        ];
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function recordAudit(Payout $payout, array $claim, ?int $actorUserId, string $description): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => $actorUserId,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Medium,
            'auditable_type' => Payout::class,
            'auditable_id' => $payout->getKey(),
            'description' => $description,
            'metadata' => [
                'payout_id' => (int) $payout->getKey(),
                'payout_reference' => (string) $payout->reference_number,
                'claim_status' => (string) ($claim['status'] ?? ''),
                'amount' => (string) $payout->amount,
                'currency' => $payout->currency->value,
            ],
        ]);

        $log->save();
    }
}
