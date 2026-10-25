<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\FraudDecision;
use App\Enums\PaymentMethod;
use App\Enums\RiskLevel;
use App\Enums\TruemoneyRedemptionStatus;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\TruemoneyVoucherRedemption;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\Deposit\DepositProcessingService;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Lock\DistributedLockService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Real, opt-in TrueMoney gift-voucher redemption.
 *
 * The baseline PaymentMethod and DepositIntegrityGuard do not admit a canonical
 * `truemoney` provider. This module therefore uses the existing canonical
 * bank_transfer evidence and records the TrueMoney rail in sanitized metadata;
 * it deliberately does not weaken or bypass the guard. The later integration
 * batch may add a first-class payment method and migrate that representation.
 */
final class TrueMoneyWalletService
{
    private const DEFAULT_VOUCHER_HOST = 'gift.truemoney.com';

    public function __construct(
        private readonly DepositProcessingService $legacyDepositProcessingService,
        private readonly FraudDetectionService $fraudDetectionService,
        private readonly DistributedLockService $lockService,
        private readonly ?DepositService $depositRequestService = null,
        private readonly ?DepositApprovalService $depositApprovalService = null,
        private readonly ?DepositCompletionService $depositCompletionService = null,
        private readonly ?AuditLogService $auditLogService = null,
    ) {}

    /**
     * Redeem a provider voucher once and credit only the exact amount returned by
     * TrueMoney. The raw URL and voucher code never leave this method.
     *
     * @param User|int|string $user
     * @return array{success: bool, amount: string, deposit_reference: string, redemption_id: int, transaction_id: int}
     *
     * @throws FinancialException
     */
    public function redeemVoucher(User|int|string $user, string $voucherUrl, string $clientIp): array
    {
        if (! (bool) config('truemoney.enabled', false)) {
            throw FinancialException::withCode('truemoney_not_configured', 'TrueMoney voucher redemption is not configured.');
        }
        if (! (bool) config('truemoney.acknowledged_unofficial_api', false)) {
            throw FinancialException::withCode('truemoney_api_not_acknowledged', 'TrueMoney voucher redemption requires an explicit acknowledgement of the unofficial API.');
        }

        $merchantMobile = trim((string) config('truemoney.merchant_mobile', ''));
        if ($merchantMobile === '' || preg_match('/\A(?:0[0-9]{9}|\+66[0-9]{9})\z/D', $merchantMobile) !== 1) {
            throw FinancialException::withCode('truemoney_not_configured', 'TrueMoney merchant mobile is not configured.');
        }
        $host = strtolower(trim((string) config('truemoney.allowed_host', self::DEFAULT_VOUCHER_HOST)));
        $this->assertProviderConfigured($host);
        try {
            $voucherCode = $this->extractVoucherCode($voucherUrl, $host);
        } catch (InvalidArgumentException $exception) {
            throw FinancialException::withCode('truemoney_voucher_invalid', 'The TrueMoney voucher URL is invalid.', [], $exception);
        }
        $voucherHash = self::hashVoucherCode($voucherCode);
        $voucherLast4 = self::voucherLastFour($voucherCode);
        $userModel = $user instanceof User ? $user : User::query()->find((int) $user);
        if (! $userModel instanceof User || $userModel->trashed()) {
            throw FinancialException::withCode('truemoney_user_invalid', 'The authenticated user could not be verified.');
        }
        if (preg_match('/\A[0-9a-fA-F:.]{0,45}\z/D', $clientIp) !== 1) {
            $clientIp = '';
        }

        try {
            $lockTtl = min(900, max(60, (int) config('truemoney.timeout_seconds', 10) + 120));

            return $this->lockService->runWithLock(
                'payment:truemoney:user:'.$userModel->getKey().':'.now()->toDateString(),
                fn (): array => $this->lockService->runWithLock(
                    'payment:truemoney:voucher:'.$voucherHash,
                    fn (): array => $this->redeemWithinLock($userModel, $voucherCode, $voucherHash, $voucherLast4, $merchantMobile, $clientIp),
                    ttlSeconds: $lockTtl,
                    maxAttempts: 2,
                ),
                ttlSeconds: $lockTtl,
                maxAttempts: 2,
            );
        } catch (FinancialException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // A distributed-lock/backend failure must not produce a provider call.
            throw FinancialException::withCode('truemoney_processing_unavailable', 'The voucher could not be processed safely; no retry was performed.', [], $exception);
        }
    }

