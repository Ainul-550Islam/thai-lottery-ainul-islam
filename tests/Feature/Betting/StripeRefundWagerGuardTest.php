<?php

declare(strict_types=1);

namespace Tests\Feature\Betting;

use App\DTOs\Betting\BetAmendmentData;
use App\DTOs\Betting\BetCancellationData;
use App\DTOs\Betting\BulkBetSelectionData;
use App\Enums\BetCancellationReason;
use App\Enums\BetPurchaseStatus;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Exceptions\BetPurchaseException;
use App\Models\User;
use App\Services\Betting\BetAmendmentService;
use App\Services\Betting\BetCancellationService;
use App\Services\Betting\BetPurchaseService;
use App\Services\Betting\BetPurchaseTransactionService;
use App\Services\Betting\BulkBetService;
use App\Services\Betting\StripeRefundWagerGuard;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Payment\StripeRefundRecoveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\StripeRefundWagerFixture;

final class StripeRefundWagerGuardTest extends FinancialMasterTestCase
{
    use StripeRefundWagerFixture;

    private function refused(callable $call, string $code = 'bet_purchase_refund_exposure_pending'): void
    {
        $before = $this->refundWagerPaper();
        try {
            $call();
            $this->fail('Unrecovered or unproved refund exposure must not authorize a new paid wager.');
        } catch (BetPurchaseException $e) {
            $this->assertSame($code, $e->errorCode());
            $this->assertStringNotContainsString('SYNTHETICREVIEW', $e->getMessage());
        }
        $this->assertSame($before, $this->refundWagerPaper());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_actual_new_paid_purchase_refuses_before_any_risk_ticket_or_money_mutation(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        $this->refused(fn () => app(BetPurchaseService::class)->purchase($this->nextRefundBet($f)));
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        Http::assertSentCount(4);
    }

    public function test_prevalidated_context_and_preview_disable_cannot_bypass_current_exposure(): void
    {
        $f = $this->wagerRefundFixture();
        $ctx = $this->refundContext($f);
        $this->publishPendingRefund($f);
        config(['lottery.betting.risk_preview_enabled' => false]);
        $this->refused(fn () => app(BetPurchaseTransactionService::class)->execute($ctx));
        Http::assertSentCount(4);
    }

    public function test_same_currency_secondary_wallet_is_not_an_escape_route(): void
    {
        $f = $this->wagerRefundFixture();
        $other = $this->otherRefundWallet($f);
        $ctx = $this->refundContext($f, $other);
        $this->publishPendingRefund($f);
        $this->refused(fn () => app(BetPurchaseTransactionService::class)->execute($ctx));
        $this->assertSame('100.00', $other->fresh()->balance);
    }

    public function test_receipt_staleness_feature_disable_and_revoked_staff_never_expire_the_block(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        config(['payment.refund_recovery.stripe.enabled' => false, 'payment.deposit_review.stripe.enabled' => false]);
        $f['actor']->syncPermissions([]);
        $ctx = $this->refundContext($f);
        $this->travel(1)->days();
        try {
            $this->refused(fn () => app(BetPurchaseTransactionService::class)->execute($ctx));
        } finally {
            $this->travelBack();
        }
        Http::assertSentCount(4);
    }

    public function test_historical_paid_purchase_replay_does_not_become_new_wager_or_second_debit(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        $before = $this->refundWagerPaper();
        $out = app(BetPurchaseService::class)->purchase($f['data']);
        $this->assertTrue($out->isReplay());
        $this->assertSame($before, $this->refundWagerPaper());
        Http::assertSentCount(4);
    }

    public function test_owned_bet_cancellation_can_return_funds_and_then_recovery_unblocks_new_wager(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        app(BetCancellationService::class)->cancel(new BetCancellationData($f['user']->id, (string) $f['purchase']->bet->id, BetCancellationReason::PlayerRequest));
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertTrue($this->recoverRefund($f)['recovered']);
        app(WalletService::class)->credit($f['wallet']->fresh(), Money::of('20', Currency::THB), FinancialTransactionType::Adjustment, 'refund-wager-new-legitimate-funds');
        $out = app(BetPurchaseService::class)->purchase($this->nextRefundBet($f));
        $this->assertFalse($out->isReplay());
        $this->assertSame('10.00', $f['wallet']->fresh()->balance);
        Http::assertSentCount(8);
    }

    public function test_another_player_and_another_currency_are_not_frozen_by_this_source(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        $other = User::factory()->create();
        $before = $this->refundWagerPaper();
        DB::transaction(function () use ($f, $other): void {
            app(StripeRefundWagerGuard::class)->assertAllowed($other, Currency::THB);
            app(StripeRefundWagerGuard::class)->assertAllowed($f['user'], Currency::USD);
        });
        $this->assertSame($before, $this->refundWagerPaper());
        Http::assertSentCount(4);
    }

    public function test_no_verified_provider_receipt_means_no_manufactured_exposure_from_signed_inbox_alone(): void
    {
        $f = $this->wagerRefundFixture();
        $out = app(BetPurchaseService::class)->purchase($this->nextRefundBet($f));
        $this->assertFalse($out->isReplay());
        $this->assertSame('80.00', $f['wallet']->fresh()->balance);
        Http::assertNothingSent();
    }

    public static function corruptSources(): array
    {
        return [['stripe_refund_recovery_intents', 'response_hash'], ['stripe_refund_recovery_receipts', 'response_hash'], ['stripe_deposit_followup_inbox', 'response_hash']];
    }

    #[DataProvider('corruptSources')]
    public function test_corrupt_pending_custody_never_lifts_the_fence(string $table, string $field): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        DB::table($table)->update([$field => str_repeat('0', 64)]);
        $this->refused(fn () => app(BetPurchaseService::class)->purchase($this->nextRefundBet($f)), 'bet_purchase_refund_exposure_unproven');
        Http::assertSentCount(4);
    }

