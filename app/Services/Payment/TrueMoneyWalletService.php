<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\Deposit\DepositProcessingService;
use App\Services\Fraud\FraudDetectionService;
use App\Services\Lock\DistributedLockService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class TrueMoneyWalletService
{
    private const TRUEMONEY_VOUCHER_REGEX = '/^https:\/\/gift\.truemoney\.com\/campaign\/\?v=([a-zA-Z0-9]{32,64})$/';

    public function __construct(
        private readonly DepositProcessingService $depositService,
        private readonly FraudDetectionService $fraudDetectionService,
        private readonly DistributedLockService $lockService
    ) {}

    /**
     * Redeem a TrueMoney Wallet Gift Voucher link into user balance ONLY after
     * upstream provider verification succeeds.
     *
     * FAIL-CLOSED:
     * Never credits a hardcoded/simulated amount when `services.truemoney` is
     * disabled or unconfigured.
     *
     * @return array{success: bool, amount: string, transaction_id: string, voucher_code: string}
     */
    public function redeemVoucher(string $userId, string $voucherUrl, string $clientIp): array
    {
        $voucherCode = $this->extractVoucherCode($voucherUrl);

        $enabled = (bool) config('services.truemoney.enabled', false);
        $apiBaseUrl = rtrim(trim((string) config('services.truemoney.api_url', '')), '/');
        $merchantMobile = trim((string) config('services.truemoney.mobile', ''));
        $apiKey = trim((string) config('services.truemoney.api_key', ''));

        if (! $enabled || $apiBaseUrl === '' || ! str_starts_with($apiBaseUrl, 'https://') || $merchantMobile === '') {
            Log::warning('TrueMoney voucher redemption refused: provider lane is not configured.', [
                'client_ip' => $clientIp,
            ]);

            throw new RuntimeException('TrueMoney voucher lane is not configured; redemption refused.');
        }

        $lockKey = "lock:payment:truemoney:voucher:{$voucherCode}";
        $lock = $this->lockService->acquireLock($lockKey, 30);
        if (! $lock) {
            throw new RuntimeException('Voucher is currently being processed by another request.');
        }

        try {
            $headers = ['Accept' => 'application/json'];
            if ($apiKey !== '') {
                $headers['Authorization'] = 'Bearer '.$apiKey;
            }

            $response = Http::withHeaders($headers)
                ->timeout((int) config('services.truemoney.timeout_seconds', 10))
                ->retry(2, 200, throw: false)
                ->post("{$apiBaseUrl}/vouchers/{$voucherCode}/redeem", [
                    'mobile' => $merchantMobile,
                ]);

            if (! $response->successful()) {
                Log::warning('TrueMoney upstream voucher verification failed.', [
                    'http_status' => $response->status(),
                ]);

                throw new RuntimeException('TrueMoney upstream voucher verification failed.');
            }

            $body = $response->json();
            if (! is_array($body)) {
                throw new RuntimeException('Invalid TrueMoney upstream verification response.');
            }

            $statusCode = strtoupper(trim((string) ($body['status']['code'] ?? $body['code'] ?? '')));
            if ($statusCode !== 'SUCCESS') {
                throw new RuntimeException('TrueMoney voucher was not redeemable.');
            }

            $rawAmount = $body['data']['voucher']['amount_baht']
                ?? $body['data']['my_ticket']['amount_baht']
                ?? $body['amount_baht']
                ?? null;

            if (! is_string($rawAmount) && ! is_int($rawAmount)) {
                throw new RuntimeException('TrueMoney upstream response omitted exact string/integer amount_baht.');
            }

            $amount = PaymentDecimalNormalizer::normalizePositive($rawAmount, 4, 'truemoney.amount_baht');

            $currency = strtoupper(trim((string) ($body['data']['voucher']['currency'] ?? $body['currency'] ?? 'THB')));
            if ($currency !== 'THB') {
                throw new RuntimeException("Unsupported TrueMoney voucher currency [{$currency}].");
            }

            $providerTxId = trim((string) ($body['data']['voucher']['voucher_id'] ?? $voucherCode));
            $gatewayRef = 'TMN-'.$providerTxId;

            $this->fraudDetectionService->evaluateTransactionRisk([
                'event_id' => (string) Str::uuid(),
                'event_type' => 'DEPOSIT',
                'user_id' => $userId,
                'amount' => $amount,
                'currency' => 'THB',
                'client_ip' => $clientIp,
                'reference_id' => $gatewayRef,
            ]);

            $deposit = $this->depositService->processConfirmedDeposit(
                userId: $userId,
                amount: $amount,
                gatewayProvider: 'TRUEMONEY_WALLET',
                gatewayReferenceId: $gatewayRef,
                currency: 'THB',
                feeAmount: '0.0000',
                rawPayload: PaymentLogSanitizer::sanitize([
                    'voucher_hash' => hash('sha256', $voucherCode),
                    'provider_voucher_id' => $providerTxId,
                ]),
            );

            return [
                'success' => true,
                'amount' => $amount,
                'transaction_id' => (string) $deposit->id,
                'voucher_code' => $voucherCode,
            ];
        } finally {
            $this->lockService->releaseLock($lockKey, $lock);
        }
    }

    /**
     * Extract and validate 32-64 alphanumeric campaign code from TrueMoney gift URL.
     */
    public function extractVoucherCode(string $voucherUrl): string
    {
        $trimmed = trim($voucherUrl);

        if (preg_match(self::TRUEMONEY_VOUCHER_REGEX, $trimmed, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid TrueMoney gift voucher URL format.');
        }

        return $matches[1];
    }
}
