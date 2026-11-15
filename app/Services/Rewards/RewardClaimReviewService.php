<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\AuditAction;
use App\Enums\RewardClaimStatus;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Home\DashboardCacheInvalidator;
use App\Services\Loyalty\LoyaltyPointsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SOLE owner of resolving PENDING reward claims (admin review).
 *
 * A claim is pending only when fulfilment needs a human (VIP upgrade). Approve
 * completes it; reject returns the spent points through the loyalty service and
 * restores catalog stock, all in one transaction with an audit entry.
 */
final class RewardClaimReviewService
{
    public function __construct(
        private readonly LoyaltyPointsService $loyaltyPoints,
        private readonly DashboardCacheInvalidator $dashboardCache,
    ) {}

    /**
     * @throws FinancialException
     */
    public function approve(RewardClaim|int $claim, User $admin, ?string $notes = null): RewardClaim
    {
        return DB::transaction(function () use ($claim, $admin, $notes): RewardClaim {
            $locked = $this->lockPending($claim);

            $payload = is_array($locked->payload) ? $locked->payload : [];
            $payload['review'] = [
                'decision' => 'approved',
                'reviewed_by' => (int) $admin->getKey(),
                'reviewed_at' => Carbon::now()->toIso8601String(),
                'notes' => $notes,
            ];
            $payload['review_required'] = false;

            $locked->status = RewardClaimStatus::Completed;
            $locked->completed_at = Carbon::now();
            $locked->payload = $payload;
            $locked->save();

            $this->audit($locked, $admin, 'Admin approved pending reward claim.', RiskLevel::Low, ['notes' => $notes]);
            $this->dashboardCache->forget((int) $locked->member_id);

            return $locked;
        });
    }

    /**
     * @throws FinancialException
     */
    public function reject(RewardClaim|int $claim, User $admin, string $reason): RewardClaim
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw FinancialException::withCode(
                'reward_rejection_reason_required',
                'A rejection reason is required.',
            );
        }

        return DB::transaction(function () use ($claim, $admin, $reason): RewardClaim {
            $locked = $this->lockPending($claim);

            /** @var User $member */
            $member = User::query()->whereKey($locked->member_id)->firstOrFail();

            $refund = $this->loyaltyPoints->refundPoints(
                user: $member,
                points: (int) $locked->points_spent,
                sourceType: RewardClaim::class,
                sourceId: (int) $locked->getKey(),
                idempotencyKey: sprintf('reward-claim-refund:%d', (int) $locked->getKey()),
                description: sprintf('Refund for rejected reward claim #%d', (int) $locked->getKey()),
                metadata: ['reason' => $reason, 'rejected_by' => (int) $admin->getKey()],
            );

            /** @var RewardCatalogItem|null $item */
            $item = RewardCatalogItem::query()->whereKey($locked->item_id)->lockForUpdate()->first();
            if ($item instanceof RewardCatalogItem && $item->stock !== null) {
                $item->stock = $item->stock + 1;
                $item->save();
            }

            $payload = is_array($locked->payload) ? $locked->payload : [];
            $payload['review'] = [
                'decision' => 'rejected',
                'reviewed_by' => (int) $admin->getKey(),
                'reviewed_at' => Carbon::now()->toIso8601String(),
                'reason' => $reason,
                'refund_ledger_id' => (int) $refund->getKey(),
            ];
            $payload['review_required'] = false;

            $locked->status = RewardClaimStatus::Reversed;
            $locked->payload = $payload;
            $locked->save();

            $this->audit($locked, $admin, 'Admin rejected pending reward claim; points refunded.', RiskLevel::Medium, [
                'reason' => $reason,
                'points_refunded' => (int) $locked->points_spent,
            ]);
            $this->dashboardCache->forget((int) $locked->member_id);

            return $locked;
        });
    }

    /**
     * @throws FinancialException
     */
    private function lockPending(RewardClaim|int $claim): RewardClaim
    {
        $id = $claim instanceof RewardClaim ? (int) $claim->getKey() : $claim;

        /** @var RewardClaim|null $locked */
        $locked = RewardClaim::query()->whereKey($id)->lockForUpdate()->first();

        if (! $locked instanceof RewardClaim) {
            throw FinancialException::withCode('reward_claim_not_found', 'Reward claim not found.');
        }

        if ($locked->status !== RewardClaimStatus::Pending) {
            throw FinancialException::withCode(
                'reward_claim_not_pending',
                'Only pending reward claims can be reviewed.',
                ['status' => $locked->status->value],
            );
        }

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(RewardClaim $claim, User $admin, string $description, RiskLevel $risk, array $metadata): void
    {
        AuditLog::query()->create([
            'user_id' => (int) $admin->getKey(),
            'action' => AuditAction::Update->value,
            'auditable_type' => RewardClaim::class,
            'auditable_id' => (int) $claim->getKey(),
            'risk_level' => $risk->value,
            'description' => $description,
            'metadata' => array_merge($metadata, [
                'member_id' => (int) $claim->member_id,
                'status' => $claim->status->value,
            ]),
        ]);
    }
}