    public function test_verified_completed_recovery_is_not_recharged_on_wager_admission(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        app(WalletService::class)->credit($f['wallet']->fresh(), Money::of('30', Currency::THB), FinancialTransactionType::Adjustment, 'refund-wager-completion-funds');
        $this->recoverRefund($f);
        $before = $this->refundWagerPaper();
        DB::transaction(fn () => app(StripeRefundWagerGuard::class)->assertAllowed($f['user'], Currency::THB));
        $this->assertSame($before, $this->refundWagerPaper());
        $this->assertSame('20.00', $f['wallet']->fresh()->balance);
        Http::assertSentCount(8);
    }

    public static function corruptClosure(): array
    {
        return [['financial'], ['journal'], ['recovery'], ['chart_currency'], ['chart_deleted']];
    }

    #[DataProvider('corruptClosure')]
    public function test_completion_label_or_tampered_accounting_is_not_clearance(string $kind): void
    {
        $f = $this->wagerRefundFixture();
        app(WalletService::class)->credit($f['wallet']->fresh(), Money::of('30', Currency::THB), FinancialTransactionType::Adjustment, 'refund-wager-completion-funds');
        $done = $this->recoverRefund($f);
        $id = $done['financial_transaction_id'];
        match ($kind) {
            'financial' => DB::table('financial_transactions')->where('id', $id)->update(['description' => 'SYNTHETIC forged closure']),
            'journal' => DB::table('ledger_entries')->where('financial_transaction_id', $id)->update(['description' => 'SYNTHETIC forged journal']),
            'recovery' => DB::table(StripeRefundRecoveryService::RECOVERIES)->update(['response_hash' => str_repeat('0', 64)]),
            'chart_currency' => DB::table('ledger_accounts')->where('code', '1000')->update(['currency' => 'USD']),
            'chart_deleted' => DB::table('ledger_accounts')->where('code', '1000')->update(['deleted_at' => now()]),
        };
        $this->refused(fn () => app(BetPurchaseService::class)->purchase($this->nextRefundBet($f)), 'bet_purchase_refund_exposure_unproven');
        Http::assertSentCount(4);
    }

    public function test_missing_receipt_never_looks_like_no_exposure(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        DB::table(StripeRefundRecoveryService::RECEIPTS)->delete();
        $this->refused(fn () => app(BetPurchaseService::class)->purchase($this->nextRefundBet($f)), 'bet_purchase_refund_exposure_unproven');
    }

    public function test_guard_requires_actual_sql_root_and_never_creates_a_provider_request(): void
    {
        $f = $this->wagerRefundFixture();
        $this->refused(fn () => app(StripeRefundWagerGuard::class)->assertAllowed($f['user'], Currency::THB), 'bet_purchase_refund_exposure_unproven');
        Http::assertNothingSent();
    }

    public function test_bulk_items_are_refused_without_financial_partial_success(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        $before = $this->refundWagerPaper();
        $selection = new BulkBetSelectionData(marketKey: '3d_direct', number: '007', stake: '10.00');
        $out = app(BulkBetService::class)->purchase($f['user']->id, $f['draw']->id, [$selection, $selection], 'refund-wager-bulk');
        $this->assertSame(0, $out['purchased']);
        $this->assertSame(2, $out['refused']);
        $this->assertSame('0.00', $out['total_charged']);
        $this->assertSame($before, $this->refundWagerPaper());
        Http::assertSentCount(4);
    }

