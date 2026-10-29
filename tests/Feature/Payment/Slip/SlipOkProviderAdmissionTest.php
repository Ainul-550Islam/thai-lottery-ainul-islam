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
use App\Services\Payment\Slip\SlipOkProvider;
use App\Services\Uploads\UploadQuarantineService;
use App\Services\Uploads\UploadScanService;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Payment\PaymentTestCase;

/** Real adapter and finance flow with a controlled HTTP response, never a live payment. */
final class SlipOkProviderAdmissionTest extends PaymentTestCase
{
    public static function scenarios(): array
    {
        return [
            'valid flags and auto credit' => [true, true, false, true, SlipVerificationStatus::Credited, null, true],
            'valid flags but review mode' => [true, true, false, false, SlipVerificationStatus::NeedsReview, 'manual_review_required', false],
            'provider duplicate' => [true, true, true, true, SlipVerificationStatus::Duplicate, 'provider_duplicate', false],
            'contradictory outer failure' => [false, true, false, true, SlipVerificationStatus::NeedsReview, 'provider_rejected_slip', false],
            'string inner failure' => [true, 'false', false, true, SlipVerificationStatus::NeedsReview, 'provider_error', false],
            'ambiguous duplicate status' => [true, true, '', true, SlipVerificationStatus::NeedsReview, 'provider_error', false],
            'visible digits are not a proven suffix' => [true, true, false, true, SlipVerificationStatus::Rejected, 'receiver_mismatch', false, 'xxx1234x'],
        ];
    }

    public function test_provider_duplicate_without_transfer_facts_is_recorded_as_duplicate_and_never_credits(): void
    {
        config([
            'slip_verification.enabled' => true,
            'slip_verification.provider' => 'slipok',
            'slip_verification.auto_credit' => true,
            'slip_verification.max_age_minutes' => 30,
            'slip_verification.receiving_accounts' => [['bank' => 'SCB', 'account_last_digits' => '1234', 'name' => 'Example Receiver']],
            'slip_verification.providers.slipok.base_url' => 'https://api.slipok.com',
            'slip_verification.providers.slipok.verify_path' => '/api/line/apikey/{branch_id}',
            'slip_verification.providers.slipok.branch_id' => 'test-branch',
            'slip_verification.providers.slipok.api_key' => 'synthetic-test-key',
            'slip_verification.providers.slipok.timeout' => 5,
        ]);
        $player = $this->createPlayer('0.00');
        $deposit = app(DepositService::class)->request($player['wallet'], Money::of('121.25', Currency::THB), PaymentMethod::BankTransfer);
        Http::fake(['*' => Http::response(['success' => false, 'code' => 1012, 'message' => 'duplicate'], 400)]);
        $fraud = Mockery::mock(FraudDetectionService::class);
        $fraud->shouldNotReceive('evaluateTransactionRisk');
        $service = new BankSlipVerificationService(
            app(UploadQuarantineService::class), app(UploadScanService::class), $fraud, new SlipOkProvider,
        );

        $result = $service->verify($player['user'], null, 'SYNTHETIC-QR', '121.25', '127.0.0.1', $deposit);
        self::assertSame(SlipVerificationStatus::Duplicate, $result->status);
        self::assertSame('provider_duplicate', $result->review_reason);
        self::assertNull($result->deposit_id);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('pending', $deposit->fresh()->status->value);
        self::assertDatabaseCount('financial_transactions', 0);
        self::assertDatabaseCount('ledger_entries', 0);
        Http::assertSentCount(1);
    }

