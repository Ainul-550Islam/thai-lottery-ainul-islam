<?php

declare(strict_types=1);

namespace App\Services\Rewards\Fulfillers;

use App\Enums\RewardClaimStatus;
use App\Exceptions\FinancialException;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Account\AccountGradeService;

final class VipUpgradeFulfiller implements RewardFulfiller
{
    public function __construct(
        private readonly AccountGradeService $gradeService,
    ) {}

    /**
     * AccountGradeService derives grades strictly from rolling qualifying spend and
     * has no manual-upgrade mutator, so a VIP upgrade is NEVER auto-applied here.
     * The claim stays `pending` for admin review (RewardClaimReviewService), which
     * either completes it or refunds the points.
     *
     * The target grade must come from the catalog item payload; there is no
     * hardcoded fallback.
     *
     * @return array{status: RewardClaimStatus, financial_transaction_id: null, payload: array<string, mixed>}
     *
     * @throws FinancialException
     */
    public function fulfill(User $member, RewardCatalogItem $item, RewardClaim $claim): array
    {
        /** @var array<string, mixed> $itemPayload */
        $itemPayload = is_array($item->payload) ? $item->payload : [];
        $targetGrade = trim((string) ($itemPayload['target_grade'] ?? ''));

        if ($targetGrade === '') {
            throw FinancialException::withCode(
                'invalid_vip_upgrade_payload',
                'VIP upgrade reward payload requires a target_grade.',
            );
        }

        $current = $this->gradeService->current($member, bypassCache: true);

        return [
            'status' => RewardClaimStatus::Pending,
            'financial_transaction_id' => null,
            'payload' => [
                'claim_id' => (int) $claim->getKey(),
                'current_grade' => (string) ($current['grade_key'] ?? ''),
                'qualifying_spend' => (string) ($current['qualifying_spend'] ?? '0.00'),
                'target_grade' => $targetGrade,
                'review_required' => true,
                'reason' => 'awaiting_admin_vip_review',
            ],
        ];
    }
}
