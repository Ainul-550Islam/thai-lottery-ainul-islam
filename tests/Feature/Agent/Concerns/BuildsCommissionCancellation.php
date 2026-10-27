<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\Concerns;

use App\DTOs\BetPurchaseData;
use App\DTOs\Betting\BetCancellationData;
use App\DTOs\Betting\BulkBetSelectionData;
use App\Enums\BetCancellationReason;
use App\Enums\Currency;
use App\Models\Agent;
use App\Models\User;
use App\Services\Betting\BetPurchaseService;
use App\Services\Finance\WalletService;

trait BuildsCommissionCancellation
{
    protected function commissionFixture(Currency $currency = Currency::THB, bool $parent = true): array
    {
        config(['lottery.betting.currency' => $currency->value, 'agent.hierarchy.enabled' => true,
            'agent.hierarchy.parent_commission_rate' => '0.0100']);
        $parent = $parent ? Agent::factory()->active()->create(['currency' => $currency]) : null;
        $agent = Agent::factory()->active()->create(['currency' => $currency, 'parent_agent_id' => $parent?->id]);
        $agentWallet = app(WalletService::class)->getOrCreateWallet($agent->user_id, $currency);
        if ($parent) {
            app(WalletService::class)->getOrCreateWallet($parent->user_id, $currency);
        }
        $user = User::factory()->create(['preferences' => ['referred_by_agent_id' => $agent->id]]);
        $wallet = app(WalletService::class)->getOrCreateWallet($user, $currency);
        $this->creditFixture($wallet, '100.00', 'Synthetic coordinated funded purchase');
        $draw = $this->openBulkDrawForTest([new BulkBetSelectionData(marketKey: '3d_direct', number: '007', stake: '10.00')]);
        $result = app(BetPurchaseService::class)->purchase(new BetPurchaseData(userId: $user->id, drawId: $draw->id,
            marketKey: '3d_direct', rawNumber: '007', rawStake: '10.00', idempotencyKey: 'coordinated-member-purchase'));
        $bet = $result->bet;

        return compact('parent', 'agent', 'agentWallet', 'user', 'wallet', 'draw', 'result', 'bet');
    }

    protected function cancellation(array $f): BetCancellationData
    {
        return new BetCancellationData($f['user']->id, (string) $f['bet']->id, BetCancellationReason::PlayerRequest);
    }

    protected function secondPurchase(array $f)
    {
        $draw = $this->openBulkDrawForTest([new BulkBetSelectionData(marketKey: '3d_direct', number: '008', stake: '10.00')]);

        return app(BetPurchaseService::class)->purchase(new BetPurchaseData(userId: $f['user']->id, drawId: $draw->id,
            marketKey: '3d_direct', rawNumber: '008', rawStake: '10.00', idempotencyKey: 'coordinated-second-purchase'));
    }
}