    public function test_pending_amendment_cannot_purchase_its_new_replacement(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        // Existing amendment protocol may commit its source cancellation/refund first.
        // That legitimate refund remains allowed; no replacement bet may debit it.
        $out = app(BetAmendmentService::class)->amend(new BetAmendmentData(
            $f['user']->id, (string) $f['purchase']->bet->id, '007', '12.00', 'refund-wager-amendment'));
        $this->assertTrue($out->isFailed());
        $this->assertSame('bet_purchase_refund_exposure_pending', $out->failureReason);
        $this->assertSame(1, DB::table('bets')->count());
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECOVERIES, 0);
        $this->refused(fn () => app(BetPurchaseService::class)->purchase($this->nextRefundBet($f)));
    }

    public function test_bounded_complete_source_scan_refuses_saturation_before_reading_a_partial_history(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        $intent = (array) DB::table(StripeRefundRecoveryService::INTENTS)->sole();
        $deposit = (array) DB::table('deposits')->where('id', $intent['deposit_id'])->first();
        $payment = (array) DB::table('payments')->where('id', $intent['payment_id'])->first();
        // Deliberately invalid extra custody: the cap must refuse BEFORE selecting a partial proof subset.
        for ($i = 0; $i < StripeRefundRecoveryService::WAGER_SOURCE_LIMIT; $i++) {
            $d = $deposit;
            unset($d['id']);
            $d['uuid'] = (string) Str::uuid();
            $d['reference_number'] = 'SYNTHETIC-CAP-'.$i;
            $d['idempotency_key'] = 'synthetic-cap-deposit-'.$i;
            $d['financial_transaction_id'] = null;
            $did = DB::table('deposits')->insertGetId($d);
            $p = $payment;
            unset($p['id']);
            if (array_key_exists('uuid', $p)) {
                $p['uuid'] = (string) Str::uuid();
            }
            $p['reference_number'] = 'SYNTHETIC-PAY-'.$i;
            $p['payable_id'] = $did;
            $p['gateway_reference'] = 'cs_test_SYNTHETICCAP'.$i;
            $pid = DB::table('payments')->insertGetId($p);
            $row = $intent;
            unset($row['id']);
            $row['uuid'] = (string) Str::uuid();
            $row['deposit_id'] = $did;
            $row['payment_id'] = $pid;
            $row['identity_key'] = hash('sha256', 'synthetic-cap-'.$i);
            DB::table(StripeRefundRecoveryService::INTENTS)->insert($row);
        }
        try {
            DB::transaction(fn () => app(StripeRefundWagerGuard::class)->assertAllowed($f['user'], Currency::THB));
            $this->fail('A partial source scan cannot authorize wagering.');
        } catch (BetPurchaseException $e) {
            $this->assertSame('stripe_refund_wager_history_saturated', $e->context()['source_code'] ?? null);
        }
        Http::assertSentCount(4);
    }

    public function test_real_member_api_returns_safe_422_not_a_server_failure(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        $before = $this->moneyPaper();
        $this->actingAs($f['user'], 'sanctum')->postJson('/api/v1/bets/purchase', [
            'draw_id' => $f['draw']->id, 'client_key' => 'refund-wager-http',
            'items' => [['market' => '3d_direct', 'number' => '007', 'stake' => '10.00']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'refund_recovery_required')
            ->assertJsonPath('error.details.currency', 'THB')->assertDontSee('response_cipher')->assertDontSee('SYNTHETICREVIEW');
        $this->assertSame($before, $this->moneyPaper());
        $this->assertDatabaseCount('bets', 1);
        Http::assertSentCount(4);
    }

    public function test_completed_recovery_chart_proof_follows_risk_before_the_new_money_write(): void
    {
        $f = $this->wagerRefundFixture();
        app(WalletService::class)->credit($f['wallet']->fresh(), Money::of('30', Currency::THB), FinancialTransactionType::Adjustment, 'refund-wager-lock-order-funds');
        $this->recoverRefund($f);
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });
        $out = app(BetPurchaseService::class)->purchase($this->nextRefundBet($f));
        $risk = null;
        $chart = null;
        foreach ($queries as $i => $sql) {
            if ($risk === null && str_contains($sql, 'number_limits') && str_starts_with($sql, 'update')) {
                $risk = $i;
            }
            if ($chart === null && str_contains($sql, 'ledger_accounts') && str_starts_with($sql, 'select')) {
                $chart = $i;
            }
        }
        $this->assertNotNull($risk);
        $this->assertNotNull($chart);
        $this->assertGreaterThan($risk, $chart);
        $this->assertSame(BetPurchaseStatus::Purchased, $out->status);
        $this->assertSame('10.00', $f['wallet']->fresh()->balance);
        Http::assertSentCount(4);
    }
}
