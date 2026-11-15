<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards;

use App\Enums\Currency;
use App\Enums\RewardType;
use App\Enums\WalletType;
use App\Events\Rewards\RewardClaimed;
use App\Exceptions\FinancialException;
use App\Models\LoyaltyPointLedger;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use App\Models\User;
use App\Services\Finance\WalletService;
use App\Services\Loyalty\LoyaltyPointsService;
use App\Services\Rewards\RewardRedemptionService;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class RewardRedemptionAtomicityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LedgerAccountSeeder::class);
    }

    public function test_fulfiller_failure_rolls_back_points_stock_claim_and_suppresses_event(): void
    {
        Event::fake([RewardClaimed::class]);

        $member = User::factory()->create();
        LoyaltyPointLedger::query()->create([
            'user_id' => $member->getKey(),
            'entry_type' => 'earn',
            'points' => 2500,
            'balance_after' => 2500,
            'source_type' => 'test_seed',
            'source_id' => 'atomicity-seed',
            'idempotency_key' => 'atomicity-seed-1',
            'description' => 'Seed points',
        ]);

        // Freeze the member's bonus wallet so BonusCashFulfiller throws FinancialException inside the transaction.
        $wallets = app(WalletService::class);
        $bonusWallet = $wallets->getOrCreateWallet($member, Currency::THB, WalletType::Bonus);
        $wallets->lockWallet($bonusWallet, 'Frozen for atomicity test');

        $item = RewardCatalogItem::query()->create([
            'code' => 'atomic_bonus',
            'name' => 'Atomic Bonus',
            'type' => RewardType::BonusCash,
            'points_cost' => 1000,
            'payload' => ['amount' => '100.00', 'currency' => 'THB'],
            'stock' => 5,
            'active' => true,
        ]);

        $thrown = false;
        try {
            app(RewardRedemptionService::class)->redeem($member, $item, 'atomic-fail-key-1');
        } catch (FinancialException) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $this->assertSame(2500, app(LoyaltyPointsService::class)->currentBalance($member));
        $this->assertSame(5, $item->fresh()?->stock);
        $this->assertSame(0, RewardClaim::query()->where('member_id', $member->getKey())->count());
        Event::assertNotDispatched(RewardClaimed::class);
    }
}
