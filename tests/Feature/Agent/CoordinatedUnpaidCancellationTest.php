<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Enums\AuditAction;
use App\Enums\BetStatus;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\WalletStatus;
use App\Exceptions\BetDomainException;
use App\Models\AgentCommission;
use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\NumberLimit;
use App\Services\Agent\AgentCommissionAccrualService;
use App\Services\Agent\AgentCommissionSettlementService;
use App\Services\Agent\BetCommissionCancellation;
use App\Services\Betting\BetCancellationService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Agent\Concerns\BuildsCommissionCancellation;
use Tests\Feature\Betting\Concerns\BuildsOwnedBetPurchase;
use Tests\Feature\Finance\FinancialMasterTestCase;

class CoordinatedUnpaidCancellationTest extends FinancialMasterTestCase
{
    use BuildsCommissionCancellation;
    use BuildsOwnedBetPurchase;

    public static function currencies(): array
    {
        return [[Currency::THB], [Currency::USD], [Currency::BDT]];
    }

    #[DataProvider('currencies')]
    public function test_actual_member_refund_closes_direct_and_parent_unpaid_commissions_once(Currency $currency): void
    {
        $f = $this->commissionFixture($currency);
        $this->assertSame('0.50', $f['agent']->fresh()->total_commission_earned);
        $this->assertSame('0.10', $f['parent']->fresh()->total_commission_earned);
        $first = app(BetCancellationService::class)->cancel($this->cancellation($f));
        $again = app(BetCancellationService::class)->cancel($this->cancellation($f));
        $this->assertFalse($first->isReplay());
        $this->assertTrue($again->isReplay());
        $this->assertSame($first->refund->id, $again->refund->id);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        foreach ([$f['agent'], $f['parent']] as $agent) {
            $this->assertSame('0.00', $agent->fresh()->total_commission_earned);
            $this->assertSame('0.00', $agent->fresh()->total_commission_paid);
        }
        $this->assertSame(2, DB::table('bet_commission_ownerships')->where('state', 'cancelled')->count());
        $this->assertSame(2, AgentCommission::where('status', CommissionStatus::Cancelled)->count());
        $this->assertSame('0.00', NumberLimit::where('draw_id', $f['draw']->id)->sole()->current_amount);
        $this->assertDatabaseCount('financial_transactions', 3);
        $this->assertDatabaseCount('ledger_entries', 6);
    }

    public function test_earned_counter_retains_the_other_owned_sale(): void
    {
        $f = $this->commissionFixture(parent: false);
        $second = $this->secondPurchase($f);
        app(BetCancellationService::class)->cancel($this->cancellation($f));
        $this->assertSame('0.50', $f['agent']->fresh()->total_commission_earned);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame(CommissionStatus::Accrued, AgentCommission::where('bet_id', $second->bet->id)->sole()->status);
        $this->assertSame(1, DB::table('bet_commission_ownerships')->where('state', 'active')->count());
    }

    public function test_other_paid_commission_and_paid_counter_are_not_reclaimed(): void
    {
        $f = $this->commissionFixture(parent: false);
        $second = $this->secondPurchase($f);
        $other = AgentCommission::where('bet_id', $second->bet->id)->sole();
        app(AgentCommissionSettlementService::class)->settleForDraw($second->bet->draw_id);
        app(BetCancellationService::class)->cancel($this->cancellation($f));
        $this->assertSame(CommissionStatus::Paid, $other->fresh()->status);
        $this->assertSame('0.50', $f['agent']->fresh()->total_commission_earned);
        $this->assertSame('0.50', $f['agent']->fresh()->total_commission_paid);
        $this->assertSame('0.50', $f['agentWallet']->fresh()->balance);
    }

    public function test_known_unowned_receivable_is_preserved_without_historical_adoption(): void
    {
        $f = $this->commissionFixture(parent: false);
        $legacy = AgentCommission::factory()->create(['agent_id' => $f['agent']->id, 'commission_amount' => '2.00']);
        $f['agent']->forceFill(['total_commission_earned' => '2.50'])->save();
        app(BetCancellationService::class)->cancel($this->cancellation($f));
        $this->assertSame('2.00', $f['agent']->fresh()->total_commission_earned);
        $this->assertSame(CommissionStatus::Accrued, $legacy->fresh()->status);
        $this->assertDatabaseCount('bet_commission_ownerships', 1);
    }

    public static function corruptions(): array
    {
        return array_map(fn ($case): array => [$case], ['missing_claim', 'missing_snapshot', 'amount', 'terms', 'hidden', 'moved', 'currency',
            'agent_currency', 'agent_hidden', 'earned_deficit', 'paid_deficit', 'fake_paid', 'paid_pointer', 'orphan_credit', 'orphan_ledger', 'legacy_deficit']);
    }

