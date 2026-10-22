<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\DTOs\Finance\FinancialHoldData;
use App\Enums\AuditAction;
use App\Enums\BetStatus;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Enums\WalletStatus;
use App\Exceptions\BetDomainException;
use App\Exceptions\FinancialException;
use App\Models\AgentCommission;
use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use App\Services\Agent\AgentCommissionSettlementService;
use App\Services\Betting\BetCancellationService;
use App\Services\Finance\FinancialHoldService;
use App\Services\Finance\FinancialReversalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Agent\Concerns\BuildsPaidCommissionCancellation;
use Tests\Feature\Finance\FinancialMasterTestCase;

final class PaidCommissionCancellationTest extends FinancialMasterTestCase
{
    use BuildsPaidCommissionCancellation;

    public static function paths(): array
    {
        $out = [];
        foreach ([Currency::THB, Currency::USD, Currency::BDT] as $currency) {
            foreach (['direct', 'both_paid', 'mixed'] as $path) {
                $out[$currency->value.'-'.$path] = [$currency, $path];
            }
        }

        return $out;
    }

    #[DataProvider('paths')]
    public function test_refund_compensates_exact_owned_originals_and_replays_without_movement(Currency $currency, string $path): void
    {
        $f = $this->paidFixture($currency, $path !== 'direct', $path === 'mixed');
        $this->assertFalse(app(BetCancellationService::class)->cancel($this->cancellation($f))->isReplay());
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['agentWallet']->fresh()->balance);
        $this->assertSame('0.00', $f['agent']->fresh()->total_commission_paid);
        $this->assertSame('0.00', $f['agent']->fresh()->total_commission_earned);
        $this->assertDatabaseCount('agent_commission_compensations', $path === 'both_paid' ? 2 : 1);
        if ($f['parent']) {
            $this->assertSame('0.00', $f['parent']->fresh()->total_commission_earned);
            $this->assertSame('0.00', Wallet::where('user_id', $f['parent']->user_id)->sole()->balance);
        }
        $this->assertSame(BetStatus::Cancelled, $f['bet']->fresh()->status);
        $this->assertSame(0, DB::table('bet_commission_ownerships')->where('state', 'active')->count());
        $before = $this->snapshot();
        config(['agent.commission.paid_cancellation.enabled' => false]);
        $this->assertTrue(app(BetCancellationService::class)->cancel($this->cancellation($f))->isReplay());
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_later_policy_cannot_retroactively_enroll_a_previously_paid_credit(): void
    {
        $f = $this->commissionFixture(parent: false);
        app(AgentCommissionSettlementService::class)->settleForDraw($f['draw']->id);
        $this->enablePolicy();
        $before = $this->snapshot();
        try {
            app(BetCancellationService::class)->cancel($this->cancellation($f));
            $this->fail('Historical policy was adopted.');
        } catch (BetDomainException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_other_owned_paid_and_unowned_pending_receivables_remain_intact(): void
    {
        $f = $this->paidFixture(parent: false);
        $second = $this->secondPurchase($f);
        app(AgentCommissionSettlementService::class)->settleForDraw($second->bet->draw_id);
        AgentCommission::factory()->create(['agent_id' => $f['agent']->id, 'commission_amount' => '0.50']);
        $f['agent']->forceFill(['total_commission_earned' => '1.50'])->save();
        app(BetCancellationService::class)->cancel($this->cancellation($f));
        $this->assertSame('0.50', $f['agentWallet']->fresh()->balance);
        $this->assertSame('0.50', $f['agent']->fresh()->total_commission_paid);
        $this->assertSame('1.00', $f['agent']->fresh()->total_commission_earned);
        $this->assertSame(CommissionStatus::Paid, AgentCommission::where('bet_id', $second->bet->id)->sole()->status);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
    }

    public static function faults(): array
    {
        return array_map(fn ($v) => [$v], ['policy_disabled', 'policy_changed', 'snapshot_policy_missing', 'receipt_hash', 'source_hash',
            'source_missing', 'source_amount', 'paid_counter', 'earned_counter', 'wallet_spent', 'wallet_frozen', 'member_frozen',
            'hold', 'hold_floor_deficit', 'original_journal', 'original_pointer', 'original_metadata', 'original_reference', 'hidden_original',
            'parent_spent', 'counter_borrows_pending', 'unknown_reversal', 'orphan_mirror']);
    }

    #[DataProvider('faults')]
    public function test_any_unapproved_unproved_or_unavailable_leg_preserves_every_other_leg(string $fault): void
    {
        $f = $this->paidFixture();
        $row = AgentCommission::where('agent_id', $f['agent']->id)->sole();
        switch ($fault) {
            case 'policy_disabled': config(['agent.commission.paid_cancellation.enabled' => false]);
                break;
            case 'policy_changed': config(['agent.commission.paid_cancellation.policy_reference' => 'synthetic-different-contract-v2']);
                break;
            case 'snapshot_policy_missing': DB::table('agent_commission_payouts')->where('commission_id', $row->id)->update(['cancellation_policy' => null]);
                break;
            case 'receipt_hash': DB::table('agent_commission_payouts')->where('commission_id', $row->id)->update(['original_hash' => str_repeat('0', 64)]);
                break;
            case 'source_hash': DB::table('bet_commission_ownerships')->where('commission_id', $row->id)->update(['terms_hash' => str_repeat('0', 64)]);
                break;
            case 'source_missing': DB::table('bet_purchase_ownerships')->update(['commission_snapshot_hash' => null]);
                break;
            case 'source_amount': $row->forceFill(['commission_amount' => '0.49'])->save();
                break;
            case 'paid_counter': $f['agent']->forceFill(['total_commission_paid' => '0.49'])->save();
                break;
            case 'earned_counter': $f['agent']->forceFill(['total_commission_earned' => '0.49'])->save();
                break;
            case 'wallet_spent': $this->debitFixture($f['agentWallet'], '0.50', 'Synthetic spent commission');
                break;
            case 'parent_spent': $this->debitFixture(Wallet::where('user_id', $f['parent']->user_id)->sole(), '0.10', 'Synthetic spent parent commission');
                break;
            case 'wallet_frozen': $f['agentWallet']->forceFill(['status' => WalletStatus::Frozen])->save();
                break;
            case 'member_frozen': $f['wallet']->forceFill(['status' => WalletStatus::Frozen])->save();
                break;
            case 'hold':
            case 'hold_floor_deficit':
                app(FinancialHoldService::class)->place(FinancialHoldData::fromInput($f['agentWallet']->id, '0.50', 'THB', 'Synthetic owned compliance hold', 'paid-cancellation-owned-hold'));
                if ($fault === 'hold_floor_deficit') {
                    DB::table('wallets')->where('id', $f['agentWallet']->id)->update(['locked_balance' => '0.00']);
                }
                break;
            case 'original_journal': DB::table('ledger_entries')->where('financial_transaction_id', $row->financial_transaction_id)->delete();
                break;
            case 'original_pointer': $row->forceFill(['financial_transaction_id' => null])->save();
                break;
            case 'original_metadata': DB::table('financial_transactions')->where('id', $row->financial_transaction_id)->update(['metadata' => '{}']);
                break;
            case 'original_reference': DB::table('financial_transactions')->where('id', $row->financial_transaction_id)->update(['reference_id' => 999999]);
                break;
            case 'hidden_original': FinancialTransaction::findOrFail($row->financial_transaction_id)->delete();
                break;
            case 'counter_borrows_pending': AgentCommission::factory()->create(['agent_id' => $f['agent']->id, 'commission_amount' => '0.50']);
                break;
            case 'orphan_mirror':
                $entry = LedgerEntry::where('financial_transaction_id', $row->financial_transaction_id)->firstOrFail()->replicate();
                $entry->financial_transaction_id = FinancialTransaction::where('type', TransactionType::BetPlacement)->sole()->id;
                $entry->reference_type = FinancialTransaction::class;
                $entry->reference_id = $row->financial_transaction_id;
                $entry->save();
                break;
            case 'unknown_reversal':
                $tx = new FinancialTransaction;
                $tx->forceFill(['uuid' => (string) Str::uuid(), 'reference_number' => 'SYNTHETIC-UNKNOWN-REV', 'type' => 'reversal', 'status' => 'processing',
                    'user_id' => $f['agent']->user_id, 'wallet_id' => $f['agentWallet']->id, 'currency' => 'THB', 'amount' => '0.50', 'fee' => '0.00',
                    'idempotency_key' => app(FinancialReversalService::class)->reversalKeyFor(FinancialTransaction::findOrFail($row->financial_transaction_id)),
                    'reference_type' => FinancialTransaction::class, 'reference_id' => $row->financial_transaction_id])->save();
                break;
        }
        $before = $this->snapshot();
        try {
            app(BetCancellationService::class)->cancel($this->cancellation($f));
            $this->fail('A bad paid leg authorized a partial refund or debit.');
        } catch (BetDomainException|FinancialException) {
            $this->assertSame($before, $this->snapshot());
            $this->assertDatabaseCount('agent_commission_compensations', 0);
        }
    }

    public static function replayFaults(): array
    {
        return array_map(fn ($v) => [$v], ['fact_hash', 'reversal_hash', 'receipt_policy', 'reversal_stamp', 'reopened', 'missing_mirror', 'comp_reference', 'original_reopened', 'source_stamp', 'orphan_mirror', 'comp_reversed_stamp']);
    }

    #[DataProvider('replayFaults')]
    public function test_closed_compensation_replay_requires_its_exact_terminal_history(string $fault): void
    {
        $f = $this->paidFixture(parent: false);
        app(BetCancellationService::class)->cancel($this->cancellation($f));
        $closed = DB::table('agent_commission_compensations')->sole();
        switch ($fault) {
            case 'fact_hash': DB::table('agent_commission_compensations')->update(['payout_fact_hash' => str_repeat('0', 64)]);
                break;
            case 'reversal_hash': DB::table('agent_commission_compensations')->update(['compensation_hash' => str_repeat('0', 64)]);
                break;
            case 'receipt_policy': DB::table('agent_commission_payouts')->update(['cancellation_policy' => 'synthetic-changed-contract']);
                break;
            case 'reversal_stamp': DB::table('agent_commissions')->update(['reversed_at' => now()->subDay()]);
                break;
            case 'reopened': DB::table('agent_commissions')->update(['status' => 'paid', 'reversed_at' => null]);
                break;
            case 'missing_mirror': DB::table('ledger_entries')->where('financial_transaction_id', $closed->compensation_transaction_id)->delete();
                break;
            case 'comp_reference': DB::table('financial_transactions')->where('id', $closed->compensation_transaction_id)->update(['reference_id' => 999999]);
                break;
            case 'original_reopened': DB::table('financial_transactions')->where('id', $closed->original_transaction_id)->update(['status' => 'completed', 'reversed_at' => null]);
                break;
            case 'comp_reversed_stamp': DB::table('financial_transactions')->where('id', $closed->compensation_transaction_id)->update(['reversed_at' => now()]);
                break;
            case 'orphan_mirror':
                $entry = LedgerEntry::where('financial_transaction_id', $closed->compensation_transaction_id)->firstOrFail()->replicate();
                $entry->financial_transaction_id = FinancialTransaction::orderBy('id')->firstOrFail()->id;
                $entry->save();
                break;
            case 'source_stamp': DB::table('bet_commission_ownerships')->update(['cancelled_at' => now()->subDay()]);
                break;
        }
        $before = $this->snapshot();
        try {
            app(BetCancellationService::class)->cancel($this->cancellation($f));
            $this->fail('Corrupt terminal compensation was accepted.');
        } catch (BetDomainException|FinancialException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_late_member_audit_failure_rolls_back_all_original_debits_and_refund(): void
    {
        $f = $this->paidFixture();
        $before = $this->snapshot();
        AuditLog::creating(function (AuditLog $log): void {
            if ($log->action === AuditAction::CancelBet) {
                throw new RuntimeException('Synthetic final member audit refusal');
            }
        });
        try {
            app(BetCancellationService::class)->cancel($this->cancellation($f));
            $this->fail('Late audit was ignored.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic final member audit refusal', $error->getMessage());
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_actual_authenticated_http_member_refund_includes_paid_compensation(): void
    {
        $f = $this->paidFixture();
        $this->actingAs($f['user'])->postJson('/api/v1/bets/'.$f['bet']->id.'/cancel', ['reason' => 'player_request'])->assertOk();
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('agent_commission_compensations', 2);
    }

    public function test_generic_reversal_cannot_reverse_a_managed_original_or_its_compensation(): void
    {
        $f = $this->paidFixture(parent: false);
        $row = AgentCommission::where('bet_id', $f['bet']->id)->sole();
        $service = app(FinancialReversalService::class);
        $before = $this->snapshot();
        try {
            $service->reverse(FinancialTransaction::findOrFail($row->financial_transaction_id));
            $this->fail('Managed payout was reversed independently.');
        } catch (FinancialException $error) {
            $this->assertSame('reversal_requires_domain_workflow', $error->errorCode());
            $this->assertSame($before, $this->snapshot());
        }
        app(BetCancellationService::class)->cancel($this->cancellation($f));
        $before = $this->snapshot();
        try {
            $service->reverse(FinancialTransaction::findOrFail(DB::table('agent_commission_compensations')->sole()->compensation_transaction_id));
            $this->fail('Managed compensation was undone independently.');
        } catch (FinancialException $error) {
            $this->assertSame('reversal_requires_domain_workflow', $error->errorCode());
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_policy_snapshot_blocks_downgrade_even_before_compensation(): void
    {
        $this->paidFixture(parent: false);
        $migration = require database_path('migrations/2026_10_16_000600_create_agent_commission_compensations_table.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }
}
