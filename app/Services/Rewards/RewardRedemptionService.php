<?php

declare(strict_types=1);

namespace App\Services\Rewards;

use App\Enums\AuditAction;
use App\Enums\RewardClaimStatus;
use App\Enums\RiskLevel;
use App\Events\Rewards\RewardClaimed;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Account\AccountGradeEvaluator;
use App\Services\Compliance\RealMoneyGate;
use App\Services\Home\DashboardCacheInvalidator;
use App\Services\Loyalty\LoyaltyPointsService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RewardRedemptionService
{
    public function __construct(
        private readonly LoyaltyPointsService $loyaltyPoints,
        private readonly RewardFulfillerRegistry $registry,
        private readonly AccountGradeEvaluator $gradeEvaluator,
        private readonly RewardCatalogService $catalogService,
    ) {}

    /**
     * Sole redemption owner: locks loyalty balance & catalog item, spends points,
     * fulfills via registry, persists claim, and fires RewardClaimed after commit.
     *
     * @return array{claim: RewardClaim, replayed: bool}
     *
     * @throws FinancialException
     */
    public function redeem(
        User $member,
        RewardCatalogItem|int|string $itemOrIdentifier,
        string $idempotencyKey,
    ): array {
        $trimmedKey = trim($idempotencyKey);
        if ($trimmedKey === '' || strlen($trimmedKey) > 128) {
            throw FinancialException::withCode(
                'reward_idempotency_key_invalid',
                'A valid idempotency key (1..128 characters) is required to redeem a reward.',
            );
        }

        $targetItem = $this->resolveItem($itemOrIdentifier);

        try {
            return DB::transaction(function () use ($member, $targetItem, $trimmedKey): array {
                User::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();

                /** @var RewardCatalogItem $lockedItem */
                $lockedItem = RewardCatalogItem::query()
                    ->whereKey($targetItem->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                /** @var RewardClaim|null $existing */
                $existing = RewardClaim::query()
                    ->where('member_id', $member->getKey())
                    ->where('item_id', $lockedItem->getKey())
                    ->where('idempotency_key', $trimmedKey)
                    ->with(['item', 'voucher'])
                    ->first();

                if ($existing instanceof RewardClaim) {
                    return [
                        'claim' => $existing,
                        'replayed' => true,
                    ];
                }

                if ($lockedItem->type->movesMoney()) {
                    app(RealMoneyGate::class)->assertOpen('reward_redemption');
                }

                $now = Carbon::now();
                if (! $lockedItem->isAvailableAt($now)) {
                    throw FinancialException::withCode(
                        'reward_item_unavailable',
                        'Selected reward item is inactive, expired, or out of stock.',
                        ['item_code' => $lockedItem->code],
                    );
                }

                $memberGrade = $this->gradeEvaluator->evaluate($member, $now)->level;
                if (! $this->catalogService->isGradeEligible($lockedItem, $memberGrade)) {
                    throw FinancialException::withCode(
                        'reward_grade_not_eligible',
                        'Your current VIP grade does not meet the minimum grade requirement for this reward.',
                        [
                            'item_code' => $lockedItem->code,
                            'member_grade' => $memberGrade->value,
                        ],
                    );
                }

                if ($lockedItem->per_member_limit !== null) {
                    $claimedByMember = RewardClaim::query()
                        ->where('member_id', $member->getKey())
                        ->where('item_id', $lockedItem->getKey())
                        ->whereIn('status', [
                            RewardClaimStatus::Pending->value,
                            RewardClaimStatus::Completed->value,
                        ])
                        ->count();

                    if ($claimedByMember >= $lockedItem->per_member_limit) {
                        throw FinancialException::withCode(
                            'reward_per_member_limit_reached',
                            'You have reached the redemption limit for this reward.',
                            ['limit' => $lockedItem->per_member_limit],
                        );
                    }
                }

                if ($lockedItem->stock !== null) {
                    if ($lockedItem->stock < 1) {
                        throw FinancialException::withCode(
                            'reward_out_of_stock',
                            'This reward is out of stock.',
                        );
                    }

                    $lockedItem->stock = $lockedItem->stock - 1;
                    $lockedItem->save();
                }

                $ledgerIdempotencyKey = substr(sprintf(
                    'reward-claim:%d:%d:%s',
                    (int) $member->getKey(),
                    (int) $lockedItem->getKey(),
                    hash('sha256', $trimmedKey),
                ), 0, 128);

                $loyaltyLedger = $this->loyaltyPoints->spendPoints(
                    user: $member,
                    pointsCost: (int) $lockedItem->points_cost,
                    sourceType: RewardCatalogItem::class,
                    sourceId: (int) $lockedItem->getKey(),
                    idempotencyKey: $ledgerIdempotencyKey,
                    description: sprintf('Redeemed reward %s (-%d pts)', $lockedItem->code, $lockedItem->points_cost),
                    metadata: [
                        'item_code' => $lockedItem->code,
                        'reward_type' => $lockedItem->type->value,
                    ],
                );

                /** @var RewardClaim $claim */
                $claim = RewardClaim::query()->create([
                    'member_id' => (int) $member->getKey(),
                    'item_id' => (int) $lockedItem->getKey(),
                    'points_spent' => (int) $lockedItem->points_cost,
                    'status' => RewardClaimStatus::Pending,
                    'idempotency_key' => $trimmedKey,
                    'loyalty_ledger_id' => (int) $loyaltyLedger->getKey(),
                    'payload' => [],
                ]);

                $fulfillment = $this->registry->fulfill($member, $lockedItem, $claim);

                $claim->status = $fulfillment['status'];
                $claim->financial_transaction_id = $fulfillment['financial_transaction_id'];
                $claim->payload = $fulfillment['payload'];
                $claim->completed_at = $fulfillment['status'] === RewardClaimStatus::Completed ? $now : null;
                $claim->save();

                AuditLog::query()->create([
                    'user_id' => (int) $member->getKey(),
                    'action' => AuditAction::Create->value,
                    'auditable_type' => RewardClaim::class,
                    'auditable_id' => (int) $claim->getKey(),
                    'risk_level' => RiskLevel::Low->value,
                    'description' => sprintf('Member redeemed reward %s.', $lockedItem->code),
                    'metadata' => [
                        'item_code' => $lockedItem->code,
                        'reward_type' => $lockedItem->type->value,
                        'points_spent' => (int) $claim->points_spent,
                        'status' => $claim->status->value,
                    ],
                ]);

                app(DashboardCacheInvalidator::class)->forget($member);

                $claim->load(['item', 'voucher']);

                DB::afterCommit(static function () use ($claim): void {
                    event(new RewardClaimed($claim));
                });

                return [
                    'claim' => $claim,
                    'replayed' => false,
                ];
            }, 5);
        } catch (QueryException $exception) {
            /** @var RewardClaim|null $winner */
            $winner = RewardClaim::query()
                ->where('member_id', $member->getKey())
                ->where('item_id', $targetItem->getKey())
                ->where('idempotency_key', $trimmedKey)
                ->with(['item', 'voucher'])
                ->first();

            if ($winner instanceof RewardClaim) {
                return [
                    'claim' => $winner,
                    'replayed' => true,
                ];
            }

            throw $exception;
        }
    }

    /**
     * @throws FinancialException
     */
    private function resolveItem(RewardCatalogItem|int|string $itemOrIdentifier): RewardCatalogItem
    {
        if ($itemOrIdentifier instanceof RewardCatalogItem) {
            return $itemOrIdentifier;
        }

        $query = RewardCatalogItem::query();
        if (is_int($itemOrIdentifier) || ctype_digit((string) $itemOrIdentifier)) {
            $query->whereKey((int) $itemOrIdentifier);
        } else {
            $query->where('code', trim((string) $itemOrIdentifier));
        }

        /** @var RewardCatalogItem|null $item */
        $item = $query->first();
        if (! $item instanceof RewardCatalogItem) {
            throw FinancialException::withCode(
                'reward_item_not_found',
                'Requested reward catalog item does not exist.',
            );
        }

        return $item;
    }
}
