<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\DTOs\Payment\PaymentCallbackData;
use App\DTOs\Payment\PaymentWebhookData;
use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\PaymentWebhookStatus;
use App\Enums\WalletType;
use App\Exceptions\FinancialException;
use App\Exceptions\ResponsibleGamingLimitException;
use App\Exceptions\SelfExclusionException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\ResponsibleGamingLimit;
use App\Models\Wallet;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\Payment\PaymentCallbackService;
use App\Services\Payment\PaymentInitiationService;
use App\Services\Payment\PaymentWebhookService;
use App\Services\Payment\PaymentWebhookVerificationService;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use App\Services\ResponsibleGaming\SelfExclusionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Finance\FinancialMasterTestCase;

final class PlayerDepositAdmissionTest extends FinancialMasterTestCase
{
    private function request(array $f, string $amount = '100.00', ?string $key = null, PaymentMethod $method = PaymentMethod::BankTransfer): Deposit
    {
        return app(DepositService::class)->request($f['wallet'], Money::of($amount, $f['wallet']->currency), $method, $key);
    }

    private function complete(Deposit $row): array
    {
        return app(DepositCompletionService::class)->complete($row, options: ['provider_reference' => 'SYNTHETIC-CURRENT-'.$row->id]);
    }

    private function cap(array $f, string $amount, string $type = 'daily_deposit', ?Currency $currency = null): void
    {
        app(ResponsibleGamingLimitService::class)->choose(new ResponsibleGamingLimitData(
            $f['user']->id, $type, $amount, ($currency ?? $f['wallet']->currency)->value));
    }

    private function state(): array
    {
        $out = [];
        foreach (['users', 'wallets', 'deposits', 'payments', 'financial_transactions', 'ledger_entries',
            'financial_provider_reference_owners', 'audit_logs', 'responsible_gaming_limit_versions', 'responsible_gaming_limit_ownerships'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();
        }

        return $out;
    }

    private function refuse(callable $call, string $exception): void
    {
        $before = $this->state();
        try {
            $call();
            $this->fail('Historical or stale facts granted a NEW deposit write.');
        } catch (FinancialException|ResponsibleGamingLimitException|SelfExclusionException $e) {
            $this->assertInstanceOf($exception, $e);
        }
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $this->state(), 'Refusal must preserve every financial and source-ownership row.');
    }

    public static function accounts(): array
    {
        return [['inactive'], ['banned'], ['suspended'], ['pending_verification']];
    }

    #[DataProvider('accounts')]
    public function test_stale_wallet_cannot_create_a_fresh_intent_for_a_current_nonactive_player(string $status): void
    {
        $f = $this->createPlayer();
        DB::table('users')->where('id', $f['user']->id)->update(['status' => $status]);
        $this->refuse(fn () => $this->request($f), FinancialException::class);
    }

    #[DataProvider('accounts')]
    public function test_old_approval_cannot_credit_a_current_nonactive_player(string $status): void
    {
        $f = $this->createPlayer();
        $row = $this->request($f);
        app(DepositApprovalService::class)->approve($row);
        DB::table('users')->where('id', $f['user']->id)->update(['status' => $status]);
        $this->refuse(fn () => $this->complete($row), FinancialException::class);
    }

    public static function protections(): array
    {
        return [['legacy'], ['cool'], ['versioned']];
    }

    private function protect(array $f, string $kind): void
    {
        if ($kind === 'versioned') {
            $svc = app(SelfExclusionService::class);
            $svc->activate($svc->request(new SelfExclusionData(userId: $f['user']->id, durationDays: 7, reason: 'synthetic deposit protection')));
        } else {
            ResponsibleGamingLimit::query()->create(['user_id' => $f['user']->id,
                $kind === 'cool' ? 'cool_off_until' : 'self_excluded_until' => now()->addDay()])->delete();
        }
    }

    #[DataProvider('protections')]
    public function test_fresh_request_and_previously_approved_credit_recheck_current_protection(string $kind): void
    {
        $f = $this->createPlayer();
        $f['user']->load('responsibleGamingLimit');
        $row = $this->request($f);
        app(DepositApprovalService::class)->approve($row);
        $this->protect($f, $kind);
        $this->refuse(fn () => $this->request($f), SelfExclusionException::class);
        $this->refuse(fn () => $this->complete($row), SelfExclusionException::class);
    }

    public static function reservedStatuses(): array
    {
        return [['pending'], ['approved'], ['processing'], ['confirmed'], ['refunded']];
    }

