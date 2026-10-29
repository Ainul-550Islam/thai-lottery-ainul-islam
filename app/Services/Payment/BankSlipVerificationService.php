<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\FraudDecision;
use App\Enums\MalwareScanStatus;
use App\Enums\PaymentMethod;
use App\Enums\RiskLevel;
use App\Enums\SlipVerificationStatus;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\DepositSlipVerification;
use App\Models\UploadScanRecord;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\Money;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Payment\Slip\ProviderAccountMask;
use App\Services\Payment\Slip\SlipVerificationProviderInterface;
use App\Services\Uploads\UploadQuarantineService;
use App\Services\Uploads\UploadScanService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Provider-backed, exact-money and receiver-checked slip verification. */
final class BankSlipVerificationService
{
    public function __construct(
        private readonly UploadQuarantineService $quarantineService,
        private readonly UploadScanService $scanService,
        private readonly FraudDetectionService $fraudDetectionService,
        private readonly ?SlipVerificationProviderInterface $provider = null,
        private readonly ?DepositApprovalService $depositApprovalService = null,
        private readonly ?DepositCompletionService $depositCompletionService = null,
        private readonly ?AuditLogService $auditLogService = null,
    ) {}

    public function verify(
        User $user,
        ?UploadedFile $image,
        ?string $qrPayload,
        string $claimedAmount,
        string $clientIp,
        ?Deposit $deposit = null,
    ): DepositSlipVerification {
        if (! (bool) config('slip_verification.enabled', false)) {
            throw FinancialException::withCode('slip_verification_not_configured', 'Bank-slip verification is not configured.');
        }
        $this->assertSlipConfiguration();
        if (($image === null) === ($qrPayload === null)) {
            throw FinancialException::withCode('slip_invalid', 'Provide exactly one slip image or QR payload.');
        }
        if ($image instanceof UploadedFile) {
            $imageSize = $image->getSize();
            $configuredProvider = strtolower((string) config('slip_verification.provider', 'easyslip'));
            $maxImageBytes = $configuredProvider === 'slipok' ? 5 * 1024 * 1024 : 4_000_000;
            if (! is_int($imageSize) || $imageSize < 1 || $imageSize > $maxImageBytes) {
                throw FinancialException::withCode('slip_image_too_large', 'The slip image exceeds the configured provider upload limit.');
            }
        }
        $claimed = Money::of($claimedAmount, Currency::THB);
        if (! $claimed->isPositive()) {
            throw FinancialException::withCode('slip_invalid', 'The claimed slip amount must be positive.');
        }
        if ($user->trashed() || ! $user->exists) {
            throw FinancialException::withCode('slip_user_invalid', 'The authenticated user could not be verified.');
        }
        if (preg_match('/\A[0-9a-fA-F:.]{0,45}\z/D', $clientIp) !== 1) {
            $clientIp = '';
        }

        $scanRecordId = null;
        $providerImagePath = null;
        if ($image instanceof UploadedFile) {
            try {
                $scanRecord = $this->quarantineService->stageInQuarantine($image, DepositSlipVerification::class, (string) $user->getKey());
                $scanRecordId = (string) $scanRecord->getKey();
                $scanResult = $this->scanService->scanRecord($scanRecordId);
            } catch (Throwable $exception) {
                throw FinancialException::withCode('slip_upload_rejected', 'The uploaded slip could not be safely quarantined and scanned.', [], $exception);
            }
            if (! $scanResult->isClean()) {
                $verification = $this->makeNonProviderRecord(
                    $user,
                    $claimed->toString(),
                    'upload_scan_not_clean',
                    SlipVerificationStatus::Rejected,
                    $scanRecordId,
                );
                $this->audit($verification, AuditAction::SecurityAlert, RiskLevel::High, 'Slip upload did not pass malware scanning.', ['scan_status' => $scanResult->status->value]);

                return $verification;
            }
            try {
                $providerImagePath = $this->materialiseScannedImage($scanRecordId);
            } catch (Throwable $exception) {
                $verification = $this->makeNonProviderRecord(
                    $user,
                    $claimed->toString(),
                    'quarantined_file_unavailable',
                    SlipVerificationStatus::Rejected,
                    $scanRecordId,
                );
                $this->audit($verification, AuditAction::SecurityAlert, RiskLevel::High, 'Scanned slip bytes could not be safely read for verification.', ['scan_record_id' => $scanRecordId]);

                return $verification;
            }
        }

        try {
            $result = $qrPayload !== null
                ? $this->provider()->verifyQr($qrPayload)
                : $this->provider()->verifyImage($providerImagePath ?? throw FinancialException::withCode('slip_upload_rejected', 'A clean quarantined slip image is required.'));
        } catch (FinancialException $exception) {
            $duplicate = $exception->errorCode() === 'slip_duplicate';
            $reason = $duplicate ? 'provider_duplicate' : $this->safeReasonCode($exception->errorCode());
            $verification = $this->makeNonProviderRecord(
                $user,
                $claimed->toString(),
                $reason,
                $duplicate ? SlipVerificationStatus::Duplicate : SlipVerificationStatus::NeedsReview,
                $scanRecordId,
            );
            $this->audit(
                $verification,
                $duplicate ? AuditAction::SecurityAlert : AuditAction::Reconcile,
                $duplicate ? RiskLevel::High : RiskLevel::Medium,
                $duplicate ? 'Slip provider reported a duplicate transfer.' : 'Slip verification needs manual review after a provider error.',
                ['reason' => $reason],
            );

            return $verification;
        } catch (Throwable $exception) {
            $verification = $this->makeNonProviderRecord(
                $user,
                $claimed->toString(),
                'provider_error',
                SlipVerificationStatus::NeedsReview,
                $scanRecordId,
            );
            $this->audit($verification, AuditAction::Reconcile, RiskLevel::Medium, 'Slip verification needs manual review after an unexpected provider failure.', [
                'reason' => 'provider_error',
                'exception_class' => $exception::class,
            ]);

            return $verification;
        } finally {
            if (is_string($providerImagePath) && is_file($providerImagePath)) {
                unlink($providerImagePath);
            }
        }

        $hardFailure = $this->checkResult($result, $claimed->toString());
        if ($hardFailure === 'expired') {
            return $this->recordResult($user, $result, $claimed->toString(), $scanRecordId, SlipVerificationStatus::Expired, 'expired', null, false);
        }
        if ($hardFailure !== null) {
            $status = $hardFailure === 'duplicate' ? SlipVerificationStatus::Duplicate : SlipVerificationStatus::Rejected;

            return $this->recordResult($user, $result, $claimed->toString(), $scanRecordId, $status, $hardFailure, null, false);
        }

        try {
            $fraud = $this->fraudDetectionService->evaluateTransactionRisk(
                eventType: 'DEPOSIT',
                userId: (string) $user->getKey(),
                amount: $result->amount,
                currency: Currency::THB->value,
                context: ['reference_id' => hash('sha256', $result->transRef), 'client_ip' => $clientIp, 'rail' => 'bank_slip'],
            );
        } catch (Throwable $exception) {
            $verification = $this->recordResult($user, $result, $claimed->toString(), $scanRecordId,
                SlipVerificationStatus::NeedsReview, 'fraud_check_unavailable', null, false, null, true);
            $this->audit($verification, AuditAction::Reconcile, RiskLevel::High, 'Verified slip requires manual review because the fraud decision was unavailable.', [
                'trans_ref_hash' => hash('sha256', $result->transRef),
                'exception_class' => $exception::class,
            ]);

            return $verification;
        }
        if ($fraud->decision === FraudDecision::DENY) {
            return $this->recordResult($user, $result, $claimed->toString(), $scanRecordId, SlipVerificationStatus::Rejected, 'fraud_denied', null, false);
        }

        $matchingDeposit = $this->resolvePendingDeposit($user, $result, $deposit);
        if ($matchingDeposit === false) {
            return $this->recordResult($user, $result, $claimed->toString(), $scanRecordId, SlipVerificationStatus::Rejected, 'deposit_mismatch', null, false);
        }
        $canAutoCredit = (bool) config('slip_verification.auto_credit', false)
            && $matchingDeposit instanceof Deposit
            && $fraud->decision === FraudDecision::ALLOW;
        // Persist a reviewable state before the credit transaction. If the worker dies
        // after provider verification but before wallet settlement, staff still have a
        // durable queue row and the pending deposit remains uncredited.
        $status = SlipVerificationStatus::NeedsReview;
        $reason = $canAutoCredit
            ? 'automatic_credit_pending'
            : ($matchingDeposit instanceof Deposit ? 'manual_review_required' : 'matching_pending_deposit_not_found');

        $verification = $this->recordResult(
            $user,
            $result,
            $claimed->toString(),
            $scanRecordId,
            $status,
            $reason,
            $matchingDeposit instanceof Deposit ? $matchingDeposit : null,
            $canAutoCredit,
            $fraud->decision->value,
            true,
        );
        if (! $canAutoCredit || $verification->status !== SlipVerificationStatus::NeedsReview
            || $verification->review_reason !== 'automatic_credit_pending' || ! $matchingDeposit instanceof Deposit) {
            return $verification;
        }

        try {
            return DB::transaction(function () use ($verification, $matchingDeposit, $result, $user): DepositSlipVerification {
                $current = DepositSlipVerification::query()->lockForUpdate()->findOrFail($verification->getKey());
                if ($current->status === SlipVerificationStatus::Credited) {
                    return $current;
                }
                if ($current->status !== SlipVerificationStatus::NeedsReview
                    || $current->review_reason !== 'automatic_credit_pending'
                    || (int) $current->user_id !== (int) $user->getKey()) {
                    throw FinancialException::withCode('slip_verification_state_conflict', 'Slip verification state changed and requires review.');
                }
                $deposit = Deposit::query()->findOrFail($matchingDeposit->getKey());
                if ($deposit->status !== DepositStatus::Pending || $deposit->method !== PaymentMethod::BankTransfer
                    || $deposit->currency !== Currency::THB
                    || (int) $deposit->user_id !== (int) $user->getKey()
                    || bccomp((string) $deposit->amount, $result->amount, 2) !== 0
                    || ($deposit->provider_reference !== null && $deposit->provider_reference !== $result->transRef)) {
                    throw FinancialException::withCode('slip_deposit_no_longer_creditable', 'The matched deposit changed and requires manual review.');
                }
                if ($deposit->status === DepositStatus::Pending) {
                    $deposit = $this->approvalService()->approve($deposit, note: 'Provider-verified bank slip matched to this deposit.')['deposit'];
                }
                if (! in_array($deposit->status, [DepositStatus::Approved, DepositStatus::Processing, DepositStatus::Confirmed], true)) {
                    throw FinancialException::withCode('slip_deposit_no_longer_creditable', 'The matched deposit is not in a creditable state.');
                }
                $this->completionService()->complete($deposit, options: [
                    'provider' => PaymentMethod::BankTransfer->value,
                    'provider_reference' => $result->transRef,
                    'description' => 'Bank slip deposit '.$deposit->reference_number,
                    'metadata' => [
                        'payment_rail' => 'bank_slip_verification',
                        'slip_trans_ref_hash' => hash('sha256', $result->transRef),
                    ],
                ]);
                $current->forceFill([
                    'status' => SlipVerificationStatus::Credited,
                    'deposit_id' => $deposit->getKey(),
                    'review_reason' => null,
                ])->save();

                $this->audit($current, AuditAction::Deposit, RiskLevel::Medium, 'Verified bank slip credited the matched pending deposit.', [
                    'trans_ref_hash' => hash('sha256', $result->transRef),
                    'deposit_id' => (int) $deposit->getKey(),
                    'amount' => $result->amount,
                ]);

                return $current;
            }, 3);
        } catch (Throwable $exception) {
            $this->setNeedsReview($verification, 'deposit_completion_failed');
            throw FinancialException::withCode('slip_credit_unconfirmed', 'Slip verification succeeded but the deposit could not be safely credited; manual review is required.', [], $exception);
        }
    }

