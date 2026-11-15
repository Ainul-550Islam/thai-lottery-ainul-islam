<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards;

use App\Enums\Currency;
use App\Enums\FreeBetVoucherStatus;
use App\Enums\RewardClaimStatus;
use App\Enums\RewardType;
use App\Enums\WalletType;
use App\Events\Rewards\RewardClaimed;
use App\Models\LoyaltyPointLedger;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Cashback\CashbackAccrualService;
use App\Services\Finance\WalletService;
use Database\Seeders\LedgerAccountSeeder;
use Database\Seeders\RewardCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RewardRedemptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            LedgerAccountSeeder::class,
            RewardCatalogSeeder::class,
        ]);
    }

    private function grantPoints(User $member, int $points): void
    {
        LoyaltyPointLedger::query()->create([
            'user_id' => $member->getKey(),
            'entry_type' => 'earn',
            'points' => $points,
            'balance_after' => $points,
            'source_type' => 'test_seed',
            'source_id' => 'seed-'.$member->getKey(),
            'idempotency_key' => 'seed-pts-'.$member->getKey().'-'.uniqid(),
            'description' => 'Seed points',
        ]);
    }

    public function test_redeems_all_four_reward_types_and_enforces_insufficient_points_stock_limit_and_window(): void
    {
        Event::fake([RewardClaimed::class]);

        // 0. Insufficient points -> rejected with 422, zero claims created
        $lowPointsMember = User::factory()->create();
        $this->grantPoints($lowPointsMember, 500);
        Sanctum::actingAs($lowPointsMember);

        $this->postJson('/api/v1/me/rewards/bonus_cash_100/claim', [
            'idempotency_key' => 'redeem-insufficient-1',
        ])->assertStatus(422);
        $this->assertSame(0, RewardClaim::query()->where('member_id', $lowPointsMember->getKey())->count());

        $member = User::factory()->create();
        $this->grantPoints($member, 15000);
        Sanctum::actingAs($member);

        // 1. bonus_cash -> credits BONUS wallet only (main wallet stays 0.00)
        $bonusRes = $this->postJson('/api/v1/me/rewards/bonus_cash_100/claim', [
            'idempotency_key' => 'redeem-bonus-1',
        ]);
        $bonusRes->assertStatus(201)
            ->assertJsonPath('data.claim.status', RewardClaimStatus::Completed->value);

        $wallets = app(WalletService::class);
        $mainWallet = $wallets->getOrCreateWallet($member, Currency::THB, WalletType::Primary);
        $bonusWallet = $wallets->getOrCreateWallet($member, Currency::THB, WalletType::Bonus);
        $this->assertSame('0.00', bcadd((string) $mainWallet->fresh()?->balance, '0.00', 2));
        $this->assertSame('100.00', bcadd((string) $bonusWallet->fresh()?->balance, '0.00', 2));

        // 2. cashback_boost -> stores extra_bps in claim payload
        $boostRes = $this->postJson('/api/v1/me/rewards/cashback_boost_7d/claim', [
            'idempotency_key' => 'redeem-boost-1',
        ]);
        $boostRes->assertStatus(201)
            ->assertJsonPath('data.claim.status', RewardClaimStatus::Completed->value)
            ->assertJsonPath('data.claim.payload.extra_bps', 50);
        $this->assertSame(50, app(CashbackAccrualService::class)->activeBoostBpsForMember($member));

        // 3. free_bet -> issues active FreeBetVoucher
        $freeBetRes = $this->postJson('/api/v1/me/rewards/free_bet_150/claim', [
            'idempotency_key' => 'redeem-freebet-1',
        ]);
        $freeBetRes->assertStatus(201)
            ->assertJsonPath('data.claim.status', RewardClaimStatus::Completed->value)
            ->assertJsonPath('data.claim.voucher.status', FreeBetVoucherStatus::Active->value)
            ->assertJsonPath('data.claim.voucher.amount', '150.00');

        // 4. vip_upgrade -> stays pending for admin review because AccountGradeService has no manual upgrade mutator
        $vipRes = $this->postJson('/api/v1/me/rewards/vip_upgrade_review/claim', [
            'idempotency_key' => 'redeem-vip-1',
        ]);
        $vipRes->assertStatus(201)
            ->assertJsonPath('data.claim.status', RewardClaimStatus::Pending->value)
            ->assertJsonPath('data.claim.payload.review_required', true);

        // per_member_limit = 1 on vip_upgrade_review -> second claim rejected
        $this->postJson('/api/v1/me/rewards/vip_upgrade_review/claim', [
            'idempotency_key' => 'redeem-vip-2',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'reward_per_member_limit_reached');

        // Stock = 1 and future/inactive checks
        $limited = RewardCatalogItem::query()->create([
            'code' => 'limited_item',
            'name' => 'Single Stock',
            'type' => RewardType::CashbackBoost,
            'points_cost' => 100,
            'payload' => ['extra_bps' => 10, 'duration_days' => 2],
            'stock' => 1,
            'active' => true,
        ]);

        $this->postJson('/api/v1/me/rewards/'.$limited->code.'/claim', [
            'idempotency_key' => 'limited-1',
        ])->assertStatus(201);
        $this->assertSame(0, $limited->fresh()?->stock);

        $this->postJson('/api/v1/me/rewards/'.$limited->code.'/claim', [
            'idempotency_key' => 'limited-2',
        ])->assertStatus(422)->assertJsonPath('error.code', 'reward_item_unavailable');

        $future = RewardCatalogItem::query()->create([
            'code' => 'future_item',
            'name' => 'Future Item',
            'type' => RewardType::CashbackBoost,
            'points_cost' => 100,
            'payload' => ['extra_bps' => 10, 'duration_days' => 2],
            'starts_at' => Carbon::now()->addDay(),
            'active' => true,
        ]);

        $this->postJson('/api/v1/me/rewards/'.$future->code.'/claim', [
            'idempotency_key' => 'future-1',
        ])->assertStatus(422)->assertJsonPath('error.code', 'reward_item_unavailable');

        $inactive = RewardCatalogItem::query()->create([
            'code' => 'inactive_item',
            'name' => 'Inactive Item',
            'type' => RewardType::CashbackBoost,
            'points_cost' => 100,
            'payload' => ['extra_bps' => 10, 'duration_days' => 2],
            'active' => false,
        ]);

        $this->postJson('/api/v1/me/rewards/'.$inactive->code.'/claim', [
            'idempotency_key' => 'inactive-1',
        ])->assertStatus(422);

        $platinumOnly = RewardCatalogItem::query()->create([
            'code' => 'platinum_only_item',
            'name' => 'Platinum Only Boost',
            'type' => RewardType::CashbackBoost,
            'points_cost' => 100,
            'payload' => ['extra_bps' => 25, 'duration_days' => 3, 'min_grade' => 'platinum'],
            'active' => true,
        ]);

        $this->postJson('/api/v1/me/rewards/'.$platinumOnly->code.'/claim', [
            'idempotency_key' => 'plat-only-1',
        ])->assertStatus(422)->assertJsonPath('error.code', 'reward_grade_not_eligible');

        Event::assertDispatchedTimes(RewardClaimed::class, 5);
    }
}
