<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\DTOs\BetPurchaseData;
use App\Enums\AgentStatus;
use App\Enums\AuditAction;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\WalletStatus;
use App\Exceptions\BetDomainException;
use App\Exceptions\FinancialException;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Agent\AgentCommissionPayoutProof;
use App\Services\Agent\AgentCommissionService;
use App\Services\Agent\AgentCommissionSettlementService;
use App\Services\Agent\AgentSettlementService;
use App\Services\Betting\BetCancellationService;
use App\Services\Betting\BetPurchaseService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Agent\Concerns\BuildsCommissionCancellation;
use Tests\Feature\Finance\FinancialMasterTestCase;

class OwnedCommissionPayoutTest extends FinancialMasterTestCase
{
    use BuildsCommissionCancellation;

    public static function currencies(): array
    {
        return [[Currency::THB], [Currency::USD], [Currency::BDT]];
    }

    protected function drawPay(array $f)
    {
        return app(AgentCommissionSettlementService::class)->settleForDraw($f['draw']->id);
    }

    protected function snapshot(): array
    {
        $result = [];
        foreach (['wallets', 'agents', 'agent_commissions', 'agent_commission_payouts', 'bet_purchase_ownerships',
            'bet_commission_ownerships', 'financial_transactions', 'ledger_entries', 'ledger_accounts', 'audit_logs'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $result;
    }

    #[DataProvider('currencies')]
    public function test_owned_direct_and_parent_draw_payout_then_cross_ingress_replay(Currency $currency): void
    {
        $f = $this->commissionFixture($currency);
        $result = $this->drawPay($f);
        $this->assertSame(2, $result->commissionsSettled);
        $this->assertSame('0.60', $result->totalCommissionPaid);
        $this->assertSame($currency->value, $result->currency);
        $this->assertSame('0.50', $f['agentWallet']->fresh()->balance);
        $this->assertSame('0.10', Wallet::where('user_id', $f['parent']->user_id)->sole()->balance);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.50', $f['agent']->fresh()->total_commission_earned);
        $this->assertSame('0.50', $f['agent']->fresh()->total_commission_paid);
        $this->assertDatabaseCount('agent_commission_payouts', 2);
        $before = $this->snapshot();
        $this->assertTrue($this->drawPay($f)->alreadySettled);
        $this->assertSame(0, app(AgentSettlementService::class)->settleForAgent($f['agent'])['settled_count']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_manual_first_then_draw_uses_one_original_key_per_commission(): void
    {
        $f = $this->commissionFixture();
        $manual = app(AgentSettlementService::class)->settleForAgent($f['agent'], $f['user']->id);
        $this->assertSame(['settled_count' => 1, 'total_amount' => '0.50', 'currency' => 'THB'], $manual);
        $this->assertSame(1, $this->drawPay($f)->commissionsSettled);
        $this->assertSame(['draw', 'manual'], DB::table('agent_commission_payouts')->orderBy('mode')->pluck('mode')->all());
        $this->assertDatabaseCount('financial_transactions', 4);
        $this->assertSame(2, DB::table('bet_commission_ownerships')->where('state', 'active')->count());
    }

    public function test_facade_uses_real_delegate_names_and_list_contracts(): void
    {
        $f = $this->commissionFixture();
        $facade = app(AgentCommissionService::class);
        $this->assertCount(2, $facade->accrueForBet($f['bet']));
        $this->assertSame(2, $facade->settleForDraw($f['draw']->id)->commissionsSettled);
        $this->assertTrue($facade->settleForDraw($f['draw']->id)->alreadySettled);
        // Empty reversal is a list, not a nullable model. This does NOT certify
        // the separate legacy reversal writer or a coordinated paid member refund.
        $this->assertSame([], $facade->reverseForBet((new Bet)->forceFill(['id' => 999999]), 'No matching synthetic source'));
    }

    public function test_suspension_is_a_hold_then_reactivation_pays_the_original_source(): void
    {
        $f = $this->commissionFixture(parent: false);
        $f['agent']->forceFill(['status' => AgentStatus::Suspended])->save();
        $before = $this->snapshot();
        $out = $this->drawPay($f);
        $this->assertSame(1, $out->context['held_commissions']);
        $this->assertFalse($out->alreadySettled);
        $this->assertSame($before, $this->snapshot());
        $f['agent']->forceFill(['status' => AgentStatus::Active])->save();
        $this->assertSame(1, $this->drawPay($f)->commissionsSettled);
    }

    public function test_current_other_unowned_liability_is_preserved_but_never_adopted(): void
    {
        $f = $this->commissionFixture(parent: false);
        AgentCommission::factory()->create(['agent_id' => $f['agent']->id, 'commission_amount' => '0.50']);
        $f['agent']->forceFill(['total_commission_earned' => '1.00'])->save();
        $this->assertSame(1, $this->drawPay($f)->commissionsSettled);
        $this->assertSame('1.00', $f['agent']->fresh()->total_commission_earned);
        $this->assertSame('0.50', $f['agent']->fresh()->total_commission_paid);
        $this->assertDatabaseCount('agent_commission_payouts', 1);
        $before = $this->snapshot();
        try {
            app(AgentSettlementService::class)->settleForAgent($f['agent']);
            $this->fail('Unowned history was paid by aggregate availability.');
        } catch (FinancialException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_settled_winning_item_does_not_destroy_original_turnover_ownership(): void
    {
        $f = $this->commissionFixture(parent: false);
        $f['bet']->forceFill(['status' => BetStatus::Won])->save();
        DB::table('bet_items')->where('bet_id', $f['bet']->id)->update(['is_winner' => true, 'actual_payout' => '9000.00']);
        $this->assertSame(1, $this->drawPay($f)->commissionsSettled);
        $this->assertSame('0.50', $f['agentWallet']->fresh()->balance);
    }

    public static function faults(): array
    {
        return array_combine($names = ['earned_deficit', 'paid_ahead', 'negative_paid', 'missing_owner', 'missing_snapshot', 'terms',
            'changed_amount', 'changed_metadata', 'hidden', 'currency', 'missing_original_ledger', 'changed_bet', 'paid_pointer',
            'paid_stamp', 'reversed_stamp', 'orphan_credit', 'orphan_ledger', 'key_collision', 'cancelled_source', 'other_book_deficit', 'paid_borrows_pending'],
            array_map(fn ($name) => [$name], $names));
    }

    #[DataProvider('faults')]
    public function test_conflicting_history_or_book_never_receives_a_payout(string $fault): void
    {
        $f = $this->commissionFixture(parent: false);
        $row = AgentCommission::where('bet_id', $f['bet']->id)->sole();
        switch ($fault) {
            case 'earned_deficit': $f['agent']->forceFill(['total_commission_earned' => '0.49'])->save();
                break;
            case 'paid_ahead': $f['agent']->forceFill(['total_commission_paid' => '0.01'])->save();
                break;
            case 'negative_paid': $f['agent']->forceFill(['total_commission_paid' => '-0.01'])->save();
                break;
            case 'missing_owner': DB::table('bet_commission_ownerships')->delete();
                break;
            case 'missing_snapshot': DB::table('bet_purchase_ownerships')->update(['commission_snapshot_hash' => null, 'commission_snapshot_count' => null]);
                break;
            case 'terms': DB::table('bet_commission_ownerships')->update(['terms_hash' => str_repeat('0', 64)]);
                break;
            case 'changed_amount': $row->forceFill(['commission_amount' => '0.49'])->save();
                break;
            case 'changed_metadata': $row->forceFill(['metadata' => ['modified' => true]])->save();
                break;
            case 'hidden': $row->delete();
                break;
            case 'currency': $f['agent']->forceFill(['currency' => Currency::USD])->save();
                break;
            case 'missing_original_ledger': DB::table('ledger_entries')->where('financial_transaction_id', DB::table('bet_purchase_ownerships')->sole()->original_transaction_id)->delete();
                break;
            case 'changed_bet': $f['bet']->forceFill(['metadata' => ['changed' => true]])->save();
                break;
            case 'paid_pointer': $row->forceFill(['financial_transaction_id' => FinancialTransaction::first()->id])->save();
                break;
            case 'paid_stamp': $row->forceFill(['paid_at' => now()])->save();
                break;
            case 'reversed_stamp': $row->forceFill(['reversed_at' => now()])->save();
                break;
            case 'orphan_credit':
            case 'orphan_ledger':
            case 'key_collision':
                $tx = app(WalletService::class)->credit($f['agentWallet'], Money::of('0.50', Currency::THB), FinancialTransactionType::Commission,
                    $fault === 'key_collision' ? app(AgentCommissionPayoutProof::class)->key($row->id) : 'synthetic-existing-commission',
                    $fault === 'key_collision' ? [] : ['reference_type' => AgentCommission::class, 'reference_id' => $row->id]);
                if ($fault === 'orphan_ledger') {
                    DB::table('financial_transactions')->where('id', $tx->id)->update(['reference_type' => null, 'reference_id' => null]);
                }
                break;
            case 'cancelled_source': DB::table('bet_purchase_ownerships')->update(['state' => 'cancelled']);
                break;
            case 'other_book_deficit':
            case 'paid_borrows_pending':
                AgentCommission::factory()->create(['agent_id' => $f['agent']->id, 'commission_amount' => '0.50']);
                $f['agent']->forceFill(['total_commission_earned' => $fault === 'other_book_deficit' ? '0.50' : '1.00',
                    'total_commission_paid' => $fault === 'other_book_deficit' ? '0.00' : '0.20'])->save();
                break;
        }
        $before = $this->snapshot();
        try {
            $this->drawPay($f);
            $this->fail('Conflicting history authorized a fresh payout.');
        } catch (FinancialException) {
            $this->assertSame($before, $this->snapshot());
            $this->assertDatabaseCount('agent_commission_payouts', 0);
        }
    }

    public static function replayFaults(): array
    {
        return array_map(fn ($v) => [$v], ['receipt_missing', 'receipt_hash', 'receipt_stamp', 'pointer', 'journal', 'tx_reference', 'tx_metadata', 'reopened', 'receipt_amount', 'reopened_hidden']);
    }

    #[DataProvider('replayFaults')]
    public function test_paid_or_reopened_history_is_not_blindly_replayed_or_repaid(string $fault): void
    {
        $f = $this->commissionFixture(parent: false);
        $this->drawPay($f);
        $row = AgentCommission::where('bet_id', $f['bet']->id)->sole();
        match ($fault) {
            'receipt_missing' => DB::table('agent_commission_payouts')->delete(),
            'receipt_hash' => DB::table('agent_commission_payouts')->update(['original_hash' => str_repeat('0', 64)]),
            'receipt_stamp' => DB::table('agent_commission_payouts')->update(['paid_at' => now()->subDay()]),
            'pointer' => DB::table('agent_commissions')->where('id', $row->id)->update(['financial_transaction_id' => null]),
            'journal' => DB::table('ledger_entries')->where('financial_transaction_id', $row->financial_transaction_id)->delete(),
            'tx_reference' => DB::table('financial_transactions')->where('id', $row->financial_transaction_id)->update(['reference_id' => $row->id + 100]),
            'tx_metadata' => DB::table('financial_transactions')->where('id', $row->financial_transaction_id)->update(['metadata' => json_encode(['engine_type' => 'commission', 'commission_id' => $row->id + 100])]),
            'reopened' => DB::table('agent_commissions')->where('id', $row->id)->update(['status' => 'accrued', 'financial_transaction_id' => null, 'paid_at' => null]),
            'receipt_amount' => DB::table('agent_commission_payouts')->update(['amount' => '0.49']),
            'reopened_hidden' => $this->hideOldLabelsForReceiptTest($f, $row),
        };
        $before = $this->snapshot();
        try {
            $this->drawPay($f);
            $this->fail('Unproved paid history was accepted.');
        } catch (FinancialException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_late_audit_failure_rolls_back_both_wallet_credits_and_receipts(): void
    {
        $f = $this->commissionFixture();
        $before = $this->snapshot();
        AuditLog::creating(function (AuditLog $log): void {
            if ($log->action === AuditAction::Payout) {
                throw new RuntimeException('Synthetic late payout audit refusal');
            }
        });
        try {
            $this->drawPay($f);
            $this->fail('Late audit refusal was ignored.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic late payout audit refusal', $error->getMessage());
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_frozen_payout_wallet_never_receives_credit_or_receipt(): void
    {
        $f = $this->commissionFixture(parent: false);
        $f['agentWallet']->forceFill(['status' => WalletStatus::Frozen])->save();
        $before = $this->snapshot();
        try {
            $this->drawPay($f);
            $this->fail('Frozen wallet received a commission.');
        } catch (FinancialException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_payout_history_cannot_be_destroyed_by_ordinary_downgrade(): void
    {
        $f = $this->commissionFixture(parent: false);
        $this->drawPay($f);
        $migration = require database_path('migrations/2026_10_16_000500_create_agent_commission_payouts_table.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to erase durable original commission payout history.');
        $migration->down();
    }

    public function test_mixed_currency_draw_batch_rolls_back_and_each_agent_can_settle_in_its_own_currency(): void
    {
        $f = $this->commissionFixture(parent: false);
        config(['lottery.betting.currency' => 'USD']);
        $agent = Agent::factory()->active()->create(['currency' => Currency::USD]);
        $agentWallet = app(WalletService::class)->getOrCreateWallet($agent->user_id, Currency::USD);
        $user = User::factory()->create(['preferences' => ['referred_by_agent_id' => $agent->id]]);
        $wallet = app(WalletService::class)->getOrCreateWallet($user, Currency::USD);
        $this->creditFixture($wallet, '100.00', 'Synthetic second currency source');
        $this->provisionBetLimitForTest($f['draw'], '3d_direct', '008');
        app(BetPurchaseService::class)->purchase(new BetPurchaseData(
            userId: $user->id, drawId: $f['draw']->id, marketKey: '3d_direct', rawNumber: '008', rawStake: '10.00', idempotencyKey: 'owned-payout-second-currency'));
        $before = $this->snapshot();
        try {
            $this->drawPay($f);
            $this->fail('A mixed-currency total was presented as one amount.');
        } catch (FinancialException) {
            $this->assertSame($before, $this->snapshot());
        }
        $first = app(AgentSettlementService::class)->settleForAgent($f['agent']);
        $second = app(AgentSettlementService::class)->settleForAgent($agent);
        $this->assertSame('THB', $first['currency']);
        $this->assertSame('USD', $second['currency']);
        $this->assertSame('0.50', $agentWallet->fresh()->balance);
        $this->assertSame('0.50', $f['agentWallet']->fresh()->balance);
    }

    private function hideOldLabelsForReceiptTest(array $f, AgentCommission $row): void
    {
        DB::table('agent_commissions')->where('id', $row->id)->update(['status' => 'accrued', 'paid_at' => null, 'financial_transaction_id' => null]);
        DB::table('financial_transactions')->where('id', $row->financial_transaction_id)->update(['reference_type' => null, 'reference_id' => null, 'idempotency_key' => 'synthetic-relabeled-credit']);
        DB::table('ledger_entries')->where('financial_transaction_id', $row->financial_transaction_id)->update(['reference_type' => null, 'reference_id' => null]);
        $f['agent']->forceFill(['total_commission_earned' => '1.00'])->save();
    }

    public function test_durable_credit_receipt_blocks_member_refund_even_when_mutable_paid_labels_are_removed(): void
    {
        $f = $this->commissionFixture(parent: false);
        $this->drawPay($f);
        $row = AgentCommission::where('bet_id', $f['bet']->id)->sole();
        $this->hideOldLabelsForReceiptTest($f, $row);
        $before = $this->snapshot();
        try {
            app(BetCancellationService::class)->cancel($this->cancellation($f));
            $this->fail('Erased paid labels hid the durable original credit and authorized an unpaired refund.');
        } catch (BetDomainException $error) {
            $this->assertSame('bet_cancellation_commission_workflow_required', $error->errorCode());
            $this->assertSame($before, $this->snapshot());
        }
    }
}
