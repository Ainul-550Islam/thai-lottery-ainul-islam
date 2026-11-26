<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards;

use App\Enums\RewardClaimStatus;
use App\Exceptions\FinancialException;
use App\Models\LoyaltyPointLedger;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Loyalty\LoyaltyPointsService;
use App\Services\Rewards\RewardClaimReviewService;
use App\Services\Rewards\RewardRedemptionService;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RewardCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RewardClaimReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([LedgerAccountSeeder::class, RewardCatalogSeeder::class]);
    }

    /**
     * @return array{0: User, 1: RewardClaim, 2: int}
     */
    private function pendingVipClaim(): array
    {
        $member = User::factory()->create();
        LoyaltyPointLedger::query()->create([
            'user_id' => $member->getKey(),
            'entry_type' => 'earn',
            'points' => 20000,
            'balance_after' => 20000,
            'source_type' => 'test_seed',
            'source_id' => 'seed-'.$member->getKey(),
            'idempotency_key' => 'seed-pts-'.$member->getKey(),
            'description' => 'Seed points',
        ]);

        $result = app(RewardRedemptionService::class)->redeem($member, 'vip_upgrade_review', 'review-key-0001');
        $claim = $result['claim'];
        $this->assertSame(RewardClaimStatus::Pending, $claim->status);

        return [$member, $claim, (int) $claim->points_spent];
    }

    public function test_reject_refunds_points_once_restores_stock_and_frees_the_member_limit(): void
    {
        [$member, $claim, $spent] = $this->pendingVipClaim();
        $admin = User::factory()->create();

        $item = RewardCatalogItem::query()->findOrFail($claim->item_id);
        $stockBefore = $item->stock;
        $balanceAfterSpend = app(LoyaltyPointsService::class)->currentBalance($member);

        $service = app(RewardClaimReviewService::class);
        $rejected = $service->reject($claim, $admin, 'Not eligible for fast-track');

        $this->assertSame(RewardClaimStatus::Reversed, $rejected->status);
        $this->assertSame($balanceAfterSpend + $spent, app(LoyaltyPointsService::class)->currentBalance($member));
        if ($stockBefore !== null) {
            $this->assertSame($stockBefore + 1, $item->fresh()?->stock);
        }

        // Second review of the same claim is refused: no double refund.
        try {
            $service->reject($rejected, $admin, 'again');
            $this->fail('A resolved claim must not be reviewable again.');
        } catch (FinancialException $e) {
            $this->assertSame('reward_claim_not_pending', $e->errorCode());
        }
        $this->assertSame($balanceAfterSpend + $spent, app(LoyaltyPointsService::class)->currentBalance($member));

        // Reversed claims no longer count toward per_member_limit, so the member may retry.
        $retry = app(RewardRedemptionService::class)->redeem($member, 'vip_upgrade_review', 'review-key-0002');
        $this->assertSame(RewardClaimStatus::Pending, $retry['claim']->status);
    }

    public function test_approve_completes_the_claim_without_touching_points(): void
    {
        [$member, $claim] = $this->pendingVipClaim();
        $admin = User::factory()->create();
        $balance = app(LoyaltyPointsService::class)->currentBalance($member);

        $approved = app(RewardClaimReviewService::class)->approve($claim, $admin, 'Applied manually');

        $this->assertSame(RewardClaimStatus::Completed, $approved->status);
        $this->assertNotNull($approved->completed_at);
        $this->assertSame($balance, app(LoyaltyPointsService::class)->currentBalance($member));
    }

    public function test_reject_requires_a_reason(): void
    {
        [, $claim] = $this->pendingVipClaim();

        $this->expectException(FinancialException::class);
        app(RewardClaimReviewService::class)->reject($claim, User::factory()->create(), '   ');
    }
}
