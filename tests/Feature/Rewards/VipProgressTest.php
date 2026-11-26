<?php

declare(strict_types=1);

namespace Tests\Feature\Rewards;

use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WalletType;
use App\Http\Controllers\Api\V1\AccountGradeApiController;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Finance\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class VipProgressTest extends TestCase
{
    use RefreshDatabase;

    private function recordQualifyingSpend(User $member, string $amount, string $suffix): void
    {
        $wallet = app(WalletService::class)->getOrCreateWallet($member, Currency::THB, WalletType::Primary);

        FinancialTransaction::unguarded(static function () use ($member, $wallet, $amount, $suffix): void {
            FinancialTransaction::query()->create([
                'reference_number' => 'TX-VIP-'.$member->getKey().'-'.$suffix,
                'user_id' => $member->getKey(),
                'wallet_id' => $wallet->getKey(),
                'type' => TransactionType::BetPlacement,
                'status' => TransactionStatus::Completed,
                'currency' => Currency::THB,
                'amount' => $amount,
                'fee' => '0.00',
                'description' => 'Qualifying wager spend',
                'idempotency_key' => 'vip-threshold-'.$member->getKey().'-'.$suffix,
                'processed_at' => Carbon::now()->subMinutes(5),
            ]);
        });
    }

    public function test_vip_progress_matches_canonical_account_grade_api_controller_and_tier_boundaries(): void
    {
        // 1. Base (0.00 spend -> bronze, next_level = gold_plus at 200.00)
        $baseMember = User::factory()->create();
        Sanctum::actingAs($baseMember);

        $vipResponse = $this->getJson('/api/v1/me/vip/progress')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.level', 'bronze')
            ->assertJsonPath('data.next_level', 'gold_plus')
            ->assertJsonPath('data.progress_percent', '0.00');

        $gradeRequest = Request::create('/api/v1/account/grade', 'GET');
        $gradeRequest->setUserResolver(static fn (): User => $baseMember);
        $gradeJson = app(AccountGradeApiController::class)->show($gradeRequest)->getData(true);

        $this->assertSame($gradeJson['data']['current_grade']['key'], $vipResponse->json('data.current_grade.key'));
        $this->assertSame($gradeJson['data']['next_grade']['key'] ?? null, $vipResponse->json('data.next_grade.key'));

        // 2. Just below Gold Plus threshold (199.99 -> still bronze, spend_needed = 0.01)
        $belowBoundary = User::factory()->create();
        $this->recordQualifyingSpend($belowBoundary, '199.99', 'b1');
        Sanctum::actingAs($belowBoundary);
        $this->getJson('/api/v1/me/vip/progress')
            ->assertOk()
            ->assertJsonPath('data.level', 'bronze')
            ->assertJsonPath('data.next_level', 'gold_plus')
            ->assertJsonPath('data.spend_needed', '0.01')
            ->assertJsonPath('data.points_needed', 1);

        // 3. Exact Gold Plus threshold (200.00 -> gold_plus, next_level = platinum at 300.00)
        $exactBoundary = User::factory()->create();
        $this->recordQualifyingSpend($exactBoundary, '200.00', 'b2');
        Sanctum::actingAs($exactBoundary);
        $this->getJson('/api/v1/me/vip/progress')
            ->assertOk()
            ->assertJsonPath('data.level', 'gold_plus')
            ->assertJsonPath('data.next_level', 'platinum')
            ->assertJsonPath('data.spend_needed', '100.00');

        // 4. Top tier boundary (600.00 -> diamond_plus, next_level = null, progress_percent = 100.00)
        $topMember = User::factory()->create();
        $this->recordQualifyingSpend($topMember, '600.00', 'b3');
        Sanctum::actingAs($topMember);
        $this->getJson('/api/v1/me/vip/progress')
            ->assertOk()
            ->assertJsonPath('data.level', 'diamond_plus')
            ->assertJsonPath('data.next_level', null)
            ->assertJsonPath('data.next_grade', null)
            ->assertJsonPath('data.progress_percent', '100.00');
    }
}
