<?php

declare(strict_types=1);

namespace Tests\Feature\Betting;

use App\DTOs\BetPurchaseData;
use App\DTOs\Betting\BetCancellationData;
use App\Enums\AuditAction;
use App\Enums\BetCancellationReason;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Enums\TicketStatus;
use App\Enums\WalletStatus;
use App\Exceptions\BetDomainException;
use App\Exceptions\FinancialException;
use App\Exceptions\RiskException;
use App\Models\AgentCommission;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\NumberLimit;
use App\Models\User;
use App\Services\Betting\BetCancellationService;
use App\Services\Betting\BetPurchaseOwnership;
use App\Services\Betting\BetPurchaseService;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Risk\NumberLimitEngine;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Betting\Concerns\BuildsOwnedBetPurchase;
use Tests\Feature\Finance\FinancialMasterTestCase;

class BetCancellationOwnershipTest extends FinancialMasterTestCase
{
    use BuildsOwnedBetPurchase;

    public function test_real_purchase_refund_replay_and_repurchase_preserve_exact_owned_capacity(): void
    {
        $f = $this->purchaseFixture();
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame('active', $f['claim']->state);
        $service = app(BetCancellationService::class);
        $first = $service->cancel($this->cancelData($f));
        $this->assertFalse($first->isReplay());
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', NumberLimit::find($f['claim']->number_limit_id)->current_amount);
        $this->assertSame('0.00', NumberLimit::find($f['claim']->number_limit_id)->current_payout_exposure);
        $this->assertSame(BetStatus::Cancelled, $first->bet->status);
        $this->assertSame(TicketStatus::Cancelled, $first->ticket->status);
        $this->assertSame(Bet::class, $first->refund->reference_type);
        $again = $service->cancel(new BetCancellationData($f['user']->id, $f['bet']->uuid, BetCancellationReason::WrongStake, 'different-replay-client-key'));
        $this->assertTrue($again->isReplay());
        $this->assertSame($first->refund->id, $again->refund->id);
        $this->assertSame(BetCancellationReason::PlayerRequest, $again->reason);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame(1, AuditLog::where('action', 'cancel_bet')->count());
        $this->assertDatabaseCount('financial_transactions', 3);
        $this->assertDatabaseCount('ledger_entries', 6);
        app(BetPurchaseService::class)->purchase(new BetPurchaseData(userId: $f['user']->id, drawId: $f['draw']->id,
            marketKey: '3d_direct', rawNumber: '007', rawStake: '10.00', idempotencyKey: 'purchase-after-cancel'));
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame('10.00', NumberLimit::find($f['claim']->number_limit_id)->current_amount);
        $this->assertSame(1, DB::table('bet_purchase_ownerships')->where('state', 'active')->count());
    }

    public static function corruptionCases(): array
    {
        return array_map(fn ($case): array => [$case], ['missing_owner', 'owner_wallet', 'owner_amount', 'owner_hash', 'bet_stake', 'item_number',
            'original_status', 'original_amount', 'original_reference', 'original_journal', 'original_account', 'original_deleted',
            'risk_stake_deficit', 'risk_payout_deficit', 'refund_key_collision', 'operator_reason', 'foreign_owner', 'closed_draw', 'time_cutoff']);
    }

