<?php

declare(strict_types=1);

namespace Tests\Feature\Draw;

use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Models\User;
use App\Services\Betting\BetCancellationService;
use App\Services\Draw\DrawCancellationService;
use App\Services\Finance\WalletService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Agent\Concerns\BuildsPaidCommissionCancellation;
use Tests\Feature\Betting\Concerns\BuildsOwnedBetPurchase;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\RunsServerFinancialRaces;

final class OwnedDrawCancellationServerRaceTest extends FinancialMasterTestCase
{
    use BuildsOwnedBetPurchase;
    use BuildsPaidCommissionCancellation;
    use RunsServerFinancialRaces;

    private function operator(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create();
        $actor->assignRole('admin');

        return $actor;
    }

    public static function modes(): array
    {
        return [['new'], ['replay'], ['paid'], ['paid_replay']];
    }

    #[DataProvider('modes')]
    public function test_two_coordinators_wait_on_the_first_original_wallet_and_share_exactly_one_refund(string $mode): void
    {
        $this->requireServerRace();
        $paid = str_starts_with($mode, 'paid');
        $f = $paid ? $this->paidFixture(parent: true) : $this->purchaseFixture();
        $actor = $this->operator();
        $replay = str_contains($mode, 'replay');
        if ($replay) {
            app(DrawCancellationService::class)->cancel($actor->id, $f['draw']->id, 'Synthetic local operator cancellation');
        }
        $command = ['action' => 'draw_cancel', 'actor_id' => $actor->id, 'draw_id' => $f['draw']->id];
        $config = $paid ? ['agent.commission.paid_cancellation.enabled' => true,
            'agent.commission.paid_cancellation.policy_reference' => 'synthetic-local-agent-contract-v1'] : [];
        $before = DB::table('financial_transactions')->count();
        $results = $this->serverRace([$command, $command], 'wallets', (int) DB::table('wallets')->min('id'), $config);
        $outcomes = array_column($results, 'outcome');
        sort($outcomes);
        $this->assertSame($replay ? ['draw_replayed', 'draw_replayed'] : ['draw_cancelled', 'draw_replayed'], $outcomes, json_encode($results));
        $this->assertSame($results[0]['ownership_id'], $results[1]['ownership_id']);
        $this->assertSame($results[0]['transaction_ids'], $results[1]['transaction_ids']);
        $this->assertSame(1, DB::table('draw_cancellation_ownerships')->count());
        $this->assertSame($before + ($replay ? 0 : ($paid ? 3 : 1)), DB::table('financial_transactions')->count());
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame(DrawStatus::Cancelled, $f['draw']->fresh()->status);
        if ($paid) {
            $this->assertSame('0.00', $f['agentWallet']->fresh()->balance);
            $this->assertSame(2, DB::table('agent_commission_compensations')->count());
        }
    }

    public function test_player_cancellation_racing_draw_cancellation_never_duplicates_the_refund(): void
    {
        $this->requireServerRace();
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        $results = $this->serverRace([
            ['action' => 'draw_cancel', 'actor_id' => $actor->id, 'draw_id' => $f['draw']->id],
            ['action' => 'bet_cancel', 'user_id' => $f['user']->id, 'bet_id' => $f['bet']->id],
        ], 'wallets', $f['wallet']->id);
        $this->assertSame('draw_cancelled', $results[0]['outcome'], json_encode($results));
        $this->assertContains($results[1]['outcome'], ['cancelled', 'replayed']);
        $this->assertSame([$results[1]['transaction_id']], $results[0]['transaction_ids']);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame(1, DB::table('financial_transactions')->where('idempotency_key', app(BetCancellationService::class)->refundKeyFor($f['bet']->id))->count());
    }

    public function test_cross_wallet_purchase_at_the_draw_lock_either_refuses_or_forces_a_safe_new_membership_attempt(): void
    {
        $this->requireServerRace();
        $f = $this->purchaseFixture();
        $actor = $this->operator();
        $buyer = User::factory()->create();
        $wallet = app(WalletService::class)->getOrCreateWallet($buyer, Currency::THB);
        $this->creditFixture($wallet, '100.00', 'cross-wallet draw boundary');
        $this->assertNotSame($f['wallet']->id, $wallet->id);
        $results = $this->serverRace([
            ['action' => 'draw_cancel', 'actor_id' => $actor->id, 'draw_id' => $f['draw']->id],
            ['action' => 'purchase_replay', 'user_id' => $buyer->id, 'draw_id' => $f['draw']->id, 'key' => 'cross-wallet-new-bet'],
        ], 'draws', $f['draw']->id);
        if ($results[0]['outcome'] === 'refused') {
            $this->assertSame('draw_cancellation_membership_changed', $results[0]['code'], json_encode($results));
            $this->assertSame('purchased', $results[1]['outcome']);
            $this->assertSame(DrawStatus::Open, $f['draw']->fresh()->status);
            $this->assertSame(0, DB::table('draw_cancellation_ownerships')->count());
            $this->assertSame('90.00', $f['wallet']->fresh()->balance);
            $this->assertSame('90.00', $wallet->fresh()->balance);
            // Explicit new root after a proven rollback. No unknown debit is resent.
            $retry = app(DrawCancellationService::class)->cancel($actor->id, $f['draw']->id, 'Synthetic local operator cancellation');
            $this->assertSame(2, $retry['newly_refunded_count']);
        } else {
            $this->assertSame('draw_cancelled', $results[0]['outcome'], json_encode($results));
            $this->assertSame('refused', $results[1]['outcome']);
            $this->assertSame(1, DB::table('bets')->where('draw_id', $f['draw']->id)->count());
        }
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame('100.00', $wallet->fresh()->balance);
        $this->assertSame(DrawStatus::Cancelled, $f['draw']->fresh()->status);
    }
}
