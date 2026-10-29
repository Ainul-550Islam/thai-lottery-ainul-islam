<?php

declare(strict_types=1);

namespace Tests\Feature\Payment\Slip;

use App\DTOs\Security\FraudDecisionData;
use App\Enums\Currency;
use App\Enums\FraudDecision;
use App\Enums\PaymentMethod;
use App\Enums\SlipVerificationStatus;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Payment\BankSlipVerificationService;
use App\Services\Payment\Slip\EasySlipProvider;
use App\Services\Uploads\UploadQuarantineService;
use App\Services\Uploads\UploadScanService;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Feature\Payment\PaymentTestCase;

/** A synthetic external response, but the real adapter, slip gate and finance flow. */
final class EasySlipProviderAdmissionTest extends PaymentTestCase
{
    public function test_unrelated_amount_in_a_provider_response_cannot_credit_a_larger_deposit(): void
    {
        config([
            'slip_verification.enabled' => true,
            'slip_verification.provider' => 'easyslip',
            'slip_verification.auto_credit' => true,
            'slip_verification.max_age_minutes' => 30,
            'slip_verification.receiving_accounts' => [['bank' => 'SCB', 'account_last_digits' => '1234', 'name' => 'Example Receiver']],
            'slip_verification.providers.easyslip.base_url' => 'https://api.easyslip.com/v2',
            'slip_verification.providers.easyslip.verify_path' => '/verify/bank',
            'slip_verification.providers.easyslip.token' => 'synthetic-test-token',
            'slip_verification.providers.easyslip.timeout' => 5,
        ]);
        $player = $this->createPlayer('0.00');
        $deposit = app(DepositService::class)->request($player['wallet'], Money::of('121.25', Currency::THB), PaymentMethod::BankTransfer);
        $body = '{"decoy":{"local":{"amount":121.25}},"success":true,"data":{"rawSlip":{'
            .'"transRef":"EASY-AMOUNT-DECOY-1","date":"'.now()->subMinute()->toIso8601String().'",'
            .'"amount":{"local":{"amount":1.23,"currency":"THB"}},'
            .'"sender":{"bank":{"short":"KBANK"}},"receiver":{"bank":{"short":"SCB"},'
            .'"account":{"bank":{"account":"xxx1234"},"name":{"th":"Example Receiver"}}}}}}';
        Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);
        $fraud = Mockery::mock(FraudDetectionService::class);
        $fraud->shouldReceive('evaluateTransactionRisk')->andReturn(new FraudDecisionData(0, FraudDecision::ALLOW, []));
        $service = new BankSlipVerificationService(
            app(UploadQuarantineService::class), app(UploadScanService::class), $fraud, new EasySlipProvider,
        );

        $result = $service->verify($player['user'], null, 'SYNTHETIC-QR', '121.25', '127.0.0.1', $deposit);
        self::assertSame(SlipVerificationStatus::Rejected, $result->status);
        self::assertSame('amount_mismatch', $result->review_reason);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('pending', $deposit->fresh()->status->value);
        self::assertDatabaseCount('financial_transactions', 0);
        self::assertDatabaseCount('ledger_entries', 0);
        Http::assertSentCount(1);
    }
}
