<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Enums\FraudDecision;
use App\DTOs\Security\FraudDecisionData;
use App\Exceptions\FinancialException;
use App\Models\TruemoneyVoucherRedemption;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Payment\TrueMoneyWalletService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * A TrueMoney voucher is money that can be spent exactly once.
 *
 * WHY THIS MATTERS MORE THAN IT LOOKS
 * The voucher code is a bearer credential: whoever submits it first is paid. The
 * provider will happily confirm the SAME voucher on a second call if the first
 * confirmation was lost, so "the provider said yes" is not proof that this
 * platform has not already paid it out. The at-most-once guarantee therefore has
 * to live here, keyed on the voucher hash, and it has to be durable enough that
 * a replayed submission is refused without ever reaching the network.
 *
 * The assertions below are about that guarantee, not about the provider:
 *   - the voucher is reserved before dispatch, so a second submit is refused,
 *   - the second refusal happens BEFORE the provider is called again,
 *   - the refusal is a distinct, stable error code an operator can act on.
 */
final class TrueMoneyVoucherDoubleRedeemTest extends PaymentTestCase
{
    /** A well-formed 32-character voucher code. */
    private const VOUCHER_CODE = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('truemoney.enabled', true);
        Config::set('truemoney.acknowledged_unofficial_api', true);
        Config::set('truemoney.merchant_mobile', '+66812345678');
        Config::set('truemoney.base_url', 'https://gift.truemoney.com');
        Config::set('truemoney.allowed_host', 'gift.truemoney.com');
        Config::set('truemoney.redeem_path', '/campaign/vouchers/{voucher_code}/redeem');
        Config::set('truemoney.timeout_seconds', 5);
        Config::set('truemoney.min_amount', '0.01');
        Config::set('truemoney.max_amount', '100000.00');
        Config::set('truemoney.per_user_daily_amount', '50000.00');
        Config::set('truemoney.per_user_daily_count', 5);
    }

    public function test_the_same_voucher_cannot_be_redeemed_twice(): void
    {
        Http::fake([
            'gift.truemoney.com/*' => Http::response([
                'status' => ['code' => 'SUCCESS', 'message' => 'ok'],
                'data' => ['voucher' => ['amount_baht' => '50.00', 'currency' => 'THB']],
            ], 200),
        ]);

        $player = $this->createPlayer();
        $service = $this->serviceWithAllowingFraud();
        $url = 'https://gift.truemoney.com/campaign/?v='.self::VOUCHER_CODE;

        // First redemption claims the voucher.
        $service->redeemVoucher($player['user'], $url, '127.0.0.1');

        self::assertSame(1, TruemoneyVoucherRedemption::query()->count());

        // Second redemption of the SAME voucher must be refused.
        try {
            $service->redeemVoucher($player['user'], $url, '127.0.0.1');
            self::fail('A voucher was redeemed twice.');
        } catch (FinancialException $exception) {
            self::assertSame('truemoney_voucher_already_processed', $exception->errorCode());
        }

        // The refusal is local: the provider was called exactly ONCE in total.
        Http::assertSentCount(1);

        // And no second reservation was created.
        self::assertSame(1, TruemoneyVoucherRedemption::query()->count());
    }

    public function test_a_second_player_cannot_claim_a_voucher_another_player_already_used(): void
    {
        Http::fake([
            'gift.truemoney.com/*' => Http::response([
                'status' => ['code' => 'SUCCESS', 'message' => 'ok'],
                'data' => ['voucher' => ['amount_baht' => '50.00', 'currency' => 'THB']],
            ], 200),
        ]);

        $first = $this->createPlayer();
        $second = $this->createPlayer();
        $service = $this->serviceWithAllowingFraud();
        $url = 'https://gift.truemoney.com/campaign/?v='.self::VOUCHER_CODE;

        $service->redeemVoucher($first['user'], $url, '127.0.0.1');

        // The voucher is global, not per-player: one code, one payout, regardless
        // of whose account presents it.
        $this->expectException(FinancialException::class);
        try {
            $service->redeemVoucher($second['user'], $url, '127.0.0.1');
        } catch (FinancialException $exception) {
            self::assertSame('truemoney_voucher_already_processed', $exception->errorCode());
            Http::assertSentCount(1);
            throw $exception;
        }
    }

    private function serviceWithAllowingFraud(): TrueMoneyWalletService
    {
        $engine = \Mockery::mock(FraudDetectionService::class);
        $engine->shouldReceive('evaluateTransactionRisk')
            ->andReturn(new FraudDecisionData(
                riskScore: 0,
                decision: FraudDecision::ALLOW,
                reasonCodes: [],
            ));

        return new TrueMoneyWalletService(
            legacyDepositProcessingService: app(\App\Services\Deposit\DepositProcessingService::class),
            fraudDetectionService: $engine,
            lockService: app(\App\Services\Lock\DistributedLockService::class),
        );
    }
}