    public function test_an_exponential_provider_amount_cannot_be_misread_as_a_larger_deposit(): void
    {
        config([
            'slip_verification.enabled' => true,
            'slip_verification.provider' => 'slipok',
            'slip_verification.auto_credit' => true,
            'slip_verification.max_age_minutes' => 30,
            'slip_verification.receiving_accounts' => [['bank' => 'SCB', 'account_last_digits' => '1234', 'name' => 'Example Receiver']],
            'slip_verification.providers.slipok.base_url' => 'https://api.slipok.com',
            'slip_verification.providers.slipok.verify_path' => '/api/line/apikey/{branch_id}',
            'slip_verification.providers.slipok.branch_id' => 'test-branch',
            'slip_verification.providers.slipok.api_key' => 'synthetic-test-key',
            'slip_verification.providers.slipok.timeout' => 5,
        ]);
        $player = $this->createPlayer('0.00');
        $deposit = app(DepositService::class)->request($player['wallet'], Money::of('121.25', Currency::THB), PaymentMethod::BankTransfer);
        // A float regex used to capture only 121.25 from 121.25e-2 and credit
        // 100 times the actual transfer amount.
        $body = '{"success":true,"data":{"success":true,"transRef":"AMOUNT-EXPONENT-1","amount":121.25e-2,"transTimestamp":"'
            .now()->subMinute()->toIso8601String().'","currency":"THB","sendingBank":"KBANK","receivingBank":"SCB",'
            .'"receiver":{"account":{"value":"xxx1234"},"displayName":"Example Receiver"}}}';
        Http::fake(['*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);
        $fraud = Mockery::mock(FraudDetectionService::class);
        $fraud->shouldReceive('evaluateTransactionRisk')->andReturn(new FraudDecisionData(0, FraudDecision::ALLOW, []));
        $service = new BankSlipVerificationService(
            app(UploadQuarantineService::class), app(UploadScanService::class), $fraud, new SlipOkProvider,
        );

        $result = $service->verify($player['user'], null, 'SYNTHETIC-QR', '121.25', '127.0.0.1', $deposit);
        self::assertSame(SlipVerificationStatus::NeedsReview, $result->status);
        self::assertSame('provider_error', $result->review_reason);
        self::assertSame('0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame('pending', $deposit->fresh()->status->value);
        self::assertDatabaseCount('financial_transactions', 0);
        self::assertDatabaseCount('ledger_entries', 0);
        Http::assertSentCount(1);
    }

    #[DataProvider('scenarios')]
    public function test_only_explicit_success_and_nonduplicate_can_reach_credit(
        mixed $outer,
        mixed $inner,
        mixed $duplicate,
        bool $autoCredit,
        SlipVerificationStatus $status,
        ?string $reason,
        bool $credited,
        string $receiverAccount = 'xxx1234',
    ): void {
        config([
            'slip_verification.enabled' => true,
            'slip_verification.provider' => 'slipok',
            'slip_verification.auto_credit' => $autoCredit,
            'slip_verification.max_age_minutes' => 30,
            'slip_verification.receiving_accounts' => [[
                'bank' => 'SCB', 'account_last_digits' => '1234', 'name' => 'Example Receiver',
            ]],
            'slip_verification.providers.slipok.base_url' => 'https://api.slipok.com',
            'slip_verification.providers.slipok.verify_path' => '/api/line/apikey/{branch_id}',
            'slip_verification.providers.slipok.branch_id' => 'test-branch',
            'slip_verification.providers.slipok.api_key' => 'synthetic-test-key',
            'slip_verification.providers.slipok.authorization_header' => 'x-authorization',
            'slip_verification.providers.slipok.timeout' => 5,
        ]);
        $player = $this->createPlayer('0.00');
        $deposit = app(DepositService::class)->request($player['wallet'], Money::of('121.25', Currency::THB), PaymentMethod::BankTransfer);
        Http::fake(['*' => Http::response([
            'success' => $outer,
            'data' => [
                'success' => $inner,
                'isDuplicate' => $duplicate,
                'transRef' => 'SYNTHETIC-SLIPOK-ADMISSION-01',
                'transTimestamp' => now()->subMinute()->toIso8601String(),
                'amount' => '121.25', 'currency' => 'THB',
                'sendingBank' => 'KBANK', 'receivingBank' => 'SCB',
                'receiver' => ['account' => ['value' => $receiverAccount], 'displayName' => 'Example Receiver'],
            ],
        ], 200)]);
        $fraud = Mockery::mock(FraudDetectionService::class);
        $fraud->shouldReceive('evaluateTransactionRisk')->andReturn(new FraudDecisionData(
            riskScore: 0, decision: FraudDecision::ALLOW, reasonCodes: [],
        ));
        $service = new BankSlipVerificationService(
            quarantineService: app(UploadQuarantineService::class),
            scanService: app(UploadScanService::class),
            fraudDetectionService: $fraud,
            provider: new SlipOkProvider,
        );

        $result = $service->verify($player['user'], null, 'SYNTHETIC-QR', '121.25', '127.0.0.1', $deposit);
        self::assertSame($status, $result->status);
        self::assertSame($reason, $result->review_reason);
        self::assertSame($credited ? '121.25' : '0.00', (string) $player['wallet']->fresh()->balance);
        self::assertSame($credited ? 'confirmed' : 'pending', $deposit->fresh()->status->value);
        self::assertDatabaseCount('financial_transactions', $credited ? 1 : 0);
        self::assertDatabaseCount('ledger_entries', $credited ? 2 : 0);
        Http::assertSentCount(1);
    }
}
