<?php

declare(strict_types=1);

namespace App\Services\Payment\Drivers;

use App\DTOs\Payment\GatewayDepositResponse;
use App\DTOs\Payment\GatewayWithdrawalResponse;
use App\DTOs\Payment\WebhookPayload;
use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\WebhookEventType;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Services\Payment\DepositCheckoutService;
use App\Services\Payment\FinancialProviderReferenceAliasService;
use App\Services\Payment\PaymentDecimalNormalizer;
use App\Support\LosslessJson;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * bKash tokenized checkout adapter. Its execute/query facts must match the
 * original locally owned checkout; a callback body is never settlement proof.
 *
 * The existing HMAC callback adapter is a local compatibility convention, not
 * evidence of bKash's published IPN authentication protocol. The merchant's
 * actual delivery/authentication contract and production credentials must be
 * verified before this integration is advertised as production-ready. Payouts
 * remain manual; no B2C transfer is implemented here.
 */
class BkashGateway extends AbstractPaymentGateway
{
    public function name(): string
    {
        return 'bkash';
    }

    public function label(): string
    {
        return 'bKash';
    }

    public function status(): GatewayIntegrationStatus
    {
        // The merchant's actual callback authentication contract is not yet
        // implemented/verified. Credentials alone cannot make this complete.
        return GatewayIntegrationStatus::PartiallyImplemented;
    }

    public function supportsCurrency(Currency $currency): bool
    {
        return $currency === Currency::BDT;
    }

    public function initiateDeposit(Deposit $deposit, array $options = []): GatewayDepositResponse
    {
        if ($deposit->currency !== Currency::BDT) {
            throw FinancialException::withCode('payment_gateway_currency_unsupported', trans('payment_ops.errors.payment_gateway_currency_unsupported'), ['gateway' => $this->name(), 'currency' => $deposit->currency->value]);
        }

        $appKey = $this->appKey();
        $appSecret = $this->appSecret();

        if (empty($appKey) || empty($appSecret)) {
            return GatewayDepositResponse::failed('bKash API credentials are not configured.');
        }

        $token = $this->grantToken();

        if ($token === null) {
            return GatewayDepositResponse::failed('Failed to obtain bKash authorization token.');
        }

        $payload = [
            'mode' => '0011',
            'payerReference' => $deposit->reference_number,
            'callbackURL' => $this->successUrl().'?reference='.$deposit->reference_number,
            'amount' => (string) $deposit->amount,
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => $deposit->reference_number,
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => $token,
                'X-APP-Key' => $appKey,
            ])->post($this->baseUrl().'/tokenized/checkout/create', $payload);

            if ($response->successful()) {
                $data = $response->json();
                $paymentId = (string) ($data['paymentID'] ?? '');
                $bkashUrl = (string) ($data['bkashURL'] ?? '');

                if ($paymentId !== '' && $bkashUrl !== '') {
                    return GatewayDepositResponse::redirect(
                        url: $bkashUrl,
                        providerReference: $paymentId,
                        rawResponse: $data,
                        metadata: [
                            'paymentID' => $paymentId,
                            'statusCode' => $data['statusCode'] ?? null,
                        ],
                    );
                }
            }

            $errorData = $response->json();
            $providerCode = is_array($errorData) ? ($errorData['statusCode'] ?? null) : null;

            Log::warning('bKash checkout request was refused', [
                'http_status' => $response->status(),
                'provider_error_code' => is_string($providerCode) ? substr($providerCode, 0, 64) : null,
            ]);