    #[DataProvider('reservedStatuses')]
    public function test_all_current_obligations_including_hidden_rows_reserve_gross_budget_across_distinct_wallets(string $status): void
    {
        $f = $this->createPlayer();
        $other = Wallet::factory()->for($f['user'])->create(['type' => WalletType::Bonus, 'currency' => Currency::THB]);
        $row = $this->request($f, '100.00');
        $row->status = DepositStatus::from($status);
        $row->save();
        $row->delete();
        $this->cap($f, '150.00');
        $this->refuse(fn () => $this->request($f + ['unused' => null], '100.00', 'fresh-cross-wallet'), ResponsibleGamingLimitException::class);
        $this->refuse(fn () => $this->request(['user' => $f['user'], 'wallet' => $other]), ResponsibleGamingLimitException::class);
    }

    public static function unusedReservations(): array
    {
        return [['cancelled'], ['failed'], ['rejected']];
    }

    #[DataProvider('unusedReservations')]
    public function test_uncredited_terminal_requests_release_reservation_without_creating_money(string $status): void
    {
        $f = $this->createPlayer('0.00');
        $row = $this->request($f);
        $row->status = DepositStatus::from($status);
        $row->save();
        $this->cap($f, '100.00');
        $fresh = $this->request($f);
        $this->assertSame(DepositStatus::Pending, $fresh->status);
        $this->assertNotSame($row->id, $fresh->id);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
    }

    public static function currencies(): array
    {
        return [[Currency::THB], [Currency::USD], [Currency::BDT]];
    }

    #[DataProvider('currencies')]
    public function test_currency_partition_and_own_replacement_avoid_default_thb_and_double_counting(Currency $currency): void
    {
        $f = $this->createPlayer('0.00', $currency);
        $this->cap($f, '100.00');
        $foreign = Wallet::factory()->for($f['user'])->create(['currency' => $currency === Currency::THB ? Currency::USD : Currency::THB]);
        $this->request(['user' => $f['user'], 'wallet' => $foreign], '500.00');
        $row = $this->request($f);
        app(DepositApprovalService::class)->approve($row);
        $out = $this->complete($row);
        $this->assertTrue($out['credited']);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame($currency, $out['transaction']->currency);
        $this->refuse(fn () => $this->request($f), ResponsibleGamingLimitException::class);
    }

    public static function windows(): array
    {
        return [['daily_deposit', 1], ['weekly_deposit', 7], ['monthly_deposit', 30]];
    }

    #[DataProvider('windows')]
    public function test_old_intent_credited_now_counts_at_confirmation_not_original_request_date(string $type, int $days): void
    {
        $f = $this->createPlayer('0.00');
        $this->travel(-($days + 2))->days();
        $row = $this->request($f);
        app(DepositApprovalService::class)->approve($row);
        $this->travelBack();
        $this->cap($f, '100.00', $type);
        $this->assertTrue($this->complete($row)['credited']);
        $this->refuse(fn () => $this->request($f), ResponsibleGamingLimitException::class);
    }

    public function test_old_intent_is_reprojected_at_completion_and_pending_increase_never_supplies_permission(): void
    {
        $f = $this->createPlayer('0.00');
        $row = $this->request($f);
        app(DepositApprovalService::class)->approve($row);
        $this->cap($f, '50.00');
        $this->cap($f, '500.00');
        $this->refuse(fn () => $this->complete($row), ResponsibleGamingLimitException::class);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
    }

    public function test_confirmed_replay_and_intent_replay_do_not_reapply_fresh_budget_or_account_permission(): void
    {
        $f = $this->createPlayer('0.00');
        $row = $this->request($f, '100.00', 'historical-deposit-replay');
        app(DepositApprovalService::class)->approve($row);
        $this->complete($row);
        $this->cap($f, '50.00');
        DB::table('users')->where('id', $f['user']->id)->update(['status' => 'banned']);
        $before = $this->state();
        $this->assertSame($row->id, $this->request($f, '100.00', 'historical-deposit-replay')->id);
        $this->assertTrue(app(DepositCompletionService::class)->complete($row->fresh())['replayed']);
        $this->assertSame($before, $this->state());
        $this->refuse(fn () => $this->request($f, '100.00', 'a-new-deposit-request'), FinancialException::class);
    }

    public function test_caller_catching_late_credit_failure_cannot_commit_partial_credit_reference_or_ledger(): void
    {
        $f = $this->createPlayer('0.00');
        $row = $this->request($f);
        app(DepositApprovalService::class)->approve($row);
        $before = $this->state();
        $armed = true;
        DB::listen(function ($q) use (&$armed): void {
            if ($armed && str_starts_with(strtolower($q->sql), 'insert into') && str_contains($q->sql, 'ledger_entries')) {
                $armed = false;
                throw new \RuntimeException('Synthetic late accounting interruption');
            }
        });
        DB::transaction(function () use ($row): void {
            try {
                $this->complete($row);
                $this->fail('Synthetic interruption did not fire.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Synthetic late accounting interruption', $e->getMessage());
            }
        });
        $this->assertSame($before, $this->state());
    }

