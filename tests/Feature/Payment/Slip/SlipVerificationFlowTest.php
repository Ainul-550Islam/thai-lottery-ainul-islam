<?php

declare(strict_types=1);

namespace Tests\Feature\Payment\Slip;

use App\DTOs\Payment\SlipVerificationResult;
use App\DTOs\Security\FraudDecisionData;
use App\DTOs\Security\MalwareScanResult;
use App\Enums\Currency;
use App\Enums\FraudDecision;
use App\Enums\MalwareScanStatus;
use App\Enums\PaymentMethod;
use App\Enums\SlipVerificationStatus;
use App\Exceptions\FinancialException;
use App\Models\DepositSlipVerification;
use App\Models\UploadScanRecord;
use App\Services\Audit\AuditLogService;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Payment\BankSlipVerificationService;
use App\Services\Payment\Slip\SlipVerificationProviderInterface;
use App\Services\Uploads\UploadQuarantineService;
use App\Services\Uploads\UploadScanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Feature\Payment\PaymentTestCase;

final class SlipVerificationFlowTest extends PaymentTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'slip_verification.enabled' => true,
            'slip_verification.provider' => 'easyslip',
            'slip_verification.auto_credit' => false,
            'slip_verification.max_age_minutes' => 30,
            'slip_verification.receiving_accounts' => [[
                'bank' => 'SCB',
                'account_last_digits' => '1234',
                'name' => 'Example Receiver',
            ]],
            'slip_verification.providers.easyslip.base_url' => 'https://api.easyslip.com/v2',
            'slip_verification.providers.easyslip.verify_path' => '/verify/bank',
            'slip_verification.providers.easyslip.token' => 'test-token',
            'slip_verification.providers.easyslip.timeout' => 5,
        ]);
    }

    public function test_default_review_mode_records_a_valid_slip_without_credit(): void
    {
        $player = $this->createPlayer('0.00');
        // Amounts must satisfy the real finance.deposit.min guard (100.00 THB);
        // 120.00 keeps the review-mode assertions identical while staying legal.
        $deposit = app(DepositService::class)->request($player['wallet'], Money::of('120.00', Currency::THB), PaymentMethod::BankTransfer);
        $provider = $this->provider($this->slipResult('SLIP-REVIEW-001', '120.00'));

        $verification = $this->service($provider)->verify($player['user'], null, 'QR-VALID', '120.00', '203.0.113.20', $deposit);

        self::assertSame(SlipVerificationStatus::NeedsReview, $verification->status);
        self::assertSame('manual_review_required', $verification->review_reason);
        self::assertSame('0.00', $player['wallet']->fresh()->balance);
        self::assertSame('pending', $deposit->fresh()->status->value);
    }

    public function test_auto_credit_requires_exact_matching_pending_deposit_and_credits_once(): void
    {
        config(['slip_verification.auto_credit' => true]);
        $player = $this->createPlayer('0.00');
        // 121.25 keeps the satang-precision coverage of the original 91.25 case
        // while clearing finance.deposit.min (100.00 THB).
        $deposit = app(DepositService::class)->request($player['wallet'], Money::of('121.25', Currency::THB), PaymentMethod::BankTransfer);
        $service = $this->service($this->provider($this->slipResult('SLIP-CREDIT-001', '121.25')));

        $verification = $service->verify($player['user'], null, 'QR-VALID', '121.25', '203.0.113.20', $deposit);
        $replay = $service->verify($player['user'], null, 'QR-VALID', '121.25', '203.0.113.20', $deposit);

        self::assertSame(SlipVerificationStatus::Credited, $verification->status);
        self::assertSame(SlipVerificationStatus::Duplicate, $replay->status);
        self::assertSame('121.25', $player['wallet']->fresh()->balance);
        self::assertSame('confirmed', $deposit->fresh()->status->value);
        self::assertDatabaseCount('financial_transactions', 1);
        self::assertDatabaseCount('ledger_entries', 2);
    }

    public function test_wrong_amount_wrong_receiver_old_and_future_slips_are_never_credited(): void
    {
        $cases = [
            ['SLIP-WRONG-AMOUNT', $this->slipResult('SLIP-WRONG-AMOUNT', '92.00'), '91.00', 'amount_mismatch'],
            ['SLIP-WRONG-RECEIVER', $this->slipResult('SLIP-WRONG-RECEIVER', '91.00', receiverBank: 'KBANK'), '91.00', 'receiver_mismatch'],
            ['SLIP-EXPIRED', $this->slipResult('SLIP-EXPIRED', '91.00', at: CarbonImmutable::now()->subHours(2)), '91.00', 'expired'],
            ['SLIP-FUTURE', $this->slipResult('SLIP-FUTURE', '91.00', at: CarbonImmutable::now()->addMinutes(2)), '91.00', 'future_dated'],
        ];
        foreach ($cases as [, $result, $claimed, $reason]) {
            $player = $this->createPlayer('0.00');
            $verification = $this->service($this->provider($result))->verify($player['user'], null, 'QR-VALID', $claimed, '203.0.113.20');
            self::assertSame($reason, $verification->review_reason);
            self::assertNotSame(SlipVerificationStatus::Credited, $verification->status);
            self::assertSame('0.00', $player['wallet']->fresh()->balance);
        }
        self::assertDatabaseCount('financial_transactions', 0);
    }

    public function test_provider_duplicate_and_duplicate_transaction_reference_are_not_creditable(): void
    {
        $first = $this->createPlayer('0.00');
        $same = $this->slipResult('SLIP-DUP-001', '40.00');
        $firstRecord = $this->service($this->provider($same))->verify($first['user'], null, 'QR-VALID', '40.00', '203.0.113.20');
        self::assertSame(SlipVerificationStatus::NeedsReview, $firstRecord->status);

        $second = $this->createPlayer('0.00');
        $duplicate = $this->service($this->provider($same))->verify($second['user'], null, 'QR-VALID', '40.00', '203.0.113.21');
        self::assertSame(SlipVerificationStatus::Duplicate, $duplicate->status);
        self::assertSame('trans_ref_already_seen', $duplicate->review_reason);

        $providerDuplicate = $this->service($this->provider($this->slipResult('SLIP-DUP-PROVIDER', '40.00', duplicate: true)))
            ->verify($second['user'], null, 'QR-VALID', '40.00', '203.0.113.21');
        self::assertSame(SlipVerificationStatus::Duplicate, $providerDuplicate->status);
        self::assertSame('0.00', $first['wallet']->fresh()->balance);
        self::assertSame('0.00', $second['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
    }

    public function test_receiver_matching_rejects_a_slip_with_a_different_four_digit_account_suffix(): void
    {
        config(['slip_verification.receiving_accounts' => [[
            'bank' => 'SCB',
            'account_last_digits' => '5678',
            'name' => 'Example Receiver',
        ]]]);
        $player = $this->createPlayer('0.00');
        $result = $this->slipResult('SLIP-WRONG-SUFFIX-001', '35.00', receiverAccountMasked: '••••1234');

        $verification = $this->service($this->provider($result))
            ->verify($player['user'], null, 'QR-VALID', '35.00', '203.0.113.25');

        self::assertSame(SlipVerificationStatus::Rejected, $verification->status);
        self::assertSame('receiver_mismatch', $verification->review_reason);
        self::assertSame('0.00', $player['wallet']->fresh()->balance);
    }

    public function test_receiver_account_configuration_must_match_the_four_digits_exposed_by_provider_masks(): void
    {
        config(['slip_verification.receiving_accounts' => [[
            'bank' => 'SCB',
            'account_last_digits' => '001234',
            'name' => 'Example Receiver',
        ]]]);
        $player = $this->createPlayer('0.00');
        $provider = Mockery::mock(SlipVerificationProviderInterface::class);
        $provider->shouldNotReceive('verifyQr');

        try {
            $this->service($provider)->verify($player['user'], null, 'QR-VALID', '35.00', '203.0.113.26');
            self::fail('A receiver suffix longer than the provider exposes must fail configuration validation.');
        } catch (FinancialException $exception) {
            self::assertSame('slip_verification_not_configured', $exception->errorCode());
        }
        self::assertSame('0.00', $player['wallet']->fresh()->balance);
    }

    public function test_infected_upload_is_rejected_before_provider_verification(): void
    {
        $player = $this->createPlayer('0.00');
        $scanRecord = UploadScanRecord::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'private_storage_key' => 'quarantine/test/slip.jpg',
            'owning_model_type' => DepositSlipVerification::class,
            'owning_model_id' => (string) $player['user']->getKey(),
            'original_filename' => 'slip.jpg',
            'file_mime_type' => 'image/jpeg',
            'file_size_bytes' => 12,
            'file_sha256' => hash('sha256', 'test-bytes'),
            'scan_status' => MalwareScanStatus::PENDING_SCAN,
            'retries_count' => 0,
        ]);
        $quarantine = Mockery::mock(UploadQuarantineService::class);
        $quarantine->shouldReceive('stageInQuarantine')->once()->andReturn($scanRecord);
        $scanner = Mockery::mock(UploadScanService::class);
        $scanner->shouldReceive('scanRecord')->once()->andReturn(MalwareScanResult::infected('Test.Signature'));
        $provider = Mockery::mock(SlipVerificationProviderInterface::class);
        $provider->shouldNotReceive('verifyImage');
        $provider->shouldNotReceive('verifyQr');
        // A real non-empty file: the service rejects zero-byte uploads
        // (getSize() < 1) before quarantine, so tempnam() alone cannot work.
        // 12 bytes matches the file_size_bytes declared on the scan record.
        $slipPath = tempnam(sys_get_temp_dir(), 'slip');
        file_put_contents($slipPath, 'slip-bytes12');
        $image = new UploadedFile($slipPath, 'slip.jpg', 'image/jpeg', null, true);

        $verification = $this->service($provider, $quarantine, $scanner)->verify($player['user'], $image, null, '20.00', '203.0.113.22');

        self::assertSame(SlipVerificationStatus::Rejected, $verification->status);
        self::assertSame('upload_scan_not_clean', $verification->review_reason);
        self::assertSame('0.00', $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
    }

    public function test_provider_receives_only_the_hash_verified_quarantined_copy_of_a_clean_upload(): void
    {
        Storage::fake('private');
        $player = $this->createPlayer('0.00');
        $key = 'quarantine/test/clean-slip.jpg';
        $bytes = 'verified-quarantine-slip-bytes';
        Storage::disk('private')->put($key, $bytes);
        $scanRecord = UploadScanRecord::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'private_storage_key' => $key,
            'owning_model_type' => DepositSlipVerification::class,
            'owning_model_id' => (string) $player['user']->getKey(),
            'original_filename' => 'slip.jpg',
            'file_mime_type' => 'image/jpeg',
            'file_size_bytes' => strlen($bytes),
            'file_sha256' => hash('sha256', $bytes),
            'scan_status' => MalwareScanStatus::PENDING_SCAN,
            'retries_count' => 0,
        ]);
        $quarantine = Mockery::mock(UploadQuarantineService::class);
        $quarantine->shouldReceive('stageInQuarantine')->once()->andReturn($scanRecord);
        $quarantine->shouldReceive('readQuarantinedStream')->once()->with($key)->andReturnUsing(
            static fn (string $storageKey) => fopen(Storage::disk('private')->path($storageKey), 'rb'),
        );
        $scanner = Mockery::mock(UploadScanService::class);
        $scanner->shouldReceive('scanRecord')->once()->andReturnUsing(static function (string $id) use ($scanRecord): MalwareScanResult {
            $scanRecord->forceFill(['scan_status' => MalwareScanStatus::CLEAN])->save();

            return MalwareScanResult::clean(fileSha256: (string) $scanRecord->file_sha256, fileSizeBytes: (int) $scanRecord->file_size_bytes);
        });
        $providerPath = null;
        $provider = Mockery::mock(SlipVerificationProviderInterface::class);
        $provider->shouldReceive('name')->andReturn('easyslip');
        $provider->shouldReceive('verifyImage')->once()->with(Mockery::on(static function (mixed $path) use (&$providerPath, $bytes): bool {
            $providerPath = is_string($path) ? $path : null;

            return is_string($path) && is_file($path) && hash_file('sha256', $path) === hash('sha256', $bytes);
        }))->andReturn($this->slipResult('SLIP-IMAGE-001', '20.00'));
        $upload = tempnam(sys_get_temp_dir(), 'slip-upload-');
        self::assertIsString($upload);
        file_put_contents($upload, 'untrusted-original-upload');
        $image = new UploadedFile($upload, 'slip.jpg', 'image/jpeg', null, true);

        try {
            $verification = $this->service($provider, $quarantine, $scanner)->verify($player['user'], $image, null, '20.00', '203.0.113.24');
        } finally {
            unlink($upload);
        }

        self::assertSame(SlipVerificationStatus::NeedsReview, $verification->status);
        self::assertIsString($providerPath);
        self::assertFileDoesNotExist($providerPath);
        self::assertSame('0.00', $player['wallet']->fresh()->balance);
    }

    public function test_fraud_deny_and_provider_error_are_recorded_without_credit(): void
    {
        $player = $this->createPlayer('0.00');
        $deny = new FraudDecisionData(99, FraudDecision::DENY, ['test-deny']);
        $fraud = Mockery::mock(FraudDetectionService::class);
        $fraud->shouldReceive('evaluateTransactionRisk')->once()->andReturn($deny);
        $denied = $this->service($this->provider($this->slipResult('SLIP-FRAUD-001', '25.00')), fraud: $fraud)
            ->verify($player['user'], null, 'QR-VALID', '25.00', '203.0.113.23');
        self::assertSame(SlipVerificationStatus::Rejected, $denied->status);
        self::assertSame('fraud_denied', $denied->review_reason);

        $providerFailure = Mockery::mock(SlipVerificationProviderInterface::class);
        $providerFailure->shouldReceive('name')->andReturn('easyslip');
        $providerFailure->shouldReceive('verifyQr')->once()->andThrow(FinancialException::withCode('slip_provider_unreachable', 'safe failure'));
        $review = $this->service($providerFailure)->verify($player['user'], null, 'QR-VALID', '25.00', '203.0.113.23');
        self::assertSame(SlipVerificationStatus::NeedsReview, $review->status);
        self::assertSame('provider_unreachable', $review->review_reason);
        self::assertSame('0.00', $player['wallet']->fresh()->balance);
        self::assertDatabaseCount('financial_transactions', 0);
    }

    private function service(
        SlipVerificationProviderInterface $provider,
        ?UploadQuarantineService $quarantine = null,
        ?UploadScanService $scanner = null,
        ?FraudDetectionService $fraud = null,
    ): BankSlipVerificationService {
        return new BankSlipVerificationService(
            $quarantine ?? Mockery::mock(UploadQuarantineService::class),
            $scanner ?? Mockery::mock(UploadScanService::class),
            $fraud ?? $this->allowFraud(),
            $provider,
            app(DepositApprovalService::class),
            app(DepositCompletionService::class),
            app(AuditLogService::class),
        );
    }

    private function allowFraud(): FraudDetectionService
    {
        $decision = new FraudDecisionData(0, FraudDecision::ALLOW, []);
        $fraud = Mockery::mock(FraudDetectionService::class);
        $fraud->shouldReceive('evaluateTransactionRisk')->andReturn($decision);

        return $fraud;
    }

    private function provider(SlipVerificationResult $result): SlipVerificationProviderInterface
    {
        $provider = Mockery::mock(SlipVerificationProviderInterface::class);
        $provider->shouldReceive('name')->andReturn('easyslip');
        $provider->shouldReceive('verifyQr')->andReturn($result);

        return $provider;
    }

    private function slipResult(
        string $reference,
        string $amount,
        string $receiverBank = 'SCB',
        ?CarbonImmutable $at = null,
        bool $duplicate = false,
        string $receiverAccountMasked = '••••1234',
    ): SlipVerificationResult {
        return new SlipVerificationResult(
            transRef: $reference,
            amount: $amount,
            currency: 'THB',
            slipDateTime: $at ?? CarbonImmutable::now()->subMinute(),
            sendingBank: 'KBANK',
            receiverAccountMasked: $receiverAccountMasked,
            receiverName: 'Example Receiver',
            senderName: 'Example Sender',
            rawSanitised: ['trans_ref_hash' => hash('sha256', $reference)],
            valid: true,
            duplicate: $duplicate,
            receivingBank: $receiverBank,
        );
    }
}