    #[DataProvider('corruptionCases')]
    public function test_conflicts_never_issue_money_or_release_capacity(string $case): void
    {
        $f = $this->purchaseFixture();
        $claim = $f['claim'];
        $data = $this->cancelData($f);
        switch ($case) {
            case 'missing_owner': DB::table('bet_purchase_receipts')->delete();
                DB::table('bet_purchase_ownerships')->delete();
                break;
            case 'owner_wallet': DB::table('bet_purchase_ownerships')->update(['wallet_id' => app(WalletService::class)->getOrCreateWallet(User::factory()->create(), Currency::THB)->id]);
                break;
            case 'owner_amount': DB::table('bet_purchase_ownerships')->update(['stake_amount' => '1.00']);
                break;
            case 'owner_hash': DB::table('bet_purchase_ownerships')->update(['terms_hash' => str_repeat('0', 64)]);
                break;
            case 'bet_stake': DB::table('bets')->where('id', $f['bet']->id)->update(['stake_amount' => '20.00']);
                break;
            case 'item_number': DB::table('bet_items')->where('bet_id', $f['bet']->id)->update(['number' => '999']);
                break;
            case 'original_status': DB::table('financial_transactions')->where('id', $claim->original_transaction_id)->update(['status' => 'pending']);
                break;
            case 'original_amount': DB::table('financial_transactions')->where('id', $claim->original_transaction_id)->update(['amount' => '1.00']);
                break;
            case 'original_reference': DB::table('financial_transactions')->where('id', $claim->original_transaction_id)->update(['reference_type' => 'bet']);
                break;
            case 'original_journal': DB::table('ledger_entries')->where('financial_transaction_id', $claim->original_transaction_id)->update(['amount' => '1.00']);
                break;
            case 'original_account': DB::table('ledger_accounts')->where('code', WalletService::ACCOUNT_BET_REVENUE)->update(['is_active' => false]);
                break;
            case 'original_deleted': DB::table('financial_transactions')->where('id', $claim->original_transaction_id)->update(['deleted_at' => now()]);
                break;
            case 'risk_stake_deficit': DB::table('number_limits')->where('id', $claim->number_limit_id)->update(['current_amount' => '0.00']);
                break;
            case 'risk_payout_deficit': DB::table('number_limits')->where('id', $claim->number_limit_id)->update(['current_payout_exposure' => '0.00']);
                break;
            case 'refund_key_collision': DB::table('financial_transactions')->where('id', FinancialTransaction::where('id', '!=', $claim->original_transaction_id)->value('id'))->update(['idempotency_key' => sprintf('bet-refund-%012d', $f['bet']->id)]);
                break;
            case 'operator_reason': $data = new BetCancellationData($f['user']->id, (string) $f['bet']->id, BetCancellationReason::Operator);
                break;
            case 'foreign_owner': $data = $this->cancelData($f, User::factory()->create()->id);
                break;
            case 'closed_draw': DB::table('draws')->where('id', $f['draw']->id)->update(['status' => 'closed']);
                break;
            case 'time_cutoff': DB::table('draws')->where('id', $f['draw']->id)->update(['betting_close_at' => now()->subSecond()]);
                break;
        }
        $before = DB::table('number_limits')->where('id', $claim->number_limit_id)->first();
        try {
            app(BetCancellationService::class)->cancel($data);
            $this->fail('Corrupt or unauthorized cancellation was accepted.');
        } catch (BetDomainException|RiskException $error) {
            $this->assertNotSame('', $error->errorCode());
        }
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame(BetStatus::Active, $f['bet']->fresh()->status);
        $this->assertEquals($before, DB::table('number_limits')->where('id', $claim->number_limit_id)->first());
        $this->assertDatabaseCount('financial_transactions', 2);
        $this->assertDatabaseCount('ledger_entries', 4);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_generic_release_cannot_consume_a_paid_purchase_owned_capacity(): void
    {
        $f = $this->purchaseFixture();
        try {
            DB::transaction(fn () => app(NumberLimitEngine::class)->release($f['draw']->id, $f['bet']->type, '007', Money::of('1.00', Currency::THB), '900'));
            $this->fail('Generic release consumed an active purchase owner.');
        } catch (RiskException $error) {
            $this->assertSame('bet_risk_ownership_conflict', $error->errorCode());
        }
        $this->assertSame('10.00', NumberLimit::find($f['claim']->number_limit_id)->current_amount);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
    }

    public static function replayCorruptions(): array
    {
        return array_map(fn ($case): array => [$case], ['refund_status', 'refund_amount', 'refund_journal', 'refund_wallet', 'refund_deleted', 'claim_refund', 'claim_hash', 'terminal_reason', 'terminal_stamp', 'original_journal']);
    }

    #[DataProvider('replayCorruptions')]
    public function test_terminal_replay_requires_original_and_exact_refund_proof(string $case): void
    {
        $f = $this->purchaseFixture();
        $outcome = app(BetCancellationService::class)->cancel($this->cancelData($f));
        switch ($case) {
            case 'refund_status': DB::table('financial_transactions')->where('id', $outcome->refund->id)->update(['status' => 'pending']);
                break;
            case 'refund_amount': DB::table('financial_transactions')->where('id', $outcome->refund->id)->update(['amount' => '1.00']);
                break;
            case 'refund_journal': DB::table('ledger_entries')->where('financial_transaction_id', $outcome->refund->id)->update(['amount' => '1.00']);
                break;
            case 'refund_wallet': DB::table('financial_transactions')->where('id', $outcome->refund->id)->update(['wallet_id' => null]);
                break;
            case 'refund_deleted': DB::table('financial_transactions')->where('id', $outcome->refund->id)->update(['deleted_at' => now()]);
                break;
            case 'claim_refund': DB::table('bet_purchase_ownerships')->update(['refund_transaction_id' => null]);
                break;
            case 'claim_hash': DB::table('bet_purchase_ownerships')->update(['refund_hash' => str_repeat('0', 64)]);
                break;
            case 'terminal_reason': DB::table('bets')->where('id', $f['bet']->id)->update(['cancelled_reason' => 'wrong_number']);
                break;
            case 'terminal_stamp': DB::table('bets')->where('id', $f['bet']->id)->update(['cancelled_at' => now()->subDay()]);
                break;
            case 'original_journal': DB::table('ledger_entries')->where('financial_transaction_id', $f['claim']->original_transaction_id)->update(['amount' => '1.00']);
                break;
        }
        try {
            app(BetCancellationService::class)->cancel($this->cancelData($f));
            $this->fail('Corrupt replay was accepted.');
        } catch (BetDomainException $error) {
            $this->assertSame('bet_cancellation_ownership_conflict', $error->errorCode());
        }
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', NumberLimit::find($f['claim']->number_limit_id)->current_amount);
        $this->assertDatabaseCount('financial_transactions', 3);
    }

    public static function currencies(): array
    {
        return [[Currency::THB], [Currency::USD], [Currency::BDT]];
    }

    #[DataProvider('currencies')]
    public function test_refund_uses_the_original_nonprimary_wallet_in_its_own_currency(Currency $currency): void
    {
        $f = $this->purchaseFixture(currency: $currency, bonus: true);
        $primary = app(WalletService::class)->getOrCreateWallet($f['user'], $currency);
        $this->assertNotSame($primary->id, $f['wallet']->id);
        $outcome = app(BetCancellationService::class)->cancel($this->cancelData($f));
        $this->assertSame($f['wallet']->id, $outcome->refund->wallet_id);
        $this->assertSame($currency->value, $outcome->currency);
        $this->assertSame('0.00', $primary->fresh()->balance);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
    }

    public function test_late_audit_failure_rolls_back_the_actual_refund_and_all_cancellation_state(): void
    {
        $f = $this->purchaseFixture();
        $before = DB::table('number_limits')->where('id', $f['claim']->number_limit_id)->first();
        AuditLog::creating(function (AuditLog $audit): void {
            if ($audit->action === AuditAction::CancelBet) {
                throw new \RuntimeException('Injected cancellation audit failure after actual refund posting.');
            }
        });
        try {
            app(BetCancellationService::class)->cancel($this->cancelData($f));
            $this->fail('Late audit failure was swallowed.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Injected cancellation audit failure after actual refund posting.', $error->getMessage());
        }
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame(BetStatus::Active, $f['bet']->fresh()->status);
        $this->assertEquals($before, DB::table('number_limits')->where('id', $f['claim']->number_limit_id)->first());
        $this->assertSame('active', DB::table('bet_purchase_ownerships')->sole()->state);
        $this->assertNull(DB::table('bet_purchase_ownerships')->sole()->refund_transaction_id);
        $this->assertDatabaseCount('financial_transactions', 2);
        $this->assertDatabaseCount('ledger_entries', 4);
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function commissionStates(): array
    {
        return [['accrued'], ['paid'], ['hidden']];
    }

    #[DataProvider('commissionStates')]
    public function test_commission_history_requires_a_coordinated_workflow_instead_of_an_unpaired_member_refund(string $state): void
    {
        $f = $this->purchaseFixture();
        $commission = AgentCommission::factory()->create(['bet_id' => $f['bet']->id, 'status' => $state === 'hidden' ? 'accrued' : $state]);
        if ($state === 'hidden') {
            $commission->delete();
        }
        try {
            app(BetCancellationService::class)->cancel($this->cancelData($f));
            $this->fail('A member refund retained an attributed commission sale.');
        } catch (BetDomainException $error) {
            $this->assertSame('bet_cancellation_commission_workflow_required', $error->errorCode());
        }
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame('10.00', NumberLimit::find($f['claim']->number_limit_id)->current_amount);
        $this->assertSame(BetStatus::Active, $f['bet']->fresh()->status);
        $this->assertDatabaseCount('financial_transactions', 2);
    }

    public function test_new_sale_cannot_mask_an_existing_owned_risk_deficit(): void
    {
        $f = $this->purchaseFixture();
        DB::table('number_limits')->where('id', $f['claim']->number_limit_id)->update(['current_amount' => '0.00']);
        try {
            app(BetPurchaseService::class)->purchase(new BetPurchaseData(userId: $f['user']->id, drawId: $f['draw']->id,
                marketKey: '3d_direct', rawNumber: '007', rawStake: '10.00', idempotencyKey: 'must-not-mask-risk-deficit'));
            $this->fail('New stake hid an existing ownership deficit.');
        } catch (BetDomainException $error) {
            $this->assertStringContainsString('risk', $error->errorCode());
        }
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('bets', 1);
        $this->assertDatabaseCount('bet_purchase_ownerships', 1);
        $this->assertDatabaseCount('financial_transactions', 2);
    }

    public function test_expired_window_refuses_new_work_but_not_an_already_proved_refund_replay(): void
    {
        $f = $this->purchaseFixture();
        $outcome = app(BetCancellationService::class)->cancel($this->cancelData($f));
        $this->travel(16)->minutes();
        $again = app(BetCancellationService::class)->cancel($this->cancelData($f));
        $this->assertTrue($again->isReplay());
        $this->assertSame($outcome->refund->id, $again->refund->id);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
    }

    public static function managedTransactions(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('managedTransactions')]
    public function test_removing_a_header_label_does_not_permit_generic_purchase_or_refund_reversal(bool $refund): void
    {
        $f = $this->purchaseFixture();
        $id = $refund ? app(BetCancellationService::class)->cancel($this->cancelData($f))->refund->id : $f['claim']->original_transaction_id;
        DB::table('financial_transactions')->where('id', $id)->update(['reference_type' => null, 'reference_id' => null]);
        try {
            app(FinancialReversalService::class)->reverse(FinancialTransaction::findOrFail($id));
            $this->fail('Durable purchase/refund ownership was bypassed by relabeling.');
        } catch (FinancialException $error) {
            $this->assertSame('reversal_requires_domain_workflow', $error->errorCode());
        }
        $this->assertSame($refund ? '100.00' : '90.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', $refund ? 3 : 2);
    }

    public function test_ordinary_downgrade_cannot_erase_nonempty_payment_ownership_history(): void
    {
        $this->purchaseFixture();
        $migration = require database_path('migrations/2026_10_16_000310_create_bet_purchase_ownerships_table.php');
        try {
            $migration->down();
            $this->fail('Migration erased durable ownership history.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Refusing to erase', $error->getMessage());
        }
        $this->assertDatabaseCount('bet_purchase_ownerships', 1);
    }

    public function test_loaded_historical_purchase_cannot_be_enrolled_from_its_aggregate_counters(): void
    {
        $f = $this->purchaseFixture();
        DB::table('bet_purchase_receipts')->delete();
        DB::table('bet_purchase_ownerships')->delete();
        try {
            DB::transaction(fn () => app(BetPurchaseOwnership::class)->record(
                $f['bet']->fresh(), $f['wallet']->fresh(), $f['result']->transaction->fresh(), $f['result']->riskReservation));
            $this->fail('Loaded historical purchase was silently adopted.');
        } catch (BetDomainException $error) {
            $this->assertSame('bet_cancellation_ownership_conflict', $error->errorCode());
        }
        $this->assertDatabaseCount('bet_purchase_ownerships', 0);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
    }

    public function test_authenticated_http_endpoint_executes_one_refund_and_a_verified_replay(): void
    {
        $f = $this->purchaseFixture();
        $url = '/api/v1/bets/'.$f['bet']->id.'/cancel';
        $response = $this->actingAs($f['user'], 'sanctum')->postJson($url, ['reason' => 'player_request']);
        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.refund.amount', '10.00')->assertJsonPath('data.replayed', false);
        $this->postJson($url, [])->assertOk()->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.refund.transaction_id', $response->json('data.refund.transaction_id'));
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 3);
    }

    public function test_http_risk_deficit_is_a_safe_422_without_a_refund_or_generic_500(): void
    {
        $f = $this->purchaseFixture();
        DB::table('number_limits')->where('id', $f['claim']->number_limit_id)->update(['current_amount' => '0.00']);
        $this->actingAs($f['user'], 'sanctum')->postJson('/api/v1/bets/'.$f['bet']->id.'/cancel', [])
            ->assertStatus(422)->assertJsonPath('error.code', 'bet_risk_ownership_conflict');
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame(BetStatus::Active, $f['bet']->fresh()->status);
        $this->assertDatabaseCount('financial_transactions', 2);
    }

    public function test_http_financial_refusal_rolls_back_owned_risk_release_and_returns_a_redacted_422(): void
    {
        $f = $this->purchaseFixture();
        $f['wallet']->forceFill(['status' => WalletStatus::Frozen])->save();
        $this->actingAs($f['user'], 'sanctum')->postJson('/api/v1/bets/'.$f['bet']->id.'/cancel', [])
            ->assertStatus(422)->assertJsonPath('error.message', 'The cancellation could not be completed safely; preserve the current purchase for review.');
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame('active', DB::table('bet_purchase_ownerships')->sole()->state);
        $this->assertSame('10.00', NumberLimit::find($f['claim']->number_limit_id)->current_amount);
        $this->assertSame(BetStatus::Active, $f['bet']->fresh()->status);
        $this->assertDatabaseCount('financial_transactions', 2);
    }

    public function test_http_client_cannot_select_the_refund_amount_or_another_owner(): void
    {
        $f = $this->purchaseFixture();
        $this->actingAs($f['user'], 'sanctum')->postJson('/api/v1/bets/'.$f['bet']->id.'/cancel', ['stake_amount' => '100000.00', 'user_id' => 999999])
            ->assertStatus(422);
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame(BetStatus::Active, $f['bet']->fresh()->status);
        $this->assertDatabaseCount('financial_transactions', 2);
    }
}