    private function callbackFixture(): array
    {
        $f = $this->createPlayer('0.00');
        $row = $this->request($f, method: PaymentMethod::Stripe);
        $ref = 'SYNTHETIC-PROVIDER-'.$row->id;
        $payment = Payment::query()->create(['reference_number' => 'PAY-SYNTHETIC-'.$row->id, 'user_id' => $f['user']->id,
            'payable_type' => Deposit::class, 'payable_id' => $row->id, 'method' => PaymentMethod::Stripe,
            'status' => PaymentStatus::Pending, 'currency' => Currency::THB, 'amount' => '100.00', 'fee' => '0.00',
            'gateway' => 'stripe', 'gateway_reference' => $ref, 'metadata' => []]);

        return $f + ['row' => $row, 'payment' => $payment, 'reference' => $ref];
    }

    public function test_normalized_callback_cannot_capture_or_approve_when_current_deposit_limit_refuses(): void
    {
        $f = $this->callbackFixture();
        $this->cap($f, '50.00');
        $dto = PaymentCallbackData::fromInput('stripe', $f['reference'], '100.00', 'THB', PaymentTransactionStatus::Succeeded, null, null, hash('sha256', 'SYNTHETIC-POLICY-CALLBACK'));
        $this->refuse(fn () => app(PaymentCallbackService::class)->apply($dto), ResponsibleGamingLimitException::class);
        $this->assertSame(PaymentStatus::Pending, $f['payment']->fresh()->status);
        $this->assertSame(DepositStatus::Pending, $f['row']->fresh()->status);
    }

    public static function receiptBarriers(): array
    {
        return [['account'], ['limit']];
    }

    #[DataProvider('receiptBarriers')]
    public function test_verified_receipt_is_retained_while_credit_is_held_then_explicit_persisted_retry_credits_once(string $barrier): void
    {
        $f = $this->callbackFixture();
        if ($barrier === 'account') {
            DB::table('users')->where('id', $f['user']->id)->update(['status' => 'banned']);
        } else {
            $this->cap($f, '50.00');
        }
        $payload = ['id' => 'SYNTHETIC-HELD-RECEIPT', 'reference' => $f['reference'], 'amount' => '100.00', 'currency' => 'THB', 'status' => 'succeeded'];
        $signature = app(PaymentWebhookVerificationService::class)->expectedSignatureFor('stripe', $payload);
        $envelope = PaymentWebhookData::fromInput('stripe', $payload['id'], 'deposit.succeeded', $signature, now()->toIso8601String(), $payload);
        $service = app(PaymentWebhookService::class);
        $out = $service->processEnvelope($envelope);
        $this->assertSame(PaymentWebhookStatus::Verified, $out['status']);
        $this->assertSame($barrier === 'account' ? 'deposit_player_unavailable' : 'RGL_INVALID_AMOUNT', $out['webhook']->fresh()->status_reason);
        $this->assertSame($payload, $out['webhook']->fresh()->payload);
        $this->assertSame(PaymentStatus::Pending, $f['payment']->fresh()->status);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertSame('duplicate', $service->processEnvelope($envelope)['outcome']);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        if ($barrier === 'account') {
            DB::table('users')->where('id', $f['user']->id)->update(['status' => 'active']);
        } else {
            $this->cap($f, '100.00');
            $this->travel(25)->hours();
            $this->assertSame(1, app(ResponsibleGamingLimitService::class)->activateDuePending());
        }
        $applied = $service->applyPersisted($out['webhook']);
        $this->assertSame(PaymentWebhookStatus::Applied, $applied->status);
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $service->applyPersisted($applied);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_checkout_replay_returns_original_response_without_second_external_call_even_after_ban(): void
    {
        $f = $this->createPlayer('0.00');
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'SYNTHETIC-ONE-CHECKOUT', 'url' => 'https://example.invalid/synthetic-checkout'], 200)]);
        $service = app(PaymentInitiationService::class);
        $first = $service->initiateDeposit($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::Stripe, 'one-checkout-request');
        DB::table('users')->where('id', $f['user']->id)->update(['status' => 'banned']);
        $before = $this->state();
        $second = $service->initiateDeposit($f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::Stripe, 'one-checkout-request');
        $this->assertSame($first['gateway_response']->toArray(), $second['gateway_response']->toArray());
        Http::assertSentCount(1);
        $this->assertSame($before, $this->state());
    }

    public function test_existing_request_without_checkout_ownership_never_redrives_an_unknown_provider_outcome(): void
    {
        $f = $this->createPlayer('0.00');
        $this->request($f, '100.00', 'unproven-checkout', PaymentMethod::Stripe);
        Http::fake();
        $this->refuse(fn () => app(PaymentInitiationService::class)->initiateDeposit(
            $f['wallet'], Money::of('100.00', Currency::THB), PaymentMethod::Stripe, 'unproven-checkout'), FinancialException::class);
        Http::assertNothingSent();
    }
}
