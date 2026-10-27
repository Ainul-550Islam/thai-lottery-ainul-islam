<?php

declare(strict_types=1);

namespace Tests\Feature\Payment\TrueMoney;

use App\DTOs\Security\FraudDecisionData;
use App\Enums\Currency;
use App\Enums\FraudDecision;
use App\Enums\TruemoneyRedemptionStatus;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\TruemoneyVoucherRedemption;
use App\Services\Audit\AuditLogService;
use App\Services\Deposit\DepositProcessingService;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositService;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Lock\DistributedLockService;
use App\Services\Payment\TrueMoneyWalletService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Feature\Payment\PaymentTestCase;

final class TrueMoneyVoucherRedeemTest extends PaymentTestCase
{
    private const VOUCHER = 'https://gift.truemoney.com/campaign/?v=1234567890abcdef1234567890abcdef';

    private const CODE = '1234567890abcdef1234567890abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'truemoney.enabled' => true,
            'truemoney.acknowledged_unofficial_api' => true,
            'truemoney.merchant_mobile' => '+66812345678',
            'truemoney.base_url' => 'https://gift.truemoney.com',
            'truemoney.allowed_host' => 'gift.truemoney.com',
            'truemoney.redeem_path' => '/campaign/vouchers/{voucher_code}/redeem',
            'truemoney.timeout_seconds' => 4,
            'truemoney.min_amount' => '0.01',
            'truemoney.max_amount' => '100000.00',
            'truemoney.per_user_daily_count' => 5,
            'truemoney.per_user_daily_amount' => '50000.00',
        ]);
    }

    public function test_disabled_or_unacknowledged_lane_refuses_without_network_call(): void
    {
        $player = $this->createPlayer('0.00');
        Http::fake();

        config(['truemoney.enabled' => false]);
        try {
            $this->service()->redeemVoucher($player['user'], self::VOUCHER, '203.0.113.12');
            self::fail('A disabled lane must refuse redemption.');
        } catch (FinancialException $exception) {
            self::assertSame('truemoney_not_configured', $exception->errorCode());
        }

        config(['truemoney.enabled' => true, 'truemoney.acknowledged_unofficial_api' => false]);
        try {
            $this->service()->redeemVoucher($player['user'], self::VOUCHER, '203.0.113.12');
            self::fail('An unacknowledged unofficial API must refuse redemption.');
        } catch (FinancialException $exception) {
            self::assertSame('truemoney_api_not_acknowledged', $exception->errorCode());
        }
        Http::assertNothingSent();
        self::assertDatabaseCount('truemoney_voucher_redemptions', 0);
    }

    public function test_success_credits_only_provider_derived_amount_once_and_keeps_ledger_balanced(): void
    {
        Log::spy();
        $player = $this->createPlayer('0.00', Currency::THB);
        $this->allowFraud();
        Http::fake(['*' => Http::response($this->successfulPayload('138.27'), 200)]);

        $result = $this->service()->redeemVoucher($player['user'], self::VOUCHER, '203.0.113.12');

        self::assertSame('138.27', $result['amount']);
        self::assertSame('138.27', $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 1);
        self::assertDatabaseCount('ledger_entries', 2);
        self::assertSame(TruemoneyRedemptionStatus::Credited, TruemoneyVoucherRedemption::query()->sole()->status);
        $redemption = TruemoneyVoucherRedemption::query()->sole();
        self::assertSame(hash('sha256', self::CODE), $redemption->voucher_hash);
        self::assertSame('cdef', $redemption->voucher_last4);
        self::assertSame('138.27', $redemption->amount);
        self::assertStringNotContainsString(self::CODE, json_encode($redemption->getAttributes(), JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(self::VOUCHER, json_encode($redemption->getAttributes(), JSON_THROW_ON_ERROR));
        self::assertSame(1, Http::recorded()->count());

        $debits = '0.00';
        $credits = '0.00';
        foreach (LedgerEntry::query()->get() as $entry) {
            if ($entry->type->value === 'debit') {
                $debits = bcadd($debits, (string) $entry->amount, 2);
            } elseif ($entry->type->value === 'credit') {
                $credits = bcadd($credits, (string) $entry->amount, 2);
            }
        }
        self::assertSame('138.27', $debits);
        self::assertSame('138.27', $credits);
        Log::shouldHaveReceived('info')->with(
            'payment.truemoney_voucher_redeemed',
            Mockery::on(static function (array $context): bool {
                $encoded = json_encode($context, JSON_THROW_ON_ERROR);

                return ($context['voucher_last4'] ?? null) === 'cdef'
                    && ! array_key_exists('voucher_hash', $context)
                    && ! str_contains($encoded, self::CODE)
                    && ! str_contains($encoded, self::VOUCHER);
            }),
        )->once();
    }

    public function test_same_voucher_is_refused_without_a_second_provider_call(): void
    {
        $player = $this->createPlayer('0.00');
        $this->allowFraud();
        Http::fake(['*' => Http::response($this->successfulPayload('50.00'), 200)]);
        $service = $this->service();
        $service->redeemVoucher($player['user'], self::VOUCHER, '203.0.113.12');

        try {
            $service->redeemVoucher($player['user'], self::VOUCHER, '203.0.113.12');
            self::fail('A duplicate voucher must be refused.');
        } catch (FinancialException $exception) {
            self::assertSame('truemoney_voucher_already_processed', $exception->errorCode());
        }

        self::assertSame(1, Http::recorded()->count());
        self::assertSame('50.00', $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 1);
    }

    public function test_provider_rejection_does_not_credit_the_wallet(): void
    {
        $player = $this->createPlayer('0.00');
        $this->allowFraud();
        Http::fake(['*' => Http::response(['status' => ['code' => 'INVALID_VOUCHER']], 400)]);

        try {
            $this->service()->redeemVoucher($player['user'], self::VOUCHER, '203.0.113.12');
            self::fail('A rejected voucher must not credit.');
        } catch (FinancialException $exception) {
            self::assertSame('truemoney_voucher_rejected', $exception->errorCode());
        }

        self::assertSame('0.00', $player['wallet']->fresh()->balance);
        self::assertSame(TruemoneyRedemptionStatus::Rejected, TruemoneyVoucherRedemption::query()->sole()->status);
        self::assertDatabaseCount('financial_transactions', 0);
        self::assertSame(1, Http::recorded()->count());
    }

    public function test_redirect_response_is_ambiguous_and_keeps_voucher_reserved(): void
    {
        $player = $this->createPlayer('0.00');
        Http::fake(['*' => Http::response($this->successfulPayload('25.00'), 302, ['Location' => 'https://untrusted.example/'])]);

        try {
            $this->service()->redeemVoucher($player['user'], self::VOUCHER, '203.0.113.12');
            self::fail('A redirect after redemption dispatch must be treated as an unknown outcome.');
        } catch (FinancialException $exception) {
            self::assertSame('truemoney_outcome_unknown', $exception->errorCode());
        }

        self::assertSame(TruemoneyRedemptionStatus::RedeemedUnconfirmed, TruemoneyVoucherRedemption::query()->sole()->status);
        self::assertSame('0.00', $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
        self::assertSame(1, Http::recorded()->count());
    }

    public function test_redirect_with_a_known_failure_code_is_still_ambiguous(): void
    {
        $player = $this->createPlayer('0.00');
        Http::fake(['*' => Http::response(['status' => ['code' => 'INVALID_VOUCHER']], 302, ['Location' => 'https://untrusted.example/'])]);

        try {
            $this->service()->redeemVoucher($player['user'], self::VOUCHER, '203.0.113.12');
            self::fail('A redirect cannot establish a definitive voucher rejection.');
        } catch (FinancialException $exception) {
            self::assertSame('truemoney_outcome_unknown', $exception->errorCode());
        }

        self::assertSame(TruemoneyRedemptionStatus::RedeemedUnconfirmed, TruemoneyVoucherRedemption::query()->sole()->status);
        self::assertSame('0.00', $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
        self::assertSame(1, Http::recorded()->count());
    }

    public function test_provider_timeout_keeps_redemption_unconfirmed_without_credit_or_retry(): void
    {
        $player = $this->createPlayer('0.00');
        $this->allowFraud();
        $attempts = 0;
        Http::fake(['*' => static function () use (&$attempts) {
            ++$attempts;
            throw new ConnectionException('transport details');
        }]);
        $service = $this->service();

        try {
            $service->redeemVoucher($player['user'], self::VOUCHER, '203.0.113.12');
            self::fail('A provider timeout must be surfaced as uncertain.');
        } catch (FinancialException $exception) {
            self::assertSame('truemoney_outcome_unknown', $exception->errorCode());
            self::assertStringNotContainsString('transport details', $exception->getMessage());
        }

        self::assertSame(TruemoneyRedemptionStatus::RedeemedUnconfirmed, TruemoneyVoucherRedemption::query()->sole()->status);
        self::assertSame('0.00', $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
        try {
            $service->redeemVoucher($player['user'], self::VOUCHER, '203.0.113.12');
            self::fail('The uncertain voucher must remain reserved.');
        } catch (FinancialException $exception) {
            self::assertSame('truemoney_voucher_already_processed', $exception->errorCode());
        }
        self::assertSame(1, $attempts);
    }

    public function test_credit_failure_after_provider_success_is_audited_and_left_for_reconciliation(): void
    {
        $player = $this->createPlayer('0.00');
        $this->allowFraud();
        Http::fake(['*' => Http::response($this->successfulPayload('76.25'), 200)]);
        // Fail real canonical ledger posting after provider redemption. No final
        // completion-service mock and no fabricated financial evidence.
        \App\Models\LedgerAccount::query()->update(['is_active' => false]);

        try {
            $this->service()->redeemVoucher($player['user'], self::VOUCHER, '203.0.113.12');
            self::fail('Credit failure must be held for reconciliation.');
        } catch (FinancialException $exception) {
            self::assertSame('truemoney_credit_unconfirmed', $exception->errorCode());
        }

        self::assertSame(TruemoneyRedemptionStatus::RedeemedUnconfirmed, TruemoneyVoucherRedemption::query()->sole()->status);
        self::assertSame('76.25', TruemoneyVoucherRedemption::query()->sole()->amount);
        self::assertSame('0.00', $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
        self::assertGreaterThan(0, \App\Models\AuditLog::query()->count());
        self::assertSame(1, Http::recorded()->count());
    }

    public function test_authenticated_route_rejects_spoofed_user_id(): void
    {
        $owner = $this->createPlayer('0.00');
        $other = $this->createPlayer('0.00');
        Http::fake();
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureAdult::class,
            \App\Http\Middleware\RequireCurrentLegalConsent::class,
        ]);

        $response = $this->actingAs($owner['user'], 'sanctum')->postJson(route('api.v1.deposit.truemoney.redeem'), [
            'voucher_url' => self::VOUCHER,
            'user_id' => $other['user']->getKey(),
        ]);

        $response->assertUnprocessable();
        self::assertDatabaseCount('truemoney_voucher_redemptions', 0);
        Http::assertNothingSent();
    }

    private function service(?DepositCompletionService $completion = null): TrueMoneyWalletService
    {
        return new TrueMoneyWalletService(
            Mockery::mock(DepositProcessingService::class),
            app(FraudDetectionService::class),
            app(DistributedLockService::class),
            app(DepositService::class),
            app(DepositApprovalService::class),
            $completion ?? app(DepositCompletionService::class),
            app(AuditLogService::class),
        );
    }

    private function allowFraud(): void
    {
        $decision = new FraudDecisionData(0, FraudDecision::ALLOW, []);
        $fraud = Mockery::mock(FraudDetectionService::class);
        $fraud->shouldReceive('evaluateTransactionRisk')->andReturn($decision);
        $this->instance(FraudDetectionService::class, $fraud);
    }

    /** @return array<string, mixed> */
    private function successfulPayload(string $amount): array
    {
        return [
            'status' => ['code' => 'SUCCESS'],
            'data' => ['voucher' => ['amount_baht' => $amount, 'currency' => 'THB']],
        ];
    }
}
