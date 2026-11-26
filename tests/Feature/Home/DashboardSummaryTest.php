<?php

declare(strict_types=1);

namespace Tests\Feature\Home;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\WalletType;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\User;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Home\PlayerDashboardSummaryService;
use Database\Seeders\CashbackPolicySeeder;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            LedgerAccountSeeder::class,
            CashbackPolicySeeder::class,
        ]);
    }

    public function test_composes_summary_caches_per_member_isolates_owners_and_avoids_n_plus_one_queries(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $charlie = User::factory()->create();

        $wallets = app(WalletService::class);
        $aliceWallet = $wallets->getOrCreateWallet($alice, Currency::THB, WalletType::Primary);
        $wallets->getOrCreateWallet($alice, Currency::THB, WalletType::Bonus);
        $wallets->getOrCreateWallet($alice, Currency::THB, WalletType::Commission);

        $charlieWallet = $wallets->getOrCreateWallet($charlie, Currency::THB, WalletType::Primary);
        $wallets->getOrCreateWallet($charlie, Currency::THB, WalletType::Bonus);
        $wallets->getOrCreateWallet($charlie, Currency::THB, WalletType::Commission);

        for ($i = 1; $i <= 2; $i++) {
            $wallets->credit(
                $aliceWallet,
                Money::of('75.00', Currency::THB),
                FinancialTransactionType::Deposit,
                sprintf('dash-alice-seed-%04d', $i),
            );
        }

        for ($i = 1; $i <= 20; $i++) {
            $wallets->credit(
                $charlieWallet,
                Money::of('50.00', Currency::THB),
                FinancialTransactionType::Deposit,
                sprintf('dash-charlie-seed-%04d', $i),
            );
        }

        $draw = Draw::factory()->open()->create(['scheduled_at' => now()->addHours(2)]);
        Bet::factory()->count(2)->active()->create([
            'user_id' => $alice->getKey(),
            'draw_id' => $draw->getKey(),
        ]);
        Bet::factory()->count(1)->won('120.00')->create([
            'user_id' => $alice->getKey(),
            'draw_id' => $draw->getKey(),
        ]);

        Bet::factory()->count(15)->active()->create([
            'user_id' => $charlie->getKey(),
            'draw_id' => $draw->getKey(),
        ]);
        Bet::factory()->count(10)->won('90.00')->create([
            'user_id' => $charlie->getKey(),
            'draw_id' => $draw->getKey(),
        ]);

        Sanctum::actingAs($alice);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/v1/me/dashboard-summary')
            ->assertOk()
            ->assertJsonPath('data.member_id', $alice->getKey())
            ->assertJsonPath('data.wallets.main.balance', '150.00')
            ->assertJsonPath('data.winnings.total_won', '120.00')
            ->assertJsonPath('data.bets.total_bets', 3)
            ->assertJsonPath('data.bets.active_bets', 2)
            ->assertJsonStructure([
                'data' => [
                    'member_id',
                    'currency',
                    'wallets' => ['main', 'bonus', 'commission'],
                    'winnings' => ['total_won', 'won_bets_count', 'currency'],
                    'bets' => ['total_bets', 'active_bets', 'settled_bets'],
                    'loyalty' => ['points_balance'],
                    'vip',
                    'cashback',
                    'free_bet_vouchers',
                    'referrals',
                    'recent_activity',
                    'upcoming_draws',
                    'security',
                    'next_draw',
                ],
            ]);

        $aliceColdQueries = count(DB::getQueryLog());

        // Second request for Alice hits per-member cache (zero dashboard DB queries).
        DB::flushQueryLog();
        $this->getJson('/api/v1/me/dashboard-summary')
            ->assertOk()
            ->assertJsonPath('data.wallets.main.balance', '150.00');
        $cachedQueryCount = count(DB::getQueryLog());
        $this->assertLessThanOrEqual(2, $cachedQueryCount);

        // N+1 proof: Charlie has ~10x more bets (25 vs 3) and 10x more transactions (20 vs 2),
        // yet executes the exact same O(1) constant query count!
        Sanctum::actingAs($charlie);
        DB::flushQueryLog();
        $this->getJson('/api/v1/me/dashboard-summary')
            ->assertOk()
            ->assertJsonPath('data.member_id', $charlie->getKey())
            ->assertJsonPath('data.wallets.main.balance', '1000.00')
            ->assertJsonPath('data.winnings.total_won', '900.00')
            ->assertJsonPath('data.bets.total_bets', 25);
        $charlieColdQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($aliceColdQueries, $charlieColdQueries, 'Dashboard summary must execute O(1) constant queries with zero N+1 growth.');

        $dashboardService = app(PlayerDashboardSummaryService::class);
        $this->assertTrue(Cache::has($dashboardService->cacheKeyFor($alice)));
        $this->assertFalse(Cache::has($dashboardService->cacheKeyFor($bob)));

        // Owner isolation: Bob never sees Alice's or Charlie's cached summary
        Sanctum::actingAs($bob);
        $this->getJson('/api/v1/me/dashboard-summary')
            ->assertOk()
            ->assertJsonPath('data.member_id', $bob->getKey())
            ->assertJsonPath('data.wallets.main.balance', '0.00');

        // Explicit invalidation clears Alice's cache key immediately
        $dashboardService->invalidate($alice);
        $this->assertFalse(Cache::has($dashboardService->cacheKeyFor($alice)));
    }
}
