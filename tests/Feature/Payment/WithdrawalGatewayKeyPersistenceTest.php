<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ThaiBankPayoutState;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Jobs\PollThaiBankPayoutStatusJob;
use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\ThaiBankPayoutAttempt;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Compliance\FinancialComplianceGateService;
use App\Services\Finance\FinancialStateTransitionService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WithdrawalApprovalService;
use App\Services\Finance\WithdrawalCompletionService;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\WithdrawalDisbursementService;
use App\Services\Payment\WithdrawalReconciliationService;
use App\Services\Payout\ThaiBank\ScbPayoutAdapter;
use App\Support\Ops\ProductionPreflight;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

final class WithdrawalGatewayKeyPersistenceTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Cache::flush();
        Queue::fake();
        Http::preventStrayRequests();
        config([
            'finance.withdrawal.min' => '1.00',
            'finance.withdrawal.max' => '10000.00',
            'finance.withdrawal.fee_percentage' => '0.00',
            'payment.gateways.bank_transfer.enabled' => true,
            'payment.withdrawal_routing.prefer_automated_payout' => true,
            'payment.withdrawal_routing.min_amount' => null,
            'payment.withdrawal_routing.max_amount' => null,
            'thai_payout.enabled' => true,
            'thai_payout.default_bank' => 'scb',
            'thai_payout.min_amount' => '1.00',
            'thai_payout.max_amount' => '10000.00',
            'thai_payout.daily_limit' => '100000.00',
            'thai_payout.poll_after_seconds' => 60,
            'thai_payout.poll_max_age_hours' => 24,
            'thai_payout.banks.scb' => [
                'enabled' => true,
                'contract_confirmed' => true,
                'base_url' => 'https://api.scb.test',
                'token_path' => '/oauth/token',
                'transfer_path' => '/transfers',
                'status_path' => '/status/{reference}',
                'status_method' => 'GET',
                'client_id' => 'synthetic-routing-client',
                'client_secret' => 'synthetic-routing-secret',
                'resource_owner_id' => 'synthetic-routing-owner',
                'webhook_secret' => 'synthetic-routing-callback',
                'source_account' => 'synthetic-routing-source',
                'timeout_seconds' => 5,
            ],
        ]);
        Cache::put(ScbPayoutAdapter::tokenCacheKey('synthetic-routing-client'), 'synthetic-routing-token', now()->addHour());
    }

    #[DataProvider('dispatchOutcomes')]
    public function test_route_is_committed_before_one_external_call_and_survives_retries(bool $uncertain): void
    {
        [$row, $wallet] = $this->fixture();
        Http::fake(['https://api.scb.test/transfers' => function () use ($row, $uncertain) {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame('thai_bank', $row->fresh()->metadata['gateway_key']);
            $this->assertSame(WithdrawalStatus::Processing, $row->fresh()->status);
            $this->assertSame('bank_transfer', Payment::query()->sole()->gateway);
            $this->assertSame(1, ThaiBankPayoutAttempt::query()->count());

            return $uncertain ? Http::response('', 504) : Http::response(['data' => [
                'status' => 'ACCEPTED', 'transactionId' => 'SCB-ROUTE-001',
            ]], 200);
        }]);
        $service = app(WithdrawalDisbursementService::class);
        $response = $service->disburse($row);
        $this->assertTrue($response->isPending);
        $this->assertSame($uncertain, $response->outcomeUnknown);
        $this->assertSame(! $uncertain, $response->successful);
        config(['thai_payout.enabled' => false, 'payment.withdrawal_routing.prefer_automated_payout' => false]);
        $this->assertTrue($service->disburse($row->fresh())->isPending);
        $this->assertSame('thai_bank', $service->resolveGatewayKey($row->fresh()));
        $this->assertSame('thai_bank', $row->fresh()->metadata['gateway_key']);
        $this->assertSame('bank_transfer', $row->fresh()->provider);
        $this->assertSame('1000.00', $wallet->fresh()->balance);
        $this->assertSame('400.00', $wallet->fresh()->locked_balance);
        $this->assertSame(PaymentStatus::Pending, Payment::query()->sole()->status);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('thai_bank_payout_attempts', 1);
        $this->assertGreaterThan(0, AuditLog::query()->count());
        Http::assertSentCount(1);
    }

    public static function dispatchOutcomes(): array
    {
        return ['accepted' => [false], 'uncertain' => [true]];
    }

    public function test_reconciliation_uses_the_persisted_rail_despite_a_new_manual_routing_preference(): void
    {
        [$row, $wallet] = $this->fixture();
        $this->fakeAcceptedAndSettled();
        app(WithdrawalDisbursementService::class)->disburse($row);
        config(['payment.withdrawal_routing.prefer_automated_payout' => false]);
        $this->travel(61)->seconds();
        $service = app(WithdrawalReconciliationService::class);
        $this->assertSame('thai_bank', $service->resolveGatewayKey($row->fresh()));
        $response = $service->reconcile($row);
        $this->assertTrue($response->successful);
        $this->assertFalse($response->isPending);
        $this->assertSame(WithdrawalStatus::Completed, $row->fresh()->status);
        $this->assertSame('thai_bank', $row->fresh()->metadata['gateway_key']);
        $this->assertSame('bank_transfer', $row->fresh()->provider);
        $this->assertSame('600.00', $wallet->fresh()->balance);
        $this->assertSame('0.00', $wallet->fresh()->locked_balance);
        $service->reconcile($row->fresh());
        $this->assertDatabaseCount('financial_transactions', 1);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.scb.test/status/SCB-ROUTE-001');
    }

    public function test_the_poller_uses_the_durable_attempt_and_preserves_the_gateway_key(): void
    {
        [$row, $wallet] = $this->fixture();
        $this->fakeAcceptedAndSettled();
        app(WithdrawalDisbursementService::class)->disburse($row);
        $attempt = ThaiBankPayoutAttempt::query()->sole();
        config(['payment.withdrawal_routing.prefer_automated_payout' => false]);
        $this->travel(61)->seconds();
        $job = new PollThaiBankPayoutStatusJob((int) $attempt->getKey());
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $this->assertSame(ThaiBankPayoutState::Settled, $attempt->fresh()->state);
        $this->assertSame(WithdrawalStatus::Completed, $row->fresh()->status);
        $this->assertSame('thai_bank', $row->fresh()->metadata['gateway_key']);
        $this->assertSame('600.00', $wallet->fresh()->balance);
        $this->assertSame(PaymentStatus::Captured, Payment::query()->sole()->status);
        $this->assertSame('bank_transfer', Payment::query()->sole()->gateway);
        $this->assertDatabaseCount('financial_transactions', 1);
        Http::assertSentCount(2);
    }

    public function test_a_legacy_row_without_a_key_keeps_its_method_even_when_automation_is_enabled(): void
    {
        config(['thai_payout.enabled' => false]);
        [$row, $wallet] = $this->fixture();
        Http::fake();
        $response = app(WithdrawalDisbursementService::class)->disburse($row);
        $this->assertTrue($response->metadata['manual_disbursement_required']);
        $metadata = $row->fresh()->metadata;
        unset($metadata['gateway_key']);
        $row->forceFill(['metadata' => $metadata])->save();
        config(['thai_payout.enabled' => true]);
        $service = app(WithdrawalReconciliationService::class);
        $this->assertSame('bank_transfer', $service->resolveGatewayKey($row->fresh()));
        $response = $service->reconcile($row);
        $this->assertSame('bank_transfer', $response->metadata['gateway_key']);
        $this->assertTrue($response->isPending);
        $this->assertTrue($response->metadata['reconciliation_required']);
        $this->assertArrayNotHasKey('gateway_key', $row->fresh()->metadata);
        $this->assertSame('400.00', $wallet->fresh()->locked_balance);
        $this->assertDatabaseCount('thai_bank_payout_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_a_missing_bank_attempt_is_audited_and_never_resubmitted(): void
    {
        [$row, $wallet] = $this->fixture();
        $metadata = $row->metadata;
        $metadata['gateway_key'] = 'thai_bank';
        $row->forceFill(['metadata' => $metadata])->save();
        Http::fake();
        $response = app(WithdrawalReconciliationService::class)->reconcile($row);
        $this->assertTrue($response->outcomeUnknown);
        $this->assertSame('thai_payout_attempt_missing', $response->metadata['reason']);
        $this->assertSame('400.00', $wallet->fresh()->locked_balance);
        $this->assertSame('thai_payout_attempt_missing', AuditLog::query()->latest('id')->firstOrFail()->metadata['reason_code']);
        Http::assertNothingSent();
    }

    public function test_operator_availability_includes_a_withdrawal_only_internal_rail(): void
    {
        $rail = app(PaymentGatewayManager::class)->availability()['thai_bank'];
        $this->assertTrue($rail['enabled']);
        $this->assertFalse($rail['supports_deposit']);
        $this->assertTrue($rail['supports_withdrawal']);
        $this->assertNotContains('thai_bank', array_column(PaymentMethod::cases(), 'value'));
    }

    public function test_a_corrupt_persisted_key_is_audited_without_falling_back_or_sending(): void
    {
        [$row, $wallet] = $this->fixture();
        $metadata = $row->metadata;
        $metadata['gateway_key'] = 'stripe';
        $row->forceFill(['metadata' => $metadata])->save();
        Http::fake();
        try {
            app(WithdrawalDisbursementService::class)->disburse($row);
            $this->fail('A contradictory persisted routing key was accepted.');
        } catch (FinancialException $exception) {
            $this->assertSame('withdrawal_gateway_key_invalid', $exception->errorCode());
        }
        $this->assertSame(WithdrawalStatus::Approved, $row->fresh()->status);
        $this->assertSame('400.00', $wallet->fresh()->locked_balance);
        $this->assertDatabaseCount('thai_bank_payout_attempts', 0);
        $this->assertSame('withdrawal_gateway_key_invalid', AuditLog::query()->latest('id')->firstOrFail()->metadata['reason_code']);
        Http::assertNothingSent();
    }

    public function test_an_unresolvable_compliance_gate_blocks_preflight_without_exposing_the_exception(): void
    {
        $this->app->bind(FinancialComplianceGateService::class,
            static fn () => throw new \RuntimeException('synthetic-private-diagnostic'));
        Http::fake();
        $report = (new ProductionPreflight(false, false))->run();
        $checks = collect($report['checks'])->keyBy('id');
        $this->assertSame('FAIL', $checks['payout.compliance_gate']['status']);
        $this->assertSame('blocking', $checks['payout.compliance_gate']['severity']);
        $this->assertStringNotContainsString('synthetic-private-diagnostic', json_encode($report, JSON_THROW_ON_ERROR));
        Http::assertNothingSent();
    }

    public function test_enabled_realtime_checks_are_included_and_offline_preflight_does_not_probe_the_network(): void
    {
        config(['realtime.enabled' => true, 'broadcasting.default' => 'log']);
        Http::fake();
        $report = (new ProductionPreflight(false, false))->run();
        $checks = collect($report['checks'])->keyBy('id');
        $this->assertSame('FAIL', $checks['realtime.broadcast.connection']['status']);
        $this->assertSame('blocking', $checks['realtime.broadcast.connection']['severity']);
        $this->assertSame('SKIP', $checks['realtime.reverb.reachable']['status']);
        Http::assertNothingSent();
    }

    public function test_unconfigured_bank_availability_never_claims_the_internal_lane_is_available(): void
    {
        config(['thai_payout.banks.scb.enabled' => false]);
        $rail = app(PaymentGatewayManager::class)->availability()['thai_bank'];
        $this->assertFalse($rail['enabled']);
        $this->assertFalse($rail['supports_deposit']);
        $this->assertTrue($rail['supports_withdrawal']);
    }

    private function fakeAcceptedAndSettled(): void
    {
        Http::fake([
            'https://api.scb.test/transfers' => Http::response(['data' => [
                'status' => 'ACCEPTED', 'transactionId' => 'SCB-ROUTE-001',
            ]], 200),
            'https://api.scb.test/status/*' => Http::response(['data' => [
                'status' => 'SETTLED', 'transactionId' => 'SCB-ROUTE-001', 'amount' => '400.00', 'currency' => 'THB',
            ]], 200),
        ]);
    }

    private function fixture(): array
    {
        $player = $this->createPlayer('1000.00', Currency::THB);
        $row = app(WithdrawalService::class)->request($player['wallet'], Money::of('400.00', Currency::THB), PaymentMethod::BankTransfer,
            options: ['fee' => '0.00', 'payout_details' => [
                'bank_code' => '014', 'bank_name' => 'Siam Commercial Bank',
                'account_number' => '1234567890', 'account_name' => 'Synthetic Player',
            ]]);
        app(WithdrawalApprovalService::class)->approve($row);

        return [$row->fresh(), $player['wallet']->fresh()];
    }

    private int $calls = 0;

    /** @return iterable<string, array{string}> */
    public static function contradictoryPayments(): iterable
    {
        foreach (['owner', 'currency', 'method', 'gateway', 'amount', 'fee', 'status', 'reference', 'deleted'] as $fact) {
            yield $fact => [$fact];
        }
    }

    #[DataProvider('contradictoryPayments')]
    public function test_contradictory_payment_cannot_cross_the_dispatch_boundary(string $fact): void
    {
        [$withdrawal, $wallet] = $this->claimFixture();
        $payment = $this->payment($withdrawal);
        match ($fact) {
            'owner' => $payment->update(['user_id' => User::factory()->create()->getKey()]),
            'currency' => $payment->update(['currency' => Currency::USD]),
            'method' => $payment->update(['method' => PaymentMethod::Stripe]),
            'gateway' => $payment->update(['gateway' => 'stripe']),
            'amount' => $payment->update(['amount' => '399.00']),
            'fee' => $payment->update(['fee' => bcadd((string) $withdrawal->fee, '1.00', 2)]),
            'status' => $payment->update(['status' => PaymentStatus::Captured]),
            'reference' => $payment->update(['gateway_reference' => 'UNBOUND-REFERENCE']),
            'deleted' => $payment->delete(),
        };
        $sender = $this->sender(static fn () => GatewayWithdrawalResponse::completed('SHOULD-NOT-SEND'));

        try {
            $sender->disburse($withdrawal);
            self::fail('Contradictory original payment evidence authorized a transfer.');
        } catch (FinancialException $exception) {
            self::assertSame('withdrawal_payment_conflict', $exception->errorCode());
        }

        self::assertSame(0, $this->calls);
        self::assertSame(WithdrawalStatus::Approved, $withdrawal->fresh()->status);
        self::assertArrayNotHasKey(WithdrawalDisbursementService::CLAIM, $withdrawal->fresh()->metadata);
        self::assertSame('400.00', $wallet->fresh()->locked_balance);
        self::assertSame('1000.00', $wallet->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
    }

    /** @return iterable<string, array{bool}> */
    public static function paymentHistory(): iterable
    {
        yield 'active original' => [false];
        yield 'deleted original' => [true];
    }

    #[DataProvider('paymentHistory')]
    public function test_database_refuses_duplicate_payment_identity_including_deleted_history(bool $deleted): void
    {
        [$withdrawal, $wallet] = $this->claimFixture();
        $payment = $this->payment($withdrawal);
        if ($deleted) {
            $payment->delete();
        }

        try {
            $this->payment($withdrawal);
            self::fail('A second payment acquired the same withdrawal identity.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame(1, Payment::withTrashed()->count());
        }
        self::assertSame($payment->getKey(), Payment::withTrashed()->sole()->getKey());
        self::assertSame('400.00', $wallet->fresh()->locked_balance);
        self::assertSame('1000.00', $wallet->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
    }

    public function test_coherent_existing_payment_is_reused_and_completed_claim_is_atomic_with_the_debit(): void
    {
        [$withdrawal, $wallet] = $this->claimFixture();
        $payment = $this->payment($withdrawal);
        $sender = $this->sender(static fn () => GatewayWithdrawalResponse::completed('COHERENT-SETTLEMENT'));

        self::assertTrue($sender->disburse($withdrawal)->successful);
        self::assertSame(1, $this->calls);
        self::assertSame($payment->getKey(), Payment::query()->sole()->getKey());
        self::assertSame(PaymentStatus::Captured, $payment->fresh()->status);
        self::assertSame('completed', $withdrawal->fresh()->metadata[WithdrawalDisbursementService::CLAIM]['state']);
        self::assertSame('COHERENT-SETTLEMENT', $withdrawal->fresh()->metadata[WithdrawalDisbursementService::CLAIM]['provider_reference']);
        self::assertSame('600.00', $wallet->fresh()->balance);
        self::assertSame('0.00', $wallet->fresh()->locked_balance);
        self::assertSame(1, FinancialTransaction::query()->count());
        self::assertSame(2, LedgerEntry::query()->count());
    }

    /** @return iterable<string, array{bool}> */
    public static function ambiguousResponses(): iterable
    {
        yield 'unknown despite successful flag' => [true];
        yield 'unknown despite failed flag' => [false];
    }

    #[DataProvider('ambiguousResponses')]
    public function test_unknown_flag_alone_never_authorizes_settlement_or_hold_release(bool $successful): void
    {
        [$withdrawal, $wallet] = $this->claimFixture();
        $sender = $this->sender(static fn () => new GatewayWithdrawalResponse(
            successful: $successful,
            isPending: false,
            providerReference: 'AMBIGUOUS-REFERENCE',
            outcomeUnknown: true,
        ));

        $response = $sender->disburse($withdrawal);
        self::assertFalse($response->successful);
        self::assertTrue($response->isPending);
        self::assertTrue($response->outcomeUnknown);
        self::assertSame(WithdrawalStatus::Processing, $withdrawal->fresh()->status);
        self::assertSame('unknown', $withdrawal->fresh()->metadata[WithdrawalDisbursementService::CLAIM]['state']);
        self::assertSame(PaymentStatus::Pending, Payment::query()->sole()->status);
        self::assertSame('400.00', $wallet->fresh()->locked_balance);
        self::assertSame('1000.00', $wallet->fresh()->balance);
        self::assertTrue($sender->disburse($withdrawal->fresh(), ['force' => true, 'retry' => true])->outcomeUnknown);
        self::assertSame(1, $this->calls);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
    }

    public function test_payment_deleted_during_transport_cannot_be_captured_or_resent(): void
    {
        [$withdrawal, $wallet] = $this->claimFixture();
        $sender = $this->sender(function (): GatewayWithdrawalResponse {
            self::assertSame(0, DB::transactionLevel());
            Payment::query()->sole()->delete();

            return GatewayWithdrawalResponse::completed('LATE-SETTLEMENT');
        });

        try {
            $sender->disburse($withdrawal);
            self::fail('A deleted payment was captured after dispatch.');
        } catch (FinancialException $exception) {
            self::assertSame('withdrawal_payment_conflict', $exception->errorCode());
        }
        self::assertSame('sending', $withdrawal->fresh()->metadata[WithdrawalDisbursementService::CLAIM]['state']);
        self::assertSame(WithdrawalStatus::Processing, $withdrawal->fresh()->status);
        self::assertSame('400.00', $wallet->fresh()->locked_balance);
        self::assertSame('1000.00', $wallet->fresh()->balance);
        self::assertSame(0, FinancialTransaction::query()->count());
        self::assertSame(0, LedgerEntry::query()->count());
        try {
            $sender->disburse($withdrawal->fresh());
            self::fail('Contradictory payment history was accepted on retry.');
        } catch (FinancialException $exception) {
            self::assertSame('withdrawal_payment_conflict', $exception->errorCode());
        }
        self::assertSame(1, $this->calls);
    }

    public function test_committed_claim_owns_the_key_and_refuses_a_changed_valid_rail_on_retry_and_reconciliation(): void
    {
        [$withdrawal] = $this->claimFixture();
        $sender = $this->sender(function ($sent, array $options) use ($withdrawal): GatewayWithdrawalResponse {
            self::assertSame(0, DB::transactionLevel());
            self::assertFalse(DB::connection()->getPdo()->inTransaction());
            $claim = $withdrawal->fresh()->metadata[WithdrawalDisbursementService::CLAIM];
            self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $claim['key']);
            self::assertSame($claim['key'], $options['idempotency_key']);
            self::assertSame('bank_transfer', $claim['gateway_key']);

            return GatewayWithdrawalResponse::pending('CLAIM-REFERENCE');
        });
        self::assertTrue($sender->disburse($withdrawal, ['idempotency_key' => 'CALLER-OVERRIDE'])->isPending);
        $metadata = $withdrawal->fresh()->metadata;
        $metadata['gateway_key'] = 'thai_bank';
        $withdrawal->forceFill(['metadata' => $metadata])->save();
        $this->app->instance(WithdrawalDisbursementService::class, $sender);

        foreach ([
            fn () => $sender->disburse($withdrawal->fresh()),
            fn () => app(WithdrawalReconciliationService::class)->resolveGatewayKey($withdrawal->fresh()),
        ] as $retry) {
            try {
                $retry();
                self::fail('The committed rail was replaced by a different valid gateway key.');
            } catch (FinancialException $exception) {
                self::assertSame('withdrawal_gateway_key_invalid', $exception->errorCode());
            }
        }
        self::assertSame(1, $this->calls);
    }

    /** @return array{Withdrawal, Wallet} */
    private function claimFixture(): array
    {
        config(['thai_payout.enabled' => false]);
        $player = $this->createPlayer('1000.00', Currency::THB);
        $withdrawal = app(WithdrawalService::class)->request(
            $player['wallet'], Money::of('400.00', Currency::THB), PaymentMethod::BankTransfer,
            options: ['payout_details' => ['bank_name' => 'TEST', 'account_number' => 'NOT-PAYABLE']],
        );
        app(WithdrawalApprovalService::class)->approve($withdrawal);

        return [$withdrawal->fresh(), $player['wallet']->fresh()];
    }

    private function payment(Withdrawal $withdrawal): Payment
    {
        return Payment::query()->create([
            'reference_number' => 'PAY-CLAIM-'.bin2hex(random_bytes(8)),
            'user_id' => $withdrawal->user_id,
            'payable_type' => $withdrawal::class,
            'payable_id' => $withdrawal->getKey(),
            'method' => $withdrawal->method,
            'gateway' => $withdrawal->method->value,
            'status' => PaymentStatus::Pending,
            'currency' => $withdrawal->currency,
            'amount' => (string) $withdrawal->amount,
            'fee' => (string) $withdrawal->fee,
        ]);
    }

    private function sender(callable $respond): WithdrawalDisbursementService
    {
        $driver = $this->createMock(PaymentGatewayInterface::class);
        $driver->method('name')->willReturn('bank_transfer');
        $driver->method('supportsCurrency')->willReturn(true);
        $driver->method('initiateWithdrawal')->willReturnCallback(function ($row, array $options) use ($respond): GatewayWithdrawalResponse {
            $this->calls++;

            return $respond($row, $options);
        });
        $manager = $this->createMock(PaymentGatewayManager::class);
        $manager->method('withdrawalDriver')->willReturn($driver);

        return new WithdrawalDisbursementService(
            $manager, app(WalletLockService::class), app(WalletHoldService::class),
            app(FinancialStateTransitionService::class), app(WithdrawalApprovalService::class), app(WithdrawalCompletionService::class),
        );
    }
}