    #[DataProvider('corruptions')]
    public function test_conflicting_or_paid_history_never_receives_a_member_only_refund(string $case): void
    {
        $f = $this->commissionFixture(parent: false);
        $row = AgentCommission::where('bet_id', $f['bet']->id)->sole();
        switch ($case) {
            case 'missing_claim': DB::table('bet_commission_ownerships')->delete();
                break;
            case 'missing_snapshot': DB::table('bet_purchase_ownerships')->update(['commission_snapshot_hash' => null, 'commission_snapshot_count' => null]);
                break;
            case 'amount': DB::table('agent_commissions')->where('id', $row->id)->update(['commission_amount' => '0.01']);
                break;
            case 'terms': DB::table('bet_commission_ownerships')->update(['terms_hash' => str_repeat('0', 64)]);
                break;
            case 'hidden': $row->delete();
                break;
            case 'moved': DB::table('agent_commissions')->where('id', $row->id)->update(['bet_id' => null]);
                break;
            case 'currency': DB::table('agent_commissions')->where('id', $row->id)->update(['currency' => 'USD']);
                break;
            case 'agent_currency': $f['agent']->forceFill(['currency' => Currency::USD])->save();
                break;
            case 'agent_hidden': $f['agent']->delete();
                break;
            case 'earned_deficit': $f['agent']->forceFill(['total_commission_earned' => '0.01'])->save();
                break;
            case 'paid_deficit': $f['agent']->forceFill(['total_commission_paid' => '0.50'])->save();
                break;
            case 'fake_paid': $row->forceFill(['status' => CommissionStatus::Paid])->save();
                break;
            case 'paid_pointer': $row->forceFill(['financial_transaction_id' => $f['result']->transaction->id])->save();
                break;
            case 'orphan_credit':
            case 'orphan_ledger':
                $tx = app(WalletService::class)->credit($f['agentWallet'], Money::of('0.50', Currency::THB), FinancialTransactionType::Commission,
                    'synthetic-orphan-commission', ['reference_type' => AgentCommission::class, 'reference_id' => $row->id]);
                if ($case === 'orphan_ledger') {
                    DB::table('financial_transactions')->where('id', $tx->id)->update(['reference_type' => null, 'reference_id' => null]);
                }
                break;
            case 'legacy_deficit':
                AgentCommission::factory()->create(['agent_id' => $f['agent']->id, 'commission_amount' => '2.00']);
                $f['agent']->forceFill(['total_commission_earned' => '2.00'])->save();
                break;
        }
        $earned = DB::table('agents')->where('id', $f['agent']->id)->value('total_commission_earned');
        $count = FinancialTransaction::withTrashed()->count();
        try {
            app(BetCancellationService::class)->cancel($this->cancellation($f));
            $this->fail('An unproved commission source authorized a member refund.');
        } catch (BetDomainException $error) {
            $this->assertSame('bet_cancellation_commission_workflow_required', $error->errorCode());
        }
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame(BetStatus::Active, $f['bet']->fresh()->status);
        $this->assertEquals($earned, DB::table('agents')->where('id', $f['agent']->id)->value('total_commission_earned'));
        $this->assertSame($count, FinancialTransaction::withTrashed()->count());
        $this->assertSame('10.00', NumberLimit::where('draw_id', $f['draw']->id)->sole()->current_amount);
    }

    public function test_late_audit_failure_rolls_back_commissions_and_real_refund_together(): void
    {
        $f = $this->commissionFixture();
        AuditLog::creating(function ($log): void {
            if ($log->action === AuditAction::CancelBet) {
                throw new \RuntimeException('Synthetic late coordinated audit failure.');
            }
        });
        try {
            app(BetCancellationService::class)->cancel($this->cancellation($f));
            $this->fail('Late failure was swallowed.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic late coordinated audit failure.', $error->getMessage());
        }
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.50', $f['agent']->fresh()->total_commission_earned);
        $this->assertSame('0.10', $f['parent']->fresh()->total_commission_earned);
        $this->assertSame(2, AgentCommission::where('status', CommissionStatus::Accrued)->count());
        $this->assertSame(2, DB::table('bet_commission_ownerships')->where('state', 'active')->count());
        $this->assertDatabaseCount('financial_transactions', 2);
        $this->assertDatabaseCount('ledger_entries', 4);
    }