    /** Preserve the legacy public parser used by existing callers and tests. */
    public function extractVoucherCode(string $url, ?string $allowedHost = null): string
    {
        $host = strtolower(trim($allowedHost ?? self::DEFAULT_VOUCHER_HOST));
        $parts = parse_url(trim($url));
        $query = is_array($parts) ? (string) ($parts['query'] ?? '') : '';
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== $host
            || ! in_array((string) ($parts['path'] ?? ''), ['/campaign/', '/campaign'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
            || preg_match('/\Av=([A-Za-z0-9]{32,64})\z/D', $query, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid TrueMoney voucher URL format.');
        }

        return $matches[1];
    }

    public static function hashVoucherCode(string $voucherCode): string
    {
        return hash('sha256', $voucherCode);
    }

    public static function voucherLastFour(string $voucherCode): string
    {
        return substr($voucherCode, -4);
    }

    private function redeemWithinLock(User $user, string $voucherCode, string $voucherHash, string $voucherLast4, string $merchantMobile, string $clientIp): array
    {
        try {
            $redemption = $this->reserveVoucher($user, $voucherHash, $voucherLast4, $clientIp);
        } catch (FinancialException $exception) {
            if ($exception->errorCode() === 'truemoney_voucher_already_processed') {
                $existing = TruemoneyVoucherRedemption::query()->where('voucher_hash', $voucherHash)->first();
                if ($existing instanceof TruemoneyVoucherRedemption) {
                    $this->audit($existing, AuditAction::SecurityAlert, RiskLevel::High, 'Duplicate TrueMoney voucher submission refused before provider dispatch.', [
                        'voucher_last4' => $voucherLast4,
                        'attempting_user_id' => (int) $user->getKey(),
                        'existing_status' => $existing->status->value,
                    ]);
                }
            }
            throw $exception;
        }
        try {
            $decision = $this->fraudDetectionService->evaluateTransactionRisk(
                eventType: 'DEPOSIT',
                userId: (string) $user->getKey(),
                amount: '0.00',
                currency: Currency::THB->value,
                context: ['reference_id' => $voucherHash, 'client_ip' => $clientIp, 'rail' => 'truemoney'],
            );
        } catch (Throwable $exception) {
            $this->updateRedemption($redemption, [
                'status' => TruemoneyRedemptionStatus::Rejected,
                'failure_code' => 'risk_check_unavailable',
                'provider_status' => 'not_called',
            ]);
            $this->audit($redemption, AuditAction::Reconcile, RiskLevel::High, 'TrueMoney voucher refused because the risk check was unavailable before provider dispatch.', [
                'voucher_last4' => $voucherLast4,
                'reason' => 'risk_check_unavailable',
            ]);
            throw FinancialException::withCode('truemoney_risk_check_unavailable', 'Voucher redemption could not pass the required risk check; no provider call was made.', [], $exception);
        }
        if ($decision->decision !== FraudDecision::ALLOW) {
            $reason = $decision->decision === FraudDecision::DENY ? 'fraud_denied' : 'fraud_review_before_provider';
            $this->updateRedemption($redemption, [
                'status' => TruemoneyRedemptionStatus::Rejected,
                'failure_code' => $reason,
                'provider_status' => 'not_called',
            ]);
            $this->audit($redemption, AuditAction::SecurityAlert, RiskLevel::High, 'TrueMoney voucher redemption refused before provider dispatch by fraud policy.', [
                'voucher_last4' => $voucherLast4,
                'decision' => $decision->decision->value,
            ]);
            throw FinancialException::withCode('truemoney_fraud_denied', 'Voucher redemption was refused by risk controls.');
        }

        // Persist the at-most-once dispatch marker before the network call. A process
        // death at any point from here leaves an uncertain row and cannot authorize
        // another provider invocation.
        $this->updateRedemption($redemption, [
            'status' => TruemoneyRedemptionStatus::RedeemedUnconfirmed,
            'provider_status' => 'dispatch_started_or_unknown',
            'failure_code' => 'provider_call_started_or_unknown',
            'provider_payload' => ['dispatch' => 'started_or_unknown'],
        ]);
        $this->audit($redemption, AuditAction::Reconcile, RiskLevel::High, 'TrueMoney voucher dispatch started; the outcome must be confirmed before any credit.', [
            'voucher_last4' => $voucherLast4,
            'provider_status' => 'dispatch_started_or_unknown',
            'reason' => 'provider_dispatch_started',
        ]);
        $response = $this->callProvider($voucherCode, $merchantMobile, $redemption, $voucherLast4);
        $providerResult = $this->parseProviderResponse($response);
        if ($providerResult['kind'] === 'rejected') {
            $this->updateRedemption($redemption, [
                'status' => TruemoneyRedemptionStatus::Rejected,
                'provider_status' => $providerResult['status'],
                'failure_code' => $providerResult['failure_code'],
                'provider_payload' => $providerResult['safe_payload'],
            ]);
            $this->audit($redemption, AuditAction::Deposit, RiskLevel::Medium, 'TrueMoney provider rejected a voucher redemption.', [
                'voucher_last4' => $voucherLast4,
                'provider_status' => $providerResult['status'],
            ]);
            throw FinancialException::withCode('truemoney_voucher_rejected', 'The voucher was not accepted by the provider.');
        }
        if ($providerResult['kind'] !== 'redeemed') {
            $this->markRedeemedUnconfirmed($redemption, $voucherLast4, $providerResult['status'], $providerResult['safe_payload'], 'provider_response_ambiguous');
            throw FinancialException::withCode('truemoney_outcome_unknown', 'The provider outcome is uncertain; the voucher was reserved for reconciliation and will not be retried.');
        }

        $amount = $providerResult['amount'];
        $this->updateRedemption($redemption, [
            // Keep the durable uncertain state until the wallet credit commits.
            // A process death after provider success must still be picked up by reconciliation.
            'status' => TruemoneyRedemptionStatus::RedeemedUnconfirmed,
            'amount' => $amount,
            'currency' => 'THB',
            'provider_status' => $providerResult['status'],
            'failure_code' => 'provider_confirmed_credit_pending',
            'provider_payload' => $providerResult['safe_payload'],
        ]);
        try {
            $dailyLimitExceeded = $this->wouldExceedDailyAmount($user, $redemption, $amount);
        } catch (Throwable $exception) {
            $this->markRedeemedUnconfirmed($redemption, $voucherLast4, $providerResult['status'], $providerResult['safe_payload'], 'daily_limit_check_unavailable');
            throw FinancialException::withCode('truemoney_review_required', 'The voucher was redeemed but its daily limit could not be verified; manual reconciliation is required.', [], $exception);
        }
        if ($dailyLimitExceeded) {
            $this->markRedeemedUnconfirmed($redemption, $voucherLast4, $providerResult['status'], $providerResult['safe_payload'], 'daily_amount_limit_after_redeem');
            throw FinancialException::withCode('truemoney_review_required', 'The voucher was redeemed but exceeded the daily credit limit; manual reconciliation is required.');
        }

        try {
            $actualRisk = $this->fraudDetectionService->evaluateTransactionRisk(
                eventType: 'DEPOSIT',
                userId: (string) $user->getKey(),
                amount: $amount,
                currency: Currency::THB->value,
                context: ['reference_id' => $voucherHash, 'client_ip' => $clientIp, 'rail' => 'truemoney'],
            );
        } catch (Throwable $exception) {
            $this->markRedeemedUnconfirmed($redemption, $voucherLast4, $providerResult['status'], $providerResult['safe_payload'], 'fraud_check_unavailable_after_redeem');
            throw FinancialException::withCode('truemoney_review_required', 'The voucher was redeemed but its risk check could not be confirmed; manual reconciliation is required.', [], $exception);
        }
        if ($actualRisk->decision !== FraudDecision::ALLOW) {
            $this->markRedeemedUnconfirmed($redemption, $voucherLast4, $providerResult['status'], $providerResult['safe_payload'], 'fraud_review_required');
            throw FinancialException::withCode('truemoney_review_required', 'The voucher was redeemed but requires manual reconciliation before credit.');
        }
        $reconciliationPayload = array_merge($providerResult['safe_payload'], ['risk_decision' => 'allow']);
        $this->updateRedemption($redemption, [
            'status' => TruemoneyRedemptionStatus::RedeemedUnconfirmed,
            'failure_code' => 'credit_pending',
            'provider_payload' => $reconciliationPayload,
        ]);

        try {
            $credited = DB::transaction(function () use ($redemption, $user, $amount, $voucherHash, $voucherLast4, $providerResult): array {
                $current = TruemoneyVoucherRedemption::query()->lockForUpdate()->findOrFail($redemption->getKey());
                if ($current->status === TruemoneyRedemptionStatus::Credited && $current->deposit_id !== null) {
                    $deposit = Deposit::query()->findOrFail($current->deposit_id);
                    $transaction = $deposit->financialTransaction;
                    if ($transaction === null) {
                        throw FinancialException::withCode('truemoney_credit_proof_missing', 'The existing voucher credit requires reconciliation.');
                    }

                    return ['deposit' => $deposit, 'transaction' => $transaction, 'replayed' => true];
                }
                $currentPayload = is_array($current->provider_payload) ? $current->provider_payload : [];
                if ($current->status !== TruemoneyRedemptionStatus::RedeemedUnconfirmed
                    || $current->amount === null
                    || bccomp((string) $current->amount, $amount, 2) !== 0
                    || strtolower((string) ($currentPayload['risk_decision'] ?? '')) !== 'allow'
                    || strtoupper((string) ($current->currency ?? '')) !== Currency::THB->value) {
                    throw FinancialException::withCode('truemoney_state_conflict', 'Voucher redemption state requires manual reconciliation.');
                }

                $deposit = $this->depositService()->request(
                    wallet: $user->wallets()->where('currency', Currency::THB->value)->firstOrFail(),
                    amount: Money::of($amount, Currency::THB),
                    method: PaymentMethod::BankTransfer,
                    idempotencyKey: 'truemoney-'.$voucherHash,
                    options: [
                        'provider' => PaymentMethod::BankTransfer->value,
                        'provider_reference' => 'TMN-'.substr($voucherHash, 0, 16),
                        'metadata' => [
                            'thai_rails' => [
                                'provider_kind' => 'truemoney_voucher',
                                'voucher_hash' => $voucherHash,
                                'voucher_last4' => $voucherLast4,
                                'provider_status' => $providerResult['status'],
                                'evidence_hash' => hash('sha256', json_encode($providerResult['safe_payload'], JSON_THROW_ON_ERROR)),
                            ],
                        ],
                    ],
                );
                if ($deposit->status === DepositStatus::Pending) {
                    $deposit = $this->depositApprovalService()->approve($deposit, note: 'Provider-confirmed TrueMoney voucher redemption.')['deposit'];
                }
                if (! in_array($deposit->status, [DepositStatus::Approved, DepositStatus::Processing, DepositStatus::Confirmed], true)) {
                    throw FinancialException::withCode('truemoney_deposit_not_creditable', 'The canonical deposit request is not creditable.');
                }
                $completion = $this->depositCompletionService()->complete($deposit, options: [
                    'provider' => PaymentMethod::BankTransfer->value,
                    'provider_reference' => (string) $deposit->provider_reference,
                    'description' => 'TrueMoney voucher deposit '.$deposit->reference_number,
                    'metadata' => ['payment_rail' => 'truemoney_voucher', 'voucher_hash' => $voucherHash],
                ]);
                $current->forceFill([
                    'status' => TruemoneyRedemptionStatus::Credited,
                    'deposit_id' => $completion['deposit']->getKey(),
                    'failure_code' => null,
                ])->save();

                return ['deposit' => $completion['deposit'], 'transaction' => $completion['transaction'], 'replayed' => $completion['replayed']];
            }, 3);
        } catch (Throwable $exception) {
            $this->markRedeemedUnconfirmed($redemption, $voucherLast4, $providerResult['status'], $reconciliationPayload, 'credit_failed');
            throw FinancialException::withCode('truemoney_credit_unconfirmed', 'The voucher was redeemed but the wallet credit did not commit; manual reconciliation is required.', [], $exception);
        }

        $this->audit($redemption, AuditAction::Deposit, RiskLevel::Medium, 'TrueMoney voucher redeemed and deposited through the canonical bank-transfer settlement path.', [
            'voucher_last4' => $voucherLast4,
            'amount' => $amount,
            'deposit_id' => (int) $credited['deposit']->getKey(),
            'replayed' => $credited['replayed'],
            'canonical_provider' => PaymentMethod::BankTransfer->value,
            'rail' => 'truemoney_voucher',
        ]);
        Log::info('payment.truemoney_voucher_redeemed', PaymentLogSanitizer::sanitize([
            'user_id' => (int) $user->getKey(),
            'redemption_id' => (int) $redemption->getKey(),
            'voucher_last4' => $voucherLast4,
            'amount' => $amount,
            'provider' => 'truemoney',
        ]));

        return [
            'success' => true,
            'amount' => $amount,
            'deposit_reference' => (string) $credited['deposit']->reference_number,
            'redemption_id' => (int) $redemption->getKey(),
            'transaction_id' => (int) $credited['transaction']->getKey(),
        ];
    }

    private function reserveVoucher(User $user, string $voucherHash, string $voucherLast4, string $clientIp): TruemoneyVoucherRedemption
    {
        try {
            return DB::transaction(function () use ($user, $voucherHash, $voucherLast4, $clientIp): TruemoneyVoucherRedemption {
                if (TruemoneyVoucherRedemption::query()->where('voucher_hash', $voucherHash)->lockForUpdate()->exists()) {
                    throw FinancialException::withCode('truemoney_voucher_already_processed', 'This voucher has already been submitted and will not be redeemed again.');
                }
                $today = now()->toDateString();
                $daily = TruemoneyVoucherRedemption::query()
                    ->where('user_id', $user->getKey())
                    ->whereDate('created_at', $today)
                    ->get(['amount', 'status']);
                if ($daily->count() >= max(1, (int) config('truemoney.per_user_daily_count', 5))) {
                    throw FinancialException::withCode('truemoney_daily_count_limit', 'The daily voucher redemption count limit has been reached.');
                }
                $total = '0.00';
                foreach ($daily as $record) {
                    if ($record->amount !== null) {
                        $total = bcadd($total, (string) $record->amount, 2);
                    }
                }
                if (bccomp($total, (string) config('truemoney.per_user_daily_amount', '50000.00'), 2) >= 0) {
                    throw FinancialException::withCode('truemoney_daily_amount_limit', 'The daily voucher amount limit has been reached.');
                }

                return TruemoneyVoucherRedemption::query()->create([
                    'user_id' => (int) $user->getKey(),
                    'voucher_hash' => $voucherHash,
                    'voucher_last4' => $voucherLast4,
                    'currency' => 'THB',
                    'status' => TruemoneyRedemptionStatus::Pending,
                    'client_ip' => $clientIp !== '' ? $clientIp : null,
                    'provider_payload' => [],
                ]);
            }, 3);
        } catch (QueryException $exception) {
            if (TruemoneyVoucherRedemption::query()->where('voucher_hash', $voucherHash)->exists()) {
                throw FinancialException::withCode('truemoney_voucher_already_processed', 'This voucher has already been submitted and will not be redeemed again.', [], $exception);
            }
            throw $exception;
        }
    }

    private function assertProviderConfigured(string $allowedHost): void
    {
        $base = trim((string) config('truemoney.base_url', ''));
        $path = (string) config('truemoney.redeem_path', '');
        $parts = parse_url($base);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== $allowedHost
            || ! $this->isSafeHost($allowedHost)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || substr_count($path, '{voucher_code}') !== 1
            || preg_match('#\A/[A-Za-z0-9_./{}-]+\z#D', $path) !== 1
            || preg_match('#(?:\A|/)\.\.(?:/|\z)#', $path) === 1
            || (int) config('truemoney.timeout_seconds', 0) < 1
            || (int) config('truemoney.timeout_seconds', 0) > 60
            || (int) config('truemoney.per_user_daily_count', 0) < 1) {
            throw FinancialException::withCode('truemoney_not_configured', 'TrueMoney redemption endpoint is not configured for the verified HTTPS host.');
        }
        try {
            $minimum = Money::of((string) config('truemoney.min_amount', ''), Currency::THB);
            $maximum = Money::of((string) config('truemoney.max_amount', ''), Currency::THB);
            $dailyLimit = Money::of((string) config('truemoney.per_user_daily_amount', ''), Currency::THB);
        } catch (Throwable $exception) {
            throw FinancialException::withCode('truemoney_not_configured', 'TrueMoney amount limits are not configured safely.', [], $exception);
        }
        if (! $minimum->isPositive() || ! $maximum->isPositive() || ! $dailyLimit->isPositive()
            || bccomp($maximum->toString(), $minimum->toString(), 2) < 0
            || bccomp($dailyLimit->toString(), $minimum->toString(), 2) < 0) {
            throw FinancialException::withCode('truemoney_not_configured', 'TrueMoney amount limits are not configured safely.');
        }
    }

    private function isSafeHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.example')) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) === false
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function wouldExceedDailyAmount(User $user, TruemoneyVoucherRedemption $current, string $amount): bool
    {
        $redemptionDate = $current->created_at?->toDateString() ?? now()->toDateString();
        $rows = TruemoneyVoucherRedemption::query()
            ->where('user_id', $user->getKey())
            ->whereDate('created_at', $redemptionDate)
            ->where('id', '!=', $current->getKey())
            ->get(['amount']);
        $total = '0.00';
        foreach ($rows as $row) {
            if ($row->amount !== null) {
                $total = bcadd($total, (string) $row->amount, 2);
            }
        }

        return bccomp(bcadd($total, $amount, 2), (string) config('truemoney.per_user_daily_amount', '50000.00'), 2) > 0;
    }

    private function callProvider(string $voucherCode, string $merchantMobile, TruemoneyVoucherRedemption $redemption, string $voucherLast4): Response
    {
        $base = trim((string) config('truemoney.base_url', ''));
        $path = (string) config('truemoney.redeem_path', '');
        $host = strtolower(trim((string) config('truemoney.allowed_host', self::DEFAULT_VOUCHER_HOST)));
        $url = rtrim($base, '/').'/'.ltrim(str_replace('{voucher_code}', rawurlencode($voucherCode), $path), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== $host
            || ! $this->isSafeHost($host)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || substr_count($path, '{voucher_code}') !== 1
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw FinancialException::withCode('truemoney_not_configured', 'TrueMoney redemption endpoint is not configured for the verified host.');
        }

        try {
            return Http::acceptJson()
                ->asJson()
                ->timeout(max(1, (int) config('truemoney.timeout_seconds', 10)))
                ->retry(0)
                ->withOptions(['allow_redirects' => false])
                ->post($url, ['mobile' => $merchantMobile]);
        } catch (Throwable $exception) {
            $this->markRedeemedUnconfirmed($redemption, $voucherLast4, 'transport_unknown', [
                'transport' => 'unavailable',
            ], 'provider_outcome_unknown');
            Log::warning('payment.truemoney_voucher_outcome_unknown', PaymentLogSanitizer::sanitize([
                'redemption_id' => (int) $redemption->getKey(),
                'voucher_last4' => $voucherLast4,
                'exception_class' => $exception::class,
            ]));
            throw FinancialException::withCode('truemoney_outcome_unknown', 'The provider outcome is uncertain; the voucher will not be retried.');
        }
    }

    /** @return array{kind: string, amount: string|null, status: string, failure_code: string|null, safe_payload: array<string, mixed>} */
    private function parseProviderResponse(Response $response): array
    {
        if ($response->status() < 200 || ($response->status() >= 300 && $response->status() < 400)) {
            return ['kind' => 'unknown', 'amount' => null, 'status' => 'non_final_response', 'failure_code' => null, 'safe_payload' => ['http_status' => $response->status(), 'response' => 'non_final']];
        }
        if ($response->status() >= 500 || in_array($response->status(), [408, 429], true)) {
            return ['kind' => 'unknown', 'amount' => null, 'status' => 'provider_unavailable', 'failure_code' => null, 'safe_payload' => ['http_status' => $response->status()]];
        }
        try {
            $payload = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['kind' => 'unknown', 'amount' => null, 'status' => 'unreadable_response', 'failure_code' => null, 'safe_payload' => ['http_status' => $response->status(), 'response' => 'unreadable']];
        }
        if (! is_array($payload)) {
            return ['kind' => 'unknown', 'amount' => null, 'status' => 'unreadable_response', 'failure_code' => null, 'safe_payload' => ['http_status' => $response->status(), 'response' => 'unreadable']];
        }
        $status = strtoupper((string) data_get($payload, 'status.code', data_get($payload, 'status', data_get($payload, 'data.status', data_get($payload, 'code', '')))));
        $failure = strtoupper((string) data_get($payload, 'status.error_code', data_get($payload, 'error_code', data_get($payload, 'code', data_get($payload, 'status.code', '')))));
        $success = $response->successful() && in_array($status, ['SUCCESS', 'SUCCEEDED', '0000', '200'], true);
        $amount = $this->providerAmount($payload, $response->body());
        $currency = strtoupper((string) data_get($payload, 'data.currency', data_get($payload, 'data.voucher.currency', '')));
        if ($currency === '' && (data_get($payload, 'data.voucher.amount_baht') !== null || data_get($payload, 'data.amount_baht') !== null)) {
            $currency = 'THB';
        }
        $safe = [
            'http_status' => $response->status(),
            'provider_status' => $status !== '' ? substr($status, 0, 40) : 'unknown',
            'provider_error_code' => $failure !== '' ? substr($failure, 0, 40) : null,
            'amount' => $amount,
            'currency' => $currency !== '' ? $currency : null,
        ];
        if (! $success) {
            if (in_array($failure, ['VOUCHER_NOT_FOUND', 'INVALID_VOUCHER', 'ALREADY_REDEEMED', 'VOUCHER_EXPIRED', 'INSUFFICIENT_BALANCE'], true)) {
                return ['kind' => 'rejected', 'amount' => null, 'status' => $status !== '' ? $status : 'rejected', 'failure_code' => $failure, 'safe_payload' => array_filter($safe, static fn ($value): bool => $value !== null)];
            }

            return ['kind' => 'unknown', 'amount' => null, 'status' => $response->successful()
                ? ($status !== '' ? $status : 'unknown')
                : 'provider_http_error', 'failure_code' => null,
                'safe_payload' => array_filter($safe, static fn ($value): bool => $value !== null)];
        }
        if ($amount === null || $currency !== 'THB') {
            return ['kind' => 'unknown', 'amount' => null, 'status' => $status, 'failure_code' => null, 'safe_payload' => array_filter($safe, static fn ($value): bool => $value !== null)];
        }
        try {
            $money = Money::of($amount, Currency::THB);
            if (! $money->isPositive()
                || bccomp($amount, (string) config('truemoney.min_amount', '0.01'), 2) < 0
                || bccomp($amount, (string) config('truemoney.max_amount', '100000.00'), 2) > 0) {
                return ['kind' => 'unknown', 'amount' => null, 'status' => $status, 'failure_code' => null, 'safe_payload' => array_filter($safe, static fn ($value): bool => $value !== null)];
            }
        } catch (Throwable) {
            return ['kind' => 'unknown', 'amount' => null, 'status' => $status, 'failure_code' => null, 'safe_payload' => array_filter($safe, static fn ($value): bool => $value !== null)];
        }

        return ['kind' => 'redeemed', 'amount' => $money->toString(), 'status' => $status, 'failure_code' => null, 'safe_payload' => array_filter($safe, static fn ($value): bool => $value !== null)];
    }

    /** @param array<string, mixed> $payload */
    private function providerAmount(array $payload, string $body): ?string
    {
        foreach ([
            'data.voucher.amount_baht', 'data.voucher.redeemed_amount_baht', 'data.amount_baht',
            'data.voucher.amount', 'data.amount', 'voucher.amount_baht', 'amount_baht',
        ] as $key) {
            $value = data_get($payload, $key);
            if (is_string($value) || is_int($value)) {
                $value = trim((string) $value);
                if (preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/D', $value) === 1) {
                    return $value;
                }
            }
        }
        if (preg_match('/"(?:amount_baht|redeemed_amount_baht)"\s*:\s*([0-9]{1,12}(?:\.[0-9]{1,2})?)/', $body, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function markRedeemedUnconfirmed(TruemoneyVoucherRedemption $redemption, string $voucherLast4, string $providerStatus, array $safePayload, string $reason): void
    {
        $this->updateRedemption($redemption, [
            'status' => TruemoneyRedemptionStatus::RedeemedUnconfirmed,
            'provider_status' => substr($providerStatus, 0, 64),
            'failure_code' => $reason,
            'provider_payload' => $safePayload,
        ]);
        $this->audit($redemption, AuditAction::Reconcile, RiskLevel::High, 'TrueMoney redemption requires manual reconciliation; no automatic voucher retry is permitted.', [
            'voucher_last4' => $voucherLast4,
            'provider_status' => $providerStatus,
            'reason' => $reason,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function updateRedemption(TruemoneyVoucherRedemption $redemption, array $attributes): TruemoneyVoucherRedemption
    {
        return DB::transaction(function () use ($redemption, $attributes): TruemoneyVoucherRedemption {
            $current = TruemoneyVoucherRedemption::query()->lockForUpdate()->findOrFail($redemption->getKey());
            if ($current->status === TruemoneyRedemptionStatus::Credited && ($attributes['status'] ?? null) !== TruemoneyRedemptionStatus::Credited) {
                return $current;
            }
            $current->forceFill($attributes)->save();

            return $current;
        }, 3);
    }

    private function depositService(): DepositService
    {
        return $this->depositRequestService ?? app(DepositService::class);
    }

    private function depositApprovalService(): DepositApprovalService
    {
        return $this->depositApprovalService ?? app(DepositApprovalService::class);
    }

    private function depositCompletionService(): DepositCompletionService
    {
        return $this->depositCompletionService ?? app(DepositCompletionService::class);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(TruemoneyVoucherRedemption $redemption, AuditAction $action, RiskLevel $risk, string $description, array $metadata): void
    {
        ($this->auditLogService ?? app(AuditLogService::class))->log(
            userId: (int) $redemption->user_id,
            action: $action,
            riskLevel: $risk,
            auditable: $redemption,
            description: $description,
            metadata: PaymentLogSanitizer::sanitize($metadata),
        );
    }
}