    private function assertSlipConfiguration(): void
    {
        $provider = strtolower(trim((string) config('slip_verification.provider', '')));
        $accounts = config('slip_verification.receiving_accounts', []);
        $accountsValid = is_array($accounts) && $accounts !== [];
        foreach ($accountsValid ? $accounts : [] as $account) {
            $digits = (string) (is_array($account) ? ($account['account_last_digits'] ?? '') : '');
            $accountsValid = $accountsValid && is_array($account)
                && \App\Enums\ThaiBankCode::resolve((string) ($account['bank'] ?? '')) !== null
                && preg_match('/\A[0-9]{4}\z/D', $digits) === 1
                && trim((string) ($account['name'] ?? '')) !== '';
        }
        $timeout = match ($provider) {
            'easyslip' => (int) config('slip_verification.providers.easyslip.timeout', 0),
            'slipok' => (int) config('slip_verification.providers.slipok.timeout', 0),
            default => 0,
        };
        $path = match ($provider) {
            'easyslip' => (string) config('slip_verification.providers.easyslip.verify_path', ''),
            'slipok' => str_replace('{branch_id}', rawurlencode((string) config('slip_verification.providers.slipok.branch_id', '')),
                (string) config('slip_verification.providers.slipok.verify_path', '')),
            default => '',
        };
        $baseUrl = match ($provider) {
            'easyslip' => (string) config('slip_verification.providers.easyslip.base_url', ''),
            'slipok' => (string) config('slip_verification.providers.slipok.base_url', ''),
            default => '',
        };
        $endpoint = rtrim($baseUrl, '/').'/'.ltrim($path, '/');
        $credentialPresent = match ($provider) {
            'easyslip' => trim((string) config('slip_verification.providers.easyslip.token', '')) !== '',
            'slipok' => trim((string) config('slip_verification.providers.slipok.branch_id', '')) !== ''
                && trim((string) config('slip_verification.providers.slipok.api_key', '')) !== '',
            default => false,
        };

        if (! $accountsValid || ! $credentialPresent || $timeout < 1 || $timeout > 60
            || (int) config('slip_verification.max_age_minutes', 0) < 1
            || ! $this->isSecureProviderEndpoint($endpoint)) {
            throw FinancialException::withCode('slip_verification_not_configured', 'Bank-slip verification is not fully configured for secure settlement checks.');
        }
    }

