<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Exceptions\WithdrawalException;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Finance\StripeRefundWithdrawalGuard;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalReservationService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\StripeRefundRecoveryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Finance\FinancialMasterTestCase;
use Tests\Support\StripeRefundWagerFixture;

final class StripeRefundWithdrawalGuardTest extends FinancialMasterTestCase
{
    use StripeRefundWagerFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['finance.withdrawal.min' => '1.00']);
    }

    private function request(Wallet $wallet, string $key = 'refund-withdraw-new')
    {
        return app(WithdrawalService::class)->request($wallet, Money::of('10', $wallet->currency), PaymentMethod::Manual, 'refund-withdraw:'.$key);
    }

    private function paper(): array
    {
        $out = $this->refundWagerPaper();
        foreach (['withdrawals', 'withdrawal_reservations'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $out;
    }

    private function refused(callable $operation, string $code = 'withdrawal_refund_exposure_pending'): void
    {
        $before = $this->paper();
        try {
            $operation();
            $this->fail('Known pending or unproved recovery cannot authorize a new cash-out request/hold.');
        } catch (WithdrawalException $e) {
            $this->assertSame($code, $e->errorCode());
            $this->assertStringNotContainsString('SYNTHETIC', $e->getMessage());
        }
        $this->assertSame($before, $this->paper());
    }

    public function test_actual_new_request_and_create_alias_refuse_without_request_hold_or_money(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        $this->refused(fn () => $this->request($f['wallet']));
        $this->refused(fn () => app(WithdrawalService::class)->create($f['wallet'], Money::of('10', Currency::THB), PaymentMethod::Manual));
        Http::assertSentCount(4);
    }

    public function test_a_different_same_currency_wallet_cannot_escape_the_original_player_source(): void
    {
        $f = $this->wagerRefundFixture();
        $other = $this->otherRefundWallet($f);
        $this->publishPendingRefund($f);
        $this->refused(fn () => $this->request($other));
    }

    public function test_exact_existing_request_replay_stays_observational(): void
    {
        $f = $this->wagerRefundFixture();
        $old = $this->request($f['wallet']);
        $this->publishPendingRefund($f);
        $before = $this->paper();
        $this->assertSame($old->id, $this->request($f['wallet'])->id);
        $this->assertSame($before, $this->paper());
        $this->assertDatabaseCount('withdrawal_reservations', 0);
        Http::assertSentCount(4);
    }

    public function test_a_request_created_before_source_publication_cannot_obtain_a_new_approval_hold(): void
    {
        $f = $this->wagerRefundFixture();
        $old = $this->request($f['wallet']);
        $this->publishPendingRefund($f);
        $this->refused(fn () => app(WithdrawalApprovalService::class)->approve($old));
        $this->assertSame('pending', $old->fresh()->status->value);
        $this->assertDatabaseCount('withdrawal_reservations', 0);
    }

    public function test_lower_reservation_writer_cannot_bypass_approval_admission(): void
    {
        $f = $this->wagerRefundFixture();
        $old = $this->request($f['wallet']);
        $this->publishPendingRefund($f);
        $this->refused(fn () => DB::transaction(function () use ($f, $old): void {
            $wallet = app(WalletLockService::class)->lock($f['wallet']->id);
            $row = $old->newQuery()->whereKey($old->id)->lockForUpdate()->firstOrFail();
            app(WithdrawalReservationService::class)->reserve($row, $wallet);
        }));
    }

    public function test_already_reserved_obligation_can_settle_and_replay_without_new_admission(): void
    {
        $f = $this->wagerRefundFixture();
        $old = $this->request($f['wallet']);
        app(WithdrawalApprovalService::class)->approve($old);
        $this->publishPendingRefund($f);
        $result = app(WithdrawalCompletionService::class)->complete($old);
        $this->assertSame('80.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        $this->assertSame('completed', $old->fresh()->status->value);
        $before = $this->paper();
        app(WithdrawalCompletionService::class)->complete($old);
        $this->assertSame($before, $this->paper());
        $this->refused(fn () => $this->request($f['wallet'], 'after-old-settlement'));
        Http::assertSentCount(4);
    }

    public function test_rejection_can_release_an_old_hold_without_clearing_refund_exposure(): void
    {
        $f = $this->wagerRefundFixture();
        $old = $this->request($f['wallet']);
        app(WithdrawalApprovalService::class)->approve($old);
        $this->publishPendingRefund($f);
        app(WithdrawalApprovalService::class)->reject($old, 'Synthetic rejection');
        $this->assertSame('90.00', $f['wallet']->fresh()->balance);
        $this->assertSame('0.00', $f['wallet']->fresh()->locked_balance);
        $this->refused(fn () => $this->request($f['wallet'], 'after-release'));
    }

    public function test_actual_financial_recovery_not_unrelated_funding_clears_new_request_and_hold(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        app(WalletService::class)->credit($f['wallet']->fresh(), Money::of('30', Currency::THB), FinancialTransactionType::Adjustment, 'refund-withdraw-funds');
        $this->refused(fn () => $this->request($f['wallet']));
        $this->recoverRefund($f);
        $row = $this->request($f['wallet']);
        app(WithdrawalApprovalService::class)->approve($row);
        $this->assertSame('20.00', $f['wallet']->fresh()->balance);
        $this->assertSame('10.00', $f['wallet']->fresh()->locked_balance);
        $this->assertDatabaseCount('withdrawal_reservations', 1);
        $this->assertDatabaseCount(StripeRefundRecoveryService::RECOVERIES, 1);
        Http::assertSentCount(8);
    }

    public function test_other_players_and_other_currencies_are_not_frozen(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        $other = $this->createPlayer('20.00', Currency::THB);
        $this->assertNotNull($this->request($other['wallet']));
        $foreign = Wallet::factory()->create(['user_id' => $f['user']->id, 'currency' => Currency::BDT, 'balance' => '0.00']);
        app(WalletService::class)->credit($foreign, Money::of('20', Currency::BDT), FinancialTransactionType::Adjustment, 'refund-withdraw-bdt');
        $this->assertNotNull($this->request($foreign, 'other-currency'));
        $this->assertDatabaseCount('withdrawals', 2);
    }

    public function test_signed_inbox_without_authenticated_provider_receipt_does_not_manufacture_exposure(): void
    {
        $f = $this->wagerRefundFixture();
        $this->assertNotNull($this->request($f['wallet']));
        Http::assertSentCount(0);
    }

    public function test_expiry_disabled_features_and_revoked_staff_never_clear_pending_source(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        config(['payment.refund_recovery.stripe.enabled' => false, 'payment.deposit_review.stripe.enabled' => false]);
        $f['actor']->syncPermissions([]);
        $this->travel(1)->days();
        $this->refused(fn () => $this->request($f['wallet']));
        Http::assertSentCount(4);
    }

    public static function corruptSources(): array
    {
        return [['intent'], ['receipt'], ['missing'], ['financial'], ['chart']];
    }

    #[DataProvider('corruptSources')]
    public function test_unproved_custody_or_completed_financial_labels_never_clear_new_request(string $kind): void
    {
        $f = $this->wagerRefundFixture();
        if (in_array($kind, ['financial', 'chart'], true)) {
            app(WalletService::class)->credit($f['wallet']->fresh(), Money::of('30', Currency::THB), FinancialTransactionType::Adjustment, 'refund-closure-funds');
            $done = $this->recoverRefund($f);
            if ($kind === 'financial') {
                DB::table('financial_transactions')->where('id', $done['financial_transaction_id'])->update(['description' => 'SYNTHETIC tamper']);
            } else {
                DB::table('ledger_accounts')->where('code', '1000')->update(['currency' => 'USD']);
            }
        } else {
            $this->publishPendingRefund($f);
            match ($kind) {
                'intent' => DB::table(StripeRefundRecoveryService::INTENTS)->update(['response_hash' => str_repeat('0', 64)]),
                'receipt' => DB::table(StripeRefundRecoveryService::RECEIPTS)->update(['response_hash' => str_repeat('0', 64)]),
                'missing' => DB::table(StripeRefundRecoveryService::RECEIPTS)->delete(),
            };
        }
        $this->refused(fn () => $this->request($f['wallet']), 'withdrawal_refund_exposure_unproven');
    }

    public function test_guard_requires_framework_and_physical_sql_transaction(): void
    {
        $f = $this->wagerRefundFixture();
        $this->refused(fn () => app(StripeRefundWithdrawalGuard::class)->assertAllowed($f['wallet']), 'withdrawal_refund_exposure_unproven');
        Http::assertSentCount(0);
    }

    public function test_ambient_caller_cannot_catch_refusal_and_commit_a_new_hold_or_request(): void
    {
        $f = $this->wagerRefundFixture();
        $old = $this->request($f['wallet']);
        $this->publishPendingRefund($f);
        DB::transaction(function () use ($f, $old): void {
            $this->refused(fn () => $this->request($f['wallet'], 'ambient-new'));
            $this->refused(fn () => app(WithdrawalReservationService::class)->reserve($old, $f['wallet']->fresh()));
        });
        $this->assertDatabaseCount('withdrawals', 1);
        $this->assertDatabaseCount('withdrawal_reservations', 0);
    }

    public function test_authenticated_member_api_returns_safe_422_before_its_legacy_hold(): void
    {
        $f = $this->wagerRefundFixture();
        $this->publishPendingRefund($f);
        $before = $this->paper();
        $this->actingAs($f['user'], 'sanctum')->postJson('/api/v1/withdrawals', [
            'amount' => '10.00', 'currency' => 'THB', 'method' => 'manual', 'idempotency_key' => 'refund-withdraw-http-request',
        ])->assertStatus(422)->assertJsonPath('error.code', 'refund_recovery_required')
            ->assertDontSee('SYNTHETIC')->assertDontSee('response_cipher');
        $this->assertSame($before, $this->paper());
        Http::assertSentCount(4);
    }

    public function test_authenticated_exact_api_replay_under_new_custody_does_not_add_another_hold(): void
    {
        $f = $this->wagerRefundFixture();
        $this->actingAs($f['user'], 'sanctum');
        $payload = ['amount' => '10.00', 'currency' => 'THB', 'method' => 'manual', 'idempotency_key' => 'api-refund-exact-replay'];
        $first = $this->postJson('/api/v1/withdrawals', $payload)->assertCreated();
        $this->assertSame('10.00', $f['wallet']->fresh()->locked_balance);
        $this->publishPendingRefund($f);
        $httpBeforeReplay = Http::recorded()->count();
        $before = $this->paper();
        $replay = $this->postJson('/api/v1/withdrawals', $payload)->assertCreated();
        $this->assertSame($first->json('data.withdrawal.id'), $replay->json('data.withdrawal.id'));
        $this->assertSame($before, $this->paper());
        $this->assertDatabaseCount('withdrawals', 1);
        $this->assertSame('10.00', $f['wallet']->fresh()->locked_balance);
        Http::assertSentCount($httpBeforeReplay);
    }

    public function test_api_initial_hold_failure_rolls_back_the_new_request_and_all_its_money_rows(): void
    {
        $f = $this->wagerRefundFixture();
        $before = $this->paper();
        $armed = true;
        DB::listen(function ($event) use (&$armed): void {
            $sql = strtolower($event->sql);
            if ($armed && str_starts_with($sql, 'update ') && str_contains($sql, 'wallets') && str_contains($sql, 'locked_balance')) {
                $armed = false;
                throw WithdrawalException::withCode('fixture_hold_refusal', 'Synthetic hold refusal.');
            }
        });
        $this->actingAs($f['user'], 'sanctum')->postJson('/api/v1/withdrawals', [
            'amount' => '10.00', 'currency' => 'THB', 'method' => 'manual', 'idempotency_key' => 'api-refund-atomic-hold',
        ])->assertStatus(422)->assertJsonPath('error.code', 'withdrawal_refused');
        $this->assertFalse($armed, 'The failure must occur after an actual attempted wallet hold update.');
        $this->assertDatabaseCount('withdrawals', 0);
        $this->assertSame($before, $this->paper());
        Http::assertNothingSent();
    }
}