            return GatewayDepositResponse::failed(
                'bKash payment creation failed.',
                [],
                ['gateway' => 'bkash', 'http_status' => $response->status()],
            );
        } catch (\Throwable $e) {
            Log::error('bKash deposit initiation failed', [
                'exception_class' => $e::class,
                'exception_code' => $e->getCode(),
            ]);

            return GatewayDepositResponse::failed('bKash could not be reached to create checkout.');
        }
    }

    public function initiateWithdrawal(Withdrawal $withdrawal, array $options = []): GatewayWithdrawalResponse
    {
        return GatewayWithdrawalResponse::pending(
            providerReference: null,
            rawResponse: [],
            metadata: [
                'manual_disbursement_required' => true,
                'requires_b2c_disbursement' => true,
                'reconciliation_required' => true,
            ],
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        // This is a legacy compatibility check, not bKash SNS verification.
        // Never reuse the merchant API credential as a webhook secret.
        $secret = $this->webhookSecret();

        if (empty($secret)) {
            Log::warning('bKash compatibility callback rejected: webhook_secret is not configured.');

            return false;
        }

        $provided = (string) ($request->header('X-Bkash-Signature') ?: $request->header('X-Signature', ''));

        if ($provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $provided);
    }

    public function parseWebhook(Request $request): WebhookPayload
    {
        try {
            $body = $request->getContent();
            $exact = LosslessJson::decode($body);
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($exact) || ! is_array($data) || ! str_starts_with(ltrim($body), '{')) {
                throw new \JsonException('bKash callback JSON must be an object.');
            }
        } catch (\JsonException $exception) {
            // The public bKash ingress remains closed. Even in the isolated
            // prototype, duplicate identities or statuses must not trigger a
            // credential-bearing provider query.
            throw FinancialException::withCode('bkash_payment_verification_failed', trans('payment_ops.errors.bkash_payment_verification_failed'), ['reason' => 'invalid_callback_json'], $exception);
        }

        $paymentValue = $data['paymentID'] ?? $data['payment_id'] ?? null;
        $transactionValue = $data['trxID'] ?? $data['transaction_id'] ?? null;
        $invoiceValue = $data['merchantInvoiceNumber'] ?? $data['invoice_number'] ?? $data['reference'] ?? null;
        $paymentId = is_string($paymentValue) ? $paymentValue : '';
        $trxId = is_string($transactionValue) ? $transactionValue : '';
        $invoice = is_string($invoiceValue) ? $invoiceValue : '';
        $amountRaw = $exact['amount'] ?? null;
        try {
            $amount = PaymentDecimalNormalizer::normalizeInboundWebhookPositive($amountRaw, 2);
        } catch (\InvalidArgumentException) {
            $amount = null;
        }
        $status = strtoupper((string) ($data['transactionStatus'] ?? $data['status'] ?? ''));
        if (in_array($status, ['COMPLETED', 'SUCCESS', 'SUCCESSFUL'], true)) {
            if ($paymentId === '' || $invoice === '') {
                throw FinancialException::withCode(
                    'bkash_payment_verification_failed',
                    trans('payment_ops.errors.bkash_payment_verification_failed'),
                    ['reason' => 'missing_callback_identity'],
                );
            }
            // A valid-looking callback must not execute any provider payment
            // until it is tied to this application's original checkout. Release
            // the SQL locks before network I/O; the binding service rechecks
            // the same proof under lock before wallet credit.
            if ($request->attributes->get('webhook.signature_verified') !== true) {
                throw FinancialException::withCode(
                    'bkash_payment_verification_failed',
                    trans('payment_ops.errors.bkash_payment_verification_failed'),
                    ['reason' => 'callback_authentication_missing'],
                );
            }
            $expectedAmount = $this->preflightOriginalCheckout($paymentId, $invoice);
            $verified = $this->executeAndQueryPayment($paymentId, $invoice, $expectedAmount);
            $data = array_merge($data, $verified);
            $trxId = $verified['trxID'];
            // The provider query, not the callback, is authoritative.
            $amount = $verified['amount'];
            $currencyRaw = $verified['currency'];
            $status = strtoupper((string) ($verified['transactionStatus'] ?? $verified['status'] ?? ''));
        } else {
            $currencyRaw = $data['currency'] ?? null;
        }

        $currency = is_string($currencyRaw) ? Currency::tryFrom(strtoupper($currencyRaw)) : null;

        $eventType = match ($status) {
            'COMPLETED', 'SUCCESS', 'SUCCESSFUL' => WebhookEventType::DepositSuccess,
            'CANCELLED', 'CANCELED' => WebhookEventType::DepositCancelled,
            'EXPIRED' => WebhookEventType::DepositExpired,
            'FAILED', 'DECLINED' => WebhookEventType::DepositFailed,
            default => WebhookEventType::Unknown,
        };

        $eventId = $paymentId !== '' && $trxId !== ''
            ? FinancialProviderReferenceAliasService::bkashEventId($paymentId, $trxId)
            : 'bkash_evt_'.bin2hex(random_bytes(8));

        return new WebhookPayload(
            gateway: $this->name(),
            eventId: $eventId,
            eventType: $eventType,
            // paymentID owns the original checkout; trxID is a separately
            // reserved transaction alias, not a replacement for that ID.
            providerReference: $paymentId !== '' ? $paymentId : null,
            internalReference: ! empty($invoice) ? $invoice : null,
            amount: $amount,
            currency: $currency,
            isSuccess: $eventType->isSuccess(),
            failureReason: $eventType->isFailure() ? ($data['statusMessage'] ?? 'bKash payment failed.') : null,
            rawData: $data,
            metadata: [
                'paymentID' => $paymentId,
                'trxID' => $trxId,
            ],
        );
    }

    /** Prove the original local obligation before any credential-bearing request. */
    private function preflightOriginalCheckout(string $paymentId, string $invoice): string
    {
        $checkout = app(DepositCheckoutService::class);
        $checkout->assertTopLevel();
        if (strlen($paymentId) > 255 || strlen($invoice) > 255
            || trim($paymentId) !== $paymentId || trim($invoice) !== $invoice
            || preg_match('/[\x00-\x1f\x7f]/', $paymentId.$invoice)) {
            throw FinancialException::withCode('bkash_payment_verification_failed', trans('payment_ops.errors.bkash_payment_verification_failed'), ['reason' => 'invalid_callback_identity']);
        }

        return DB::transaction(function () use ($checkout, $paymentId, $invoice): string {
            $candidate = Deposit::query()->where('reference_number', $invoice)->first();
            if (! $candidate) {
                throw FinancialException::withCode('bkash_payment_verification_failed', trans('payment_ops.errors.bkash_payment_verification_failed'), ['reason' => 'original_checkout_not_found']);
            }
            [$row, , , , $originalReference] = $checkout->lockedBkashSettlement($candidate);
            if (! hash_equals($originalReference, $paymentId)
                || ! hash_equals((string) $row->reference_number, $invoice)
                || ! in_array($row->status, [DepositStatus::Pending, DepositStatus::Approved, DepositStatus::Processing, DepositStatus::Confirmed], true)) {
                throw FinancialException::withCode('bkash_payment_verification_failed', trans('payment_ops.errors.bkash_payment_verification_failed'), ['reason' => 'original_checkout_mismatch']);
            }

            return (string) $row->amount;
        });
    }

    /**
     * Callback bodies are not settlement. Each status response must match the
     * original paymentID, invoice, amount and currency before an execute is
     * considered, and a completed query must also carry its own trxID.
     *
     * @return array<string, mixed>
     */
    private function executeAndQueryPayment(string $paymentId, string $expectedInvoice, string $expectedAmount): array
    {
        $token = $this->grantToken();
        $appKey = $this->appKey();
        if ($token === null || $appKey === null) {
            throw FinancialException::withCode(
                'bkash_payment_verification_failed',
                trans('payment_ops.errors.bkash_payment_verification_failed'),
                ['reason' => 'credentials_unavailable'],
            );
        }

        $headers = ['Authorization' => $token, 'X-APP-Key' => $appKey];
        $query = fn () => Http::withHeaders($headers)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->retry(0)
            ->withOptions(['allow_redirects' => false])
            ->post($this->baseUrl().'/tokenized/checkout/payment/status', ['paymentID' => $paymentId]);

        try {
            $statusResponse = $query();
            if (! $statusResponse->successful()) {
                throw new \RuntimeException('bKash status query was not successful.');
            }
            $statusData = $this->statusResponseFacts($statusResponse);
            $statusData = $this->validateProviderFacts($statusData, $paymentId, $expectedInvoice, $expectedAmount);
            if (! $this->isCompletedProviderStatus($statusData)) {
                // Absence or disagreement of customer-verification facts is
                // not proof of authorization. This deliberately fails closed
                // until the merchant's actual provider contract is verified.
                $verificationFacts = [];
                foreach (['verificationStatus', 'userVerificationStatus'] as $key) {
                    if (array_key_exists($key, $statusData)) {
                        $verificationFacts[] = $statusData[$key];
                    }
                }
                if (! in_array($statusData['transactionStatus'], ['INITIATED', 'AUTHORIZED'], true)
                    || $verificationFacts === []) {
                    throw new \RuntimeException('bKash checkout is not eligible for execution.');
                }
                foreach ($verificationFacts as $verification) {
                    if (! is_string($verification) || ! in_array(strtoupper($verification), ['COMPLETE', 'COMPLETED'], true)) {
                        throw new \RuntimeException('bKash customer verification is not proved.');
                    }
                }
                $executeResponse = Http::withHeaders($headers)
                    ->acceptJson()
                    ->asJson()
                    ->timeout(15)
                    ->retry(0)
                    ->withOptions(['allow_redirects' => false])
                    ->post($this->baseUrl().'/tokenized/checkout/execute', ['paymentID' => $paymentId]);
                // An execute response can be lost or report that the checkout
                // was already executed. The subsequent query, never this response,
                // is the sole authority for whether the deposit can be credited.
                if (! $executeResponse->successful()) {
                    Log::notice('bKash execute response requires authoritative payment query', [
                        'http_status' => $executeResponse->status(),
                    ]);
                }
                $statusResponse = $query();
                if (! $statusResponse->successful()) {
                    throw new \RuntimeException('bKash final status query was not successful.');
                }
                $statusData = $this->statusResponseFacts($statusResponse);
                $statusData = $this->validateProviderFacts($statusData, $paymentId, $expectedInvoice, $expectedAmount);
            }
        } catch (\Throwable $exception) {
            Log::warning('bKash payment verification did not complete', [
                'payment_id_hash' => hash('sha256', $paymentId),
                'exception_class' => $exception::class,
            ]);
            throw FinancialException::withCode(
                'bkash_payment_verification_failed',
                trans('payment_ops.errors.bkash_payment_verification_failed'),
                ['reason' => 'provider_query_unavailable'],
                $exception,
            );
        }

        $transactionId = $statusData['trxID'] ?? null;
        if (! $this->isCompletedProviderStatus($statusData) || ! is_string($transactionId)
            || $transactionId === '' || strlen($transactionId) > 245 || trim($transactionId) !== $transactionId
            || preg_match('/[\x00-\x1f\x7f]/', $transactionId)) {
            throw FinancialException::withCode(
                'bkash_payment_verification_failed',
                trans('payment_ops.errors.bkash_payment_verification_failed'),
                ['reason' => 'provider_facts_mismatch'],
            );
        }

        return $statusData;
    }

    /**
     * Preserve the authoritative status query's exact amount lexeme and reject
     * duplicate JSON keys before looking at any settlement facts. The ordinary
     * decode is kept for all other type checks; it must not supply money.
     *
     * @return array<string, mixed>
     */
    private function statusResponseFacts(\Illuminate\Http\Client\Response $response): array
    {
        $body = $response->body();
        $exact = LosslessJson::decode($body);
        $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($exact) || ! is_array($data) || ! str_starts_with(ltrim($body), '{')) {
            throw new \RuntimeException('bKash status response was malformed.');
        }
        $data['amount'] = $exact['amount'] ?? null;

        return $data;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validateProviderFacts(array $data, string $paymentId, string $expectedInvoice, string $expectedAmount): array
    {
        // Query Payment returns merchantInvoice; create/execute return
        // merchantInvoiceNumber. If both are present they must agree.
        $invoice = $data['merchantInvoice'] ?? $data['merchantInvoiceNumber'] ?? null;
        $otherInvoice = $data['merchantInvoiceNumber'] ?? $invoice;
        $providerPaymentId = $data['paymentID'] ?? null;
        $currency = $data['currency'] ?? null;
        $status = $data['transactionStatus'] ?? null;
        try {
            $amount = PaymentDecimalNormalizer::normalizeInboundWebhookPositive($data['amount'] ?? null, 2);
        } catch (\InvalidArgumentException $exception) {
            throw FinancialException::withCode('bkash_payment_verification_failed', trans('payment_ops.errors.bkash_payment_verification_failed'), ['reason' => 'invalid_provider_amount'], $exception);
        }
        if (($data['statusCode'] ?? null) !== '0000' || ! is_string($providerPaymentId) || ! hash_equals($paymentId, $providerPaymentId)
            || ! is_string($invoice) || ! is_string($otherInvoice) || ! hash_equals($expectedInvoice, $invoice)
            || ! hash_equals($invoice, $otherInvoice) || ! is_string($currency) || strtoupper($currency) !== Currency::BDT->value
            || ! is_string($status) || bccomp($amount, $expectedAmount, 2) !== 0) {
            throw FinancialException::withCode('bkash_payment_verification_failed', trans('payment_ops.errors.bkash_payment_verification_failed'), ['reason' => 'provider_facts_mismatch']);
        }

        $data['merchantInvoiceNumber'] = $invoice;
        $data['amount'] = $amount;
        $data['currency'] = Currency::BDT->value;
        $data['transactionStatus'] = strtoupper($status);

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function isCompletedProviderStatus(array $data): bool
    {
        return ($data['statusCode'] ?? null) === '0000'
            && ($data['transactionStatus'] ?? null) === 'COMPLETED';
    }

    private function grantToken(): ?string
    {
        $appKey = $this->appKey();
        $appSecret = $this->appSecret();
        $username = $this->config->get('payment.gateways.bkash.username');
        $password = $this->config->get('payment.gateways.bkash.password');

        if (empty($appKey) || empty($appSecret) || empty($username) || empty($password)) {
            return null;
        }

        try {
            $response = Http::withHeaders([
                'username' => (string) $username,
                'password' => (string) $password,
            ])->acceptJson()->asJson()->timeout(15)->retry(0)->withOptions(['allow_redirects' => false])->post($this->baseUrl().'/tokenized/checkout/token/grant', [
                'app_key' => $appKey,
                'app_secret' => $appSecret,
            ]);

            if ($response->successful()) {
                return $response->json('id_token');
            }
        } catch (\Throwable $e) {
            Log::error('bKash token grant failed', [
                'exception_class' => $e::class,
                'exception_code' => $e->getCode(),
            ]);
        }

        return null;
    }

    private function appKey(): ?string
    {
        $key = $this->config->get('payment.gateways.bkash.app_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    private function appSecret(): ?string
    {
        $secret = $this->config->get('payment.gateways.bkash.app_secret');

        return is_string($secret) && trim($secret) !== '' ? trim($secret) : null;
    }

    private function baseUrl(): string
    {
        $custom = $this->config->get('payment.gateways.bkash.base_url');

        if (is_string($custom) && trim($custom) !== '') {
            return rtrim(trim($custom), '/');
        }

        $sandbox = (bool) $this->config->get('payment.gateways.bkash.sandbox', true);

        return $sandbox
            ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta'
            : 'https://tokenized.pay.bka.sh/v1.2.0-beta';
    }
}