    private function isSecureProviderEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) (is_array($parts) ? ($parts['host'] ?? '') : ''));
        $expectedHost = match (strtolower((string) config('slip_verification.provider', ''))) {
            'easyslip' => 'api.easyslip.com',
            'slipok' => 'api.slipok.com',
            default => '',
        };
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === '' || $host !== $expectedHost
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.example')) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) === false
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function provider(): SlipVerificationProviderInterface
    {
        return $this->provider ?? app(SlipVerificationProviderInterface::class);
    }

    private function materialiseScannedImage(string $scanRecordId): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'bank-slip-');
        if (! is_string($temporaryPath) || $temporaryPath === '') {
            throw new \RuntimeException('Could not allocate a private temporary file for the scanned slip.');
        }

        $source = null;
        $target = null;
        try {
            $record = UploadScanRecord::query()->findOrFail($scanRecordId);
            if ($record->scan_status !== MalwareScanStatus::CLEAN || trim((string) $record->file_sha256) === '') {
                throw new \RuntimeException('The quarantined slip has not passed malware scanning.');
            }
            $source = $this->quarantineService->readQuarantinedStream((string) $record->private_storage_key);
            $target = fopen($temporaryPath, 'wb');
            if (! is_resource($target)) {
                throw new \RuntimeException('Could not open the temporary slip file.');
            }
            $copiedBytes = stream_copy_to_stream($source, $target);
            if (! is_int($copiedBytes) || $copiedBytes <= 0) {
                throw new \RuntimeException('Could not copy the scanned slip bytes.');
            }
            fclose($target);
            $target = null;
            fclose($source);
            $source = null;

            $copiedHash = hash_file('sha256', $temporaryPath);
            if (! is_string($copiedHash) || ! hash_equals((string) $record->file_sha256, $copiedHash)) {
                throw new \RuntimeException('The scanned slip bytes changed before verification.');
            }

            return $temporaryPath;
        } catch (Throwable $exception) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
            throw $exception;
        } finally {
            if (is_resource($target)) {
                fclose($target);
            }
            if (is_resource($source)) {
                fclose($source);
            }
        }
    }

    private function approvalService(): DepositApprovalService
    {
        return $this->depositApprovalService ?? app(DepositApprovalService::class);
    }

    private function completionService(): DepositCompletionService
    {
        return $this->depositCompletionService ?? app(DepositCompletionService::class);
    }

    private function checkResult(\App\DTOs\Payment\SlipVerificationResult $result, string $claimedAmount): ?string
    {
        if ($result->duplicate) {
            return 'duplicate';
        }
        if (! self::validTransactionReference($result->transRef)) {
            return 'provider_invalid';
        }
        if (! $result->valid) {
            return 'provider_invalid';
        }
        if (strtoupper($result->currency) !== 'THB') {
            return 'currency_mismatch';
        }
        try {
            $providerAmount = Money::of($result->amount, Currency::THB);
        } catch (Throwable) {
            return 'provider_amount_invalid';
        }
        if (! $providerAmount->isPositive() || bccomp($providerAmount->toString(), $claimedAmount, 2) !== 0) {
            return 'amount_mismatch';
        }
        if (! $this->receiverMatches($result)) {
            return 'receiver_mismatch';
        }
        $now = now();
        if ($result->slipDateTime->isFuture()) {
            return 'future_dated';
        }
        if ($result->slipDateTime->lt($now->copy()->subMinutes(max(1, (int) config('slip_verification.max_age_minutes', 30))))) {
            return 'expired';
        }

        return null;
    }

    private function receiverMatches(\App\DTOs\Payment\SlipVerificationResult $result): bool
    {
        $accounts = config('slip_verification.receiving_accounts', []);
        if (! is_array($accounts) || $accounts === []) {
            return false;
        }
        $actualBank = self::normaliseBank($result->receivingBank);
        $maskedSuffix = ProviderAccountMask::lastFourIfExposed($result->receiverAccountMasked);
        $actualSuffix = $maskedSuffix === '' ? '' : substr($maskedSuffix, -4);
        $actualName = self::normaliseName($result->receiverName);
        if ($actualBank === '' || strlen($actualSuffix) !== 4 || $actualName === '') {
            return false;
        }
        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }
            $expectedBank = self::normaliseBank((string) ($account['bank'] ?? ''));
            $expectedDigits = preg_replace('/\D+/', '', (string) ($account['account_last_digits'] ?? '')) ?? '';
            $expectedName = self::normaliseName((string) ($account['name'] ?? ''));
            $expectedSuffixLength = strlen($expectedDigits);
            if ($expectedBank !== '' && $expectedBank === $actualBank
                && $expectedSuffixLength === 4
                && strlen($actualSuffix) >= $expectedSuffixLength
                && hash_equals($expectedDigits, substr($actualSuffix, -$expectedSuffixLength))
                && $expectedName !== '' && hash_equals($expectedName, $actualName)) {
                return true;
            }
        }

        return false;
    }

    private function resolvePendingDeposit(User $user, \App\DTOs\Payment\SlipVerificationResult $result, ?Deposit $provided): Deposit|false|null
    {
        if ($provided instanceof Deposit) {
            $deposit = Deposit::query()->find($provided->getKey());
            if (! $deposit instanceof Deposit || (int) $deposit->user_id !== (int) $user->getKey()
                || $deposit->method !== PaymentMethod::BankTransfer || $deposit->status !== DepositStatus::Pending
                || $deposit->currency !== Currency::THB || bccomp((string) $deposit->amount, $result->amount, 2) !== 0
                || ($deposit->provider_reference !== null && $deposit->provider_reference !== $result->transRef)) {
                return false;
            }

            return $deposit;
        }

        $matches = Deposit::query()
            ->where('user_id', $user->getKey())
            ->where('method', PaymentMethod::BankTransfer->value)
            ->where('status', DepositStatus::Pending->value)
            ->where('currency', Currency::THB->value)
            ->where('amount', $result->amount)
            ->get();
        if ($matches->count() > 1) {
            return null;
        }

        return $matches->first();
    }

    private function recordResult(
        User $user,
        \App\DTOs\Payment\SlipVerificationResult $result,
        string $claimedAmount,
        ?string $scanRecordId,
        SlipVerificationStatus $status,
        ?string $reason,
        ?Deposit $deposit,
        bool $autoCredit,
        ?string $fraudDecision = null,
        bool $hardChecksPassed = false,
    ): DepositSlipVerification {
        $transRef = self::validTransactionReference($result->transRef) ? $result->transRef : null;
        $transRefHash = hash('sha256', $result->transRef);
        $payload = PaymentLogSanitizer::sanitize(array_merge($result->rawSanitised, [
            'trans_ref_hash' => $transRefHash,
            'claimed_amount' => $claimedAmount,
            'valid' => $result->valid,
            'duplicate' => $result->duplicate,
            'verification_facts' => [
                'hard_checks_passed' => $hardChecksPassed,
                'provider_valid' => $result->valid,
                'provider_duplicate' => $result->duplicate,
                'provider_amount' => $result->amount,
                'claimed_amount' => $claimedAmount,
                'currency' => strtoupper($result->currency),
                'receiver_matches' => $this->receiverMatches($result),
                'fraud_decision' => $fraudDecision,
                'trans_ref_hash' => $transRefHash,
            ],
        ]));
        try {
            $record = DB::transaction(function () use ($user, $result, $transRef, $scanRecordId, $status, $reason, $deposit, $payload): DepositSlipVerification {
                $existing = $transRef !== null
                    ? DepositSlipVerification::query()->where('trans_ref', $transRef)->lockForUpdate()->first()
                    : null;
                if ($existing instanceof DepositSlipVerification || $result->duplicate) {
                    $row = DepositSlipVerification::query()->create([
                        'user_id' => (int) $user->getKey(),
                        'deposit_id' => null,
                        'trans_ref' => null,
                        'sending_bank' => substr($result->sendingBank, 0, 80),
                        'receiving_account_masked' => substr($result->receiverAccountMasked, 0, 80),
                        'amount' => $result->amount,
                        'currency' => strtoupper($result->currency),
                        'slip_datetime' => $result->slipDateTime,
                        'provider' => $this->provider()->name(),
                        'status' => SlipVerificationStatus::Duplicate,
                        'review_reason' => $existing ? 'trans_ref_already_seen' : 'provider_duplicate',
                        'upload_scan_record_id' => $scanRecordId,
                        'provider_payload' => array_merge($payload, ['trans_ref_hash' => hash('sha256', $result->transRef)]),
                    ]);

                    return $row;
                }

                return DepositSlipVerification::query()->create([
                    'user_id' => (int) $user->getKey(),
                    'deposit_id' => $deposit?->getKey(),
                    'trans_ref' => $transRef,
                    'sending_bank' => substr($result->sendingBank, 0, 80),
                    'receiving_account_masked' => substr($result->receiverAccountMasked, 0, 80),
                    'amount' => $result->amount,
                    'currency' => strtoupper($result->currency),
                    'slip_datetime' => $result->slipDateTime,
                    'provider' => $this->provider()->name(),
                    'status' => $status,
                    'review_reason' => $reason,
                    'upload_scan_record_id' => $scanRecordId,
                    'provider_payload' => $payload,
                ]);
            }, 3);
        } catch (QueryException $exception) {
            if ($transRef !== null && DepositSlipVerification::query()->where('trans_ref', $transRef)->exists()) {
                $duplicate = DepositSlipVerification::query()->create([
                    'user_id' => (int) $user->getKey(),
                    'trans_ref' => null,
                    'amount' => $result->amount,
                    'currency' => strtoupper($result->currency),
                    'slip_datetime' => $result->slipDateTime,
                    'provider' => $this->provider()->name(),
                    'status' => SlipVerificationStatus::Duplicate,
                    'review_reason' => 'trans_ref_already_seen',
                    'upload_scan_record_id' => $scanRecordId,
                    'provider_payload' => array_merge($payload, ['trans_ref_hash' => hash('sha256', $result->transRef)]),
                ]);
                $this->audit($duplicate, AuditAction::SecurityAlert, RiskLevel::High, 'Concurrent bank-slip verification reused an existing transaction reference.', [
                    'trans_ref_hash' => hash('sha256', $result->transRef),
                    'status' => SlipVerificationStatus::Duplicate->value,
                    'reason' => 'trans_ref_already_seen',
                ]);

                return $duplicate;
            }
            throw $exception;
        }
        $this->audit($record, $record->status === SlipVerificationStatus::Duplicate ? AuditAction::SecurityAlert : AuditAction::Create,
            $record->status === SlipVerificationStatus::Duplicate ? RiskLevel::High : RiskLevel::Low,
            'Bank slip verification result recorded.', [
                'trans_ref_hash' => hash('sha256', $result->transRef),
                'status' => $record->status->value,
                'reason' => $record->review_reason,
                'deposit_id' => $deposit?->getKey(),
            ]);
        if ($record->status === SlipVerificationStatus::Duplicate) {
            return $record;
        }
        if ($autoCredit && $record->status !== SlipVerificationStatus::Verified) {
            return $record;
        }

        return $record;
    }

    private function makeNonProviderRecord(User $user, string $amount, string $reason, SlipVerificationStatus $status, ?string $scanRecordId): DepositSlipVerification
    {
        $record = DepositSlipVerification::query()->create([
            'user_id' => (int) $user->getKey(),
            'amount' => $amount,
            'currency' => 'THB',
            'provider' => strtolower((string) config('slip_verification.provider', 'easyslip')),
            'status' => $status,
            'review_reason' => $reason,
            'upload_scan_record_id' => $scanRecordId,
            'provider_payload' => [],
        ]);

        return $record;
    }

    private function setNeedsReview(DepositSlipVerification $verification, string $reason): void
    {
        DB::transaction(function () use ($verification, $reason): void {
            $current = DepositSlipVerification::query()->lockForUpdate()->findOrFail($verification->getKey());
            if ($current->status === SlipVerificationStatus::NeedsReview
                && $current->review_reason === 'automatic_credit_pending') {
                $current->forceFill(['status' => SlipVerificationStatus::NeedsReview, 'review_reason' => $reason])->save();
                $this->audit($current, AuditAction::Reconcile, RiskLevel::High, 'Slip deposit completion failed and requires manual review.', [
                    'trans_ref_hash' => is_string($current->trans_ref) ? hash('sha256', $current->trans_ref) : null,
                    'reason' => $reason,
                ]);
            }
        }, 3);
    }

    private function audit(DepositSlipVerification $verification, AuditAction $action, RiskLevel $risk, string $description, array $metadata): void
    {
        ($this->auditLogService ?? app(AuditLogService::class))->log(
            userId: (int) $verification->user_id,
            action: $action,
            riskLevel: $risk,
            auditable: $verification,
            description: $description,
            metadata: PaymentLogSanitizer::sanitize($metadata),
        );
    }

    private function safeReasonCode(?string $code): string
    {
        return match ($code) {
            'slip_provider_unreachable' => 'provider_unreachable',
            'slip_provider_not_configured' => 'provider_not_configured',
            'slip_invalid' => 'provider_rejected_slip',
            default => 'provider_error',
        };
    }

    private static function validTransactionReference(string $reference): bool
    {
        return preg_match('~\A[A-Za-z0-9][A-Za-z0-9._:/-]{0,190}\z~D', $reference) === 1;
    }

    private static function normaliseBank(string $bank): string
    {
        $resolved = \App\Enums\ThaiBankCode::resolve($bank);

        return $resolved?->value ?? '';
    }

    private static function normaliseName(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');

        return preg_replace('/\s+/u', '', $name) ?? '';
    }
}
