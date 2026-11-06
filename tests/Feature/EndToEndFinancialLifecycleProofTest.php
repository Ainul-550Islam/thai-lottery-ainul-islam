<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AgentStatus;
use App\Enums\WalletStatus;
use App\Exceptions\LegacyLotteryEntryException;
use App\Models\Agent;
use App\Models\Draw;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Deposit\DepositProcessingService;
use App\Services\Order\OrderPlacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Legacy lottery-entry sales is fail-closed until an audited migration and
 * settlement path exists. The former end-to-end chain (order, commission,
 * ingestion, settlement, payout) ran entirely on that closed path, so this
 * test guards the closure itself: a refused order must throw and must not
 * debit the player.
 */
class EndToEndFinancialLifecycleProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_order_placement_is_closed_and_leaves_the_wallet_untouched(): void
    {
        $user = User::factory()->create();
        $agentUser = User::factory()->create();

        $userWallet = new Wallet(['user_id' => $user->id, 'type' => 'main', 'currency' => 'THB']);
        $userWallet->status = WalletStatus::Active;
        $userWallet->save();

        $agent = Agent::create([
            'user_id' => $agentUser->id,
            'agent_code' => 'D-SHOP',
            'status' => AgentStatus::Active,
        ]);

        app(DepositProcessingService::class)->processConfirmedDeposit(
            userId: $user->id,
            amount: '10000.0000',
            gatewayProvider: 'PROMPTPAY',
            gatewayReferenceId: 'PROMETHEUS-PROOF-01',
            currency: 'THB'
        );
        $this->assertEquals('10000.0000', bcadd((string) $userWallet->fresh()->balance, '0', 4));

        $draw = Draw::create([
            'draw_code' => 'E2E-DRAW-001',
            'game_type' => 'THAI_6D',
            'draw_date' => now()->addHour(),
            'betting_starts_at' => now()->subHour(),
            'betting_ends_at' => now()->addMinutes(30),
            'status' => 'OPEN',
        ]);

        try {
            app(OrderPlacementService::class)->placeOrder(
                userId: $user->id,
                drawId: $draw->id,
                ticketNumber: '934521',
                betType: 'DIRECT_6D',
                stakeAmount: '1000.0000',
                agentId: $agent->id,
                idempotencyKey: 'ORDER-PROOF-001'
            );

            $this->fail('Legacy order placement must stay closed.');
        } catch (LegacyLotteryEntryException) {
            // Expected: the legacy path refuses before it touches any money.
        }

        $this->assertEquals('10000.0000', bcadd((string) $userWallet->fresh()->balance, '0', 4));
    }
}