    public function test_actual_paid_sale_remains_held_for_explicit_compensation(): void
    {
        $f = $this->commissionFixture();
        app(AgentCommissionSettlementService::class)->settleForDraw($f['draw']->id);
        $before = FinancialTransaction::count();
        $this->actingAs($f['user'], 'sanctum')->postJson('/api/v1/bets/'.$f['bet']->id.'/cancel', [])
            ->assertStatus(422)->assertJsonPath('error.code', 'bet_cancellation_commission_workflow_required');
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.50', $f['agentWallet']->fresh()->balance);
        $this->assertSame($before, FinancialTransaction::count());
    }

    public function test_repeated_accrual_does_not_duplicate_the_receivable(): void
    {
        $f = $this->commissionFixture();
        $rows = app(AgentCommissionAccrualService::class)->accrueForBet($f['bet']);
        $this->assertCount(2, $rows);
        $this->assertSame('0.50', $f['agent']->fresh()->total_commission_earned);
        $this->assertSame('0.10', $f['parent']->fresh()->total_commission_earned);
        $this->assertDatabaseCount('agent_commissions', 2);
    }

    public function test_terminal_replay_refuses_reopened_commission_without_a_second_refund(): void
    {
        $f = $this->commissionFixture(parent: false);
        app(BetCancellationService::class)->cancel($this->cancellation($f));
        DB::table('agent_commissions')->update(['status' => 'accrued']);
        try {
            app(BetCancellationService::class)->cancel($this->cancellation($f));
            $this->fail('Unpaired terminal commission state was accepted.');
        } catch (BetDomainException $error) {
            $this->assertSame('bet_cancellation_commission_workflow_required', $error->errorCode());
        }
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['agent']->fresh()->total_commission_earned);
        $this->assertDatabaseCount('financial_transactions', 3);
    }

    public function test_authenticated_http_request_cancels_both_member_purchase_and_owned_receivables(): void
    {
        $f = $this->commissionFixture();
        $this->actingAs($f['user'], 'sanctum')->postJson('/api/v1/bets/'.$f['bet']->id.'/cancel', [])
            ->assertOk()->assertJsonPath('data.refund.amount', '10.00')->assertJsonPath('data.replayed', false);
        $this->assertSame('0.00', $f['agent']->fresh()->total_commission_earned);
        $this->assertSame('0.00', $f['parent']->fresh()->total_commission_earned);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
    }

    public function test_nonempty_snapshot_history_cannot_be_downgraded_even_after_cancellation(): void
    {
        $f = $this->commissionFixture();
        app(BetCancellationService::class)->cancel($this->cancellation($f));
        $migration = require database_path('migrations/2026_10_16_000400_create_bet_commission_ownerships_table.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing to erase');
        $migration->down();
    }

    public function test_original_commission_snapshot_cannot_be_recaptured_after_funding(): void
    {
        $f = $this->commissionFixture();
        $purchase = DB::table('bet_purchase_ownerships')->where('bet_id', $f['bet']->id)->sole();
        try {
            DB::transaction(fn () => app(BetCommissionCancellation::class)->capture($f['bet'], $purchase->id));
            $this->fail('Initial commission membership was overwritten.');
        } catch (BetDomainException $error) {
            $this->assertSame('bet_cancellation_commission_workflow_required', $error->errorCode());
        }
        $this->assertEquals($purchase, DB::table('bet_purchase_ownerships')->where('id', $purchase->id)->sole());
    }

    public function test_member_wallet_refusal_restores_both_commission_levels(): void
    {
        $f = $this->commissionFixture();
        $f['wallet']->forceFill(['status' => WalletStatus::Frozen])->save();
        $this->actingAs($f['user'], 'sanctum')->postJson('/api/v1/bets/'.$f['bet']->id.'/cancel', [])->assertStatus(422);
        $this->assertSame('0.50', $f['agent']->fresh()->total_commission_earned);
        $this->assertSame('0.10', $f['parent']->fresh()->total_commission_earned);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame(2, DB::table('bet_commission_ownerships')->where('state', 'active')->count());
        $this->assertSame(2, AgentCommission::where('status', CommissionStatus::Accrued)->count());
        $this->assertDatabaseCount('financial_transactions', 2);
    }

    public function test_older_direct_purchase_with_no_commission_history_remains_usable_without_backfill(): void
    {
        $f = $this->purchaseFixture();
        DB::table('bet_purchase_ownerships')->where('bet_id', $f['bet']->id)->update(['commission_snapshot_hash' => null, 'commission_snapshot_count' => null]);
        $outcome = app(BetCancellationService::class)->cancel($this->cancelData($f));
        $this->assertSame('10.00', $outcome->refundAmount);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertNull(DB::table('bet_purchase_ownerships')->sole()->commission_snapshot_hash);
        $this->assertDatabaseCount('bet_commission_ownerships', 0);
    }
}
