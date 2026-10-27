<?php

declare(strict_types=1);

namespace Tests\Feature\Draw;

use App\Enums\Currency;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Agent\AgentCommissionSettlementService;
use App\Services\Draw\DrawCancellationService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Agent\Concerns\BuildsPaidCommissionCancellation;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Throwable;

final class OwnedDrawCommissionCancellationTest extends FinancialMasterTestCase
{
    use BuildsPaidCommissionCancellation;

    private function operator(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $actor = User::factory()->create();
        $actor->assignRole('admin');

        return $actor;
    }

    private function allState(): array
    {
        return [$this->snapshot(), DB::table('draws')->orderBy('id')->get()->toJson(),
            DB::table('draw_cancellation_ownerships')->orderBy('id')->get()->toJson()];
    }

    public static function paths(): array
    {
        $out = [];
        foreach ([Currency::THB, Currency::BDT, Currency::USD] as $currency) {
            foreach (['direct', 'both_paid', 'mixed'] as $path) {
                $out[$currency->value.'-'.$path] = [$currency, $path];
            }
        }

        return $out;
    }

    #[DataProvider('paths')]
    public function test_draw_refunds_original_member_and_only_original_owned_agent_payouts(Currency $currency, string $path): void
    {
        $f = $this->paidFixture($currency, $path !== 'direct', $path === 'mixed');
        $actor = $this->operator();
        $result = app(DrawCancellationService::class)->cancel($actor->id, $f['draw']->id, 'Synthetic local coordinated refund');
        $this->assertSame(1, $result['newly_refunded_count']);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['agentWallet']->fresh()->balance);
        $this->assertSame('0.00', $f['agent']->fresh()->total_commission_paid);
        $this->assertSame('0.00', $f['agent']->fresh()->total_commission_earned);
        $this->assertDatabaseCount('agent_commission_compensations', $path === 'both_paid' ? 2 : 1);
        if ($f['parent']) {
            $this->assertSame('0.00', Wallet::where('user_id', $f['parent']->user_id)->sole()->balance);
        }
        config(['agent.commission.paid_cancellation.enabled' => false]);
        $before = $this->allState();
        $replay = app(DrawCancellationService::class)->cancel($actor->id, $f['draw']->id, 'Synthetic local coordinated refund');
        $this->assertTrue($replay['replayed']);
        $this->assertSame($result['refund_transaction_ids'], $replay['refund_transaction_ids']);
        $this->assertSame($before, $this->allState());
    }

    public static function faults(): array
    {
        return [['policy_disabled'], ['no_historical_policy'], ['insufficient_agent_balance'], ['corrupt_payout'], ['corrupt_compensation_replay']];
    }

    #[DataProvider('faults')]
    public function test_unapproved_or_unprovable_agent_recovery_cannot_partially_cancel_the_draw(string $fault): void
    {
        $f = $fault === 'no_historical_policy' ? $this->commissionFixture(parent: false) : $this->paidFixture(parent: false);
        $actor = $this->operator();
        if ($fault === 'no_historical_policy') {
            app(AgentCommissionSettlementService::class)->settleForDraw($f['draw']->id);
            $this->enablePolicy();
        } elseif ($fault === 'policy_disabled') {
            config(['agent.commission.paid_cancellation.enabled' => false]);
        } elseif ($fault === 'insufficient_agent_balance') {
            app(WalletService::class)->debit($f['agentWallet'], Money::of('0.50', $f['agentWallet']->currency), idempotencyKey: 'synthetic-agent-exhaustion');
        } elseif ($fault === 'corrupt_payout') {
            DB::table('agent_commission_payouts')->update(['original_hash' => str_repeat('0', 64)]);
        } else {
            app(DrawCancellationService::class)->cancel($actor->id, $f['draw']->id, 'Synthetic local coordinated refund');
            DB::table('agent_commission_compensations')->update(['compensation_hash' => str_repeat('0', 64)]);
        }
        $before = $this->allState();
        try {
            app(DrawCancellationService::class)->cancel($actor->id, $f['draw']->id, 'Synthetic local coordinated refund');
            $this->fail('Unproven commission recovery accepted.');
        } catch (Throwable $e) {
            $this->assertNotInstanceOf(AssertionFailedError::class, $e);
            $this->assertSame($before, $this->allState());
        }
    }
}
