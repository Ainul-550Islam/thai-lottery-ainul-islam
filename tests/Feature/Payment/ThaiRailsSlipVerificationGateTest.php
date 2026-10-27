<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\DTOs\Payment\SlipVerificationResult;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use App\Enums\SlipVerificationStatus;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Payment\BankSlipVerificationService;
use App\Services\Payment\Slip\SlipVerificationProviderInterface;
use App\DTOs\Security\FraudDecisionData;
use App\Enums\FraudDecision;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;

/**
 * The slip lane's refusals, as behaviour rather than as configuration.
 *
 * WHY THESE ASSERTIONS EXIST
 * A verified bank slip is an instruction to credit a wallet with real money. The
 * provider is the only thing standing between a thief and that credit, so every
 * reason the provider can return a slip must be shown to STOP the flow — not to
 * be logged and continued past. Each test below drives a real
 * BankSlipVerificationService with a provider that reports one specific bad
 * property, and asserts the persisted verdict.
 *
 * The fixture provider is injected, so these tests never touch the network: the
 * subject under test is the service's decision, not EasySlip's uptime.
 */
final class ThaiRailsSlipVerificationGateTest extends PaymentTestCase
{
    private const RECEIVER_SUFFIX = '1234';

    private const RECEIVER_NAME = 'Thai Lottery Co';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSlipLane();
    }

    public function test_a_duplicate_slip_is_refused_and_never_credits(): void
    {
        $player = $this->createPlayer();
        $deposit = $this->createPendingDeposit($player['wallet'], '1000.00', PaymentMethod::BankTransfer);
        $balanceBefore = (string) $player['wallet']->fresh()->balance;

        $verification = $this->serviceWith($this->slip(duplicate: true))
            ->verify($player['user'], null, 'QR-DUPLICATE', '1000.00', '127.0.0.1', $deposit);

        self::assertSame(SlipVerificationStatus::Duplicate, $verification->status);
        self::assertSame('provider_duplicate', $verification->review_reason);
        self::assertNull($verification->deposit_id);
        $this->assertDepositStillPending($deposit->refresh());
        self::assertSame($balanceBefore, (string) $player['wallet']->fresh()->balance);
    }

    public function test_the_same_slip_cannot_be_submitted_twice(): void
    {
        // The provider says the slip is fine BOTH times; the refusal has to come
        // from this system remembering the transaction reference it already saw.
        $player = $this->createPlayer();
        $deposit = $this->createPendingDeposit($player['wallet'], '1000.00', PaymentMethod::BankTransfer);

        $slip = $this->slip();

        $first = $this->serviceWith($slip)
            ->verify($player['user'], null, 'QR-FIRST', '1000.00', '127.0.0.1', $deposit);
        self::assertSame(SlipVerificationStatus::NeedsReview, $first->status);

        $second = $this->serviceWith($slip)
            ->verify($player['user'], null, 'QR-SECOND', '1000.00', '127.0.0.1', $deposit);

        self::assertSame(SlipVerificationStatus::Duplicate, $second->status);
        self::assertSame('trans_ref_already_seen', $second->review_reason);
        $this->assertDepositStillPending($deposit->refresh());
    }

    public function test_a_slip_older_than_max_age_minutes_is_expired_and_never_credits(): void
    {
        $player = $this->createPlayer();
        $deposit = $this->createPendingDeposit($player['wallet'], '1000.00', PaymentMethod::BankTransfer);
        $balanceBefore = (string) $player['wallet']->fresh()->balance;

        // max_age_minutes is 30; the slip is 31 minutes old.
        $this->configureSlipLane(maxAgeMinutes: 30);

        $verification = $this->serviceWith(
            $this->slip(slipDateTime: CarbonImmutable::now()->subMinutes(31)),
        )->verify($player['user'], null, 'QR-EXPIRED', '1000.00', '127.0.0.1', $deposit);

        self::assertSame(SlipVerificationStatus::Expired, $verification->status);
        self::assertSame('expired', $verification->review_reason);
        $this->assertDepositStillPending($deposit->refresh());
        self::assertSame($balanceBefore, (string) $player['wallet']->fresh()->balance);
    }

    public function test_a_slip_inside_max_age_minutes_is_not_expired(): void
    {
        // The boundary the previous test relies on, asserted from the other
        // side: the same slip, 29 minutes old, must clear the age check.
        $player = $this->createPlayer();
        $deposit = $this->createPendingDeposit($player['wallet'], '1000.00', PaymentMethod::BankTransfer);

        $verification = $this->serviceWith(
            $this->slip(slipDateTime: CarbonImmutable::now()->subMinutes(29)),
        )->verify($player['user'], null, 'QR-FRESH', '1000.00', '127.0.0.1', $deposit);

        self::assertNotSame(SlipVerificationStatus::Expired, $verification->status);
        self::assertSame('manual_review_required', $verification->review_reason);
    }

    public function test_a_slip_whose_amount_disagrees_with_the_claim_is_refused(): void
    {
        $player = $this->createPlayer();
        $deposit = $this->createPendingDeposit($player['wallet'], '1000.00', PaymentMethod::BankTransfer);

        // Provider says 1,000.00; the player claims 2,000.00.
        $verification = $this->serviceWith($this->slip(amount: '1000.00'))
            ->verify($player['user'], null, 'QR-AMOUNT', '2000.00', '127.0.0.1', $deposit);

        self::assertSame(SlipVerificationStatus::Rejected, $verification->status);
        self::assertSame('amount_mismatch', $verification->review_reason);
        $this->assertDepositStillPending($deposit->refresh());
    }

    public function test_a_slip_paid_to_someone_elses_account_is_refused(): void
    {
        $player = $this->createPlayer();
        $deposit = $this->createPendingDeposit($player['wallet'], '1000.00', PaymentMethod::BankTransfer);

        $verification = $this->serviceWith($this->slip(receiverSuffix: '9999'))
            ->verify($player['user'], null, 'QR-RECEIVER', '1000.00', '127.0.0.1', $deposit);

        self::assertSame(SlipVerificationStatus::Rejected, $verification->status);
        self::assertSame('receiver_mismatch', $verification->review_reason);
        $this->assertDepositStillPending($deposit->refresh());
    }

    public function test_a_slip_paid_to_a_different_receiver_name_is_refused(): void
    {
        $player = $this->createPlayer();
        $deposit = $this->createPendingDeposit($player['wallet'], '1000.00', PaymentMethod::BankTransfer);

        // Right bank, right last four, wrong account holder.
        $verification = $this->serviceWith($this->slip(receiverName: 'Someone Else Ltd'))
            ->verify($player['user'], null, 'QR-NAME', '1000.00', '127.0.0.1', $deposit);

        self::assertSame(SlipVerificationStatus::Rejected, $verification->status);
        self::assertSame('receiver_mismatch', $verification->review_reason);
        $this->assertDepositStillPending($deposit->refresh());
    }

    public function test_auto_credit_false_never_credits_a_matching_deposit(): void
    {
        $player = $this->createPlayer();
        $deposit = $this->createPendingDeposit($player['wallet'], '1000.00', PaymentMethod::BankTransfer);
        $balanceBefore = (string) $player['wallet']->fresh()->balance;

        // A slip that passes every check: right amount, right receiver, fresh,
        // not a duplicate — and the fraud engine explicitly allows it. The ONLY
        // thing standing between it and a credit is auto_credit=false.
        $this->configureSlipLane(autoCredit: false);

        $verification = $this->serviceWith($this->slip(), allowFraud: true)
            ->verify($player['user'], null, 'QR-AUTOCREdit-OFF', '1000.00', '127.0.0.1', $deposit);

        self::assertSame(SlipVerificationStatus::NeedsReview, $verification->status);
        self::assertSame('manual_review_required', $verification->review_reason);
        self::assertFalse((bool) $verification->auto_credit);
        self::assertSame($deposit->getKey(), $verification->deposit_id);

        // The money must not have moved.
        $this->assertDepositStillPending($deposit->refresh());
        self::assertSame($balanceBefore, (string) $player['wallet']->fresh()->balance);
    }

    private function assertDepositStillPending(\App\Models\Deposit $deposit): void
    {
        self::assertSame(DepositStatus::Pending, $deposit->status);
    }

    private function configureSlipLane(bool $autoCredit = false, int $maxAgeMinutes = 30): void
    {
        Config::set('slip_verification.enabled', true);
        Config::set('slip_verification.provider', 'easyslip');
        Config::set('slip_verification.auto_credit', $autoCredit);
        Config::set('slip_verification.max_age_minutes', $maxAgeMinutes);
        Config::set('slip_verification.receiving_accounts', [[
            'bank' => 'SCB',
            'account_last_digits' => self::RECEIVER_SUFFIX,
            'name' => self::RECEIVER_NAME,
        ]]);
        Config::set('slip_verification.providers.easyslip.base_url', 'https://api.easyslip.com');
        Config::set('slip_verification.providers.easyslip.verify_path', '/verify/bank');
        Config::set('slip_verification.providers.easyslip.token', 'test-easyslip-token');
        Config::set('slip_verification.providers.easyslip.timeout', 5);
    }

    private function slip(
        ?string $amount = null,
        ?CarbonImmutable $slipDateTime = null,
        bool $duplicate = false,
        string $receiverSuffix = self::RECEIVER_SUFFIX,
        string $receiverName = self::RECEIVER_NAME,
    ): SlipVerificationResult {
        return new SlipVerificationResult(
            transRef: 'TRX'.strtoupper(bin2hex(random_bytes(8))),
            amount: $amount ?? '1000.00',
            currency: 'THB',
            slipDateTime: $slipDateTime ?? CarbonImmutable::now()->subMinutes(5),
            sendingBank: 'KBANK',
            receiverAccountMasked: 'xxx-xxx-'.$receiverSuffix,
            receiverName: $receiverName,
            senderName: 'Somchai Jaidee',
            rawSanitised: [],
            valid: true,
            duplicate: $duplicate,
            receivingBank: 'SCB',
        );
    }

    private function serviceWith(SlipVerificationResult $result, bool $allowFraud = false): BankSlipVerificationService
    {
        $provider = new class($result) implements SlipVerificationProviderInterface
        {
            public function __construct(private readonly SlipVerificationResult $result) {}

            public function verifyQr(string $payload): SlipVerificationResult
            {
                return $this->result;
            }

            public function verifyImage(UploadedFile|string $image): SlipVerificationResult
            {
                return $this->result;
            }

            public function name(): string
            {
                return 'easyslip';
            }
        };

        $fraud = $allowFraud ? $this->allowingFraudEngine() : null;

        return new BankSlipVerificationService(
            quarantineService: app(\App\Services\Uploads\UploadQuarantineService::class),
            scanService: app(\App\Services\Uploads\UploadScanService::class),
            fraudDetectionService: $fraud ?? app(FraudDetectionService::class),
            provider: $provider,
        );
    }

    private function allowingFraudEngine(): FraudDetectionService
    {
        $engine = \Mockery::mock(FraudDetectionService::class);
        $engine->shouldReceive('evaluateTransactionRisk')
            ->andReturn(new FraudDecisionData(
                riskScore: 0,
                decision: FraudDecision::ALLOW,
                reasonCodes: [],
            ));

        return $engine;
    }
}
