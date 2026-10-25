<?php

declare(strict_types=1);

namespace App\Services\Payout\ThaiBank;

use App\DTOs\Payment\PayoutInstruction;
use App\DTOs\Payment\PayoutOutcome;
use App\Exceptions\FinancialException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/** KBank K-API client-credentials and corporate-transfer adapter; never resends a transfer. */
final class KbankPayoutAdapter implements ThaiBankPayoutAdapterInterface
{
    public function bank(): string
    {
        return 'kbank';
    }

    public function configured(): bool
    {
        $config = (array) config('thai_payout.banks.kbank', []);

        return $this->safeBaseUrl((string) ($config['base_url'] ?? ''))
            && trim((string) ($config['client_id'] ?? '')) !== ''
            && trim((string) ($config['client_secret'] ?? '')) !== ''
            && (bool) ($config['contract_confirmed'] ?? false)
            && trim((string) ($config['webhook_secret'] ?? '')) !== ''
            && trim((string) ($config['source_account'] ?? '')) !== ''
            && $this->safePath((string) ($config['token_path'] ?? ''))
            && $this->safePath((string) ($config['transfer_path'] ?? ''))
            && $this->safePath((string) ($config['status_path'] ?? ''))
            && (int) ($config['timeout_seconds'] ?? 0) >= 1
            && (int) ($config['timeout_seconds'] ?? 0) <= 60;
    }

    public function initiateTransfer(PayoutInstruction $instruction): PayoutOutcome
    {
        if (! $this->laneEnabled()) {
            return PayoutOutcome::uncertain(rawSanitised: ['adapter_disabled' => true]);
        }
        try {
            $config = (array) config('thai_payout.banks.kbank', []);
            $body = $this->transferBody($instruction);
            $token = $this->accessToken();
            $response = Http::withHeaders($this->requestHeaders($instruction->reference, $token))
                ->acceptJson()
                ->asJson()
                ->timeout(max(1, (int) ($config['timeout_seconds'] ?? 15)))
                ->retry(0)
                ->withOptions(['allow_redirects' => false])
                ->post($this->url('transfer_path'), $body);

            return $this->mapResponse($response);
        } catch (Throwable) {
            return PayoutOutcome::uncertain(rawSanitised: ['transport' => 'unavailable']);
        }
    }

    public function queryStatus(string $reference): PayoutOutcome
    {
        if (! $this->laneEnabled()) {
            return PayoutOutcome::uncertain(rawSanitised: ['adapter_disabled' => true, 'status_query' => true]);
        }
        try {
            $config = (array) config('thai_payout.banks.kbank', []);
            $token = $this->accessToken();
            $path = str_replace('{reference}', rawurlencode($reference), (string) ($config['status_path'] ?? ''));
            $response = Http::withHeaders($this->requestHeaders($reference, $token))
                ->acceptJson()
                ->asJson()
                ->timeout(max(1, (int) ($config['timeout_seconds'] ?? 15)))
                ->retry(0)
                ->withOptions(['allow_redirects' => false])
                ->post($this->url('status_path', $path), [
                    'transactionReference' => $reference,
                    'debitAccountNumber' => (string) ($config['source_account'] ?? ''),
                ]);

            if ($response->status() < 200 || $response->status() >= 300) {
                return PayoutOutcome::uncertain(rawSanitised: ['http_status' => $response->status(), 'status_query' => true]);
            }

            return $this->mapResponse($response);
        } catch (Throwable) {
            return PayoutOutcome::uncertain(rawSanitised: ['transport' => 'unavailable']);
        }
    }

    public function verifyCallback(string $rawBody, array $headers): bool
    {
        $config = (array) config('thai_payout.banks.kbank', []);
        $secret = trim((string) ($config['webhook_secret'] ?? ''));
        $signature = $this->header($headers, (string) ($config['callback_signature_header'] ?? 'X-Signature'));
        $timestamp = $this->header($headers, (string) ($config['callback_timestamp_header'] ?? 'X-Timestamp'));
        if ($secret === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }
        $eventTime = (int) $timestamp;
        $tolerance = max(1, (int) config('thai_payout.callback_tolerance_seconds', 300));
        if (abs(time() - $eventTime) > $tolerance) {
            return false;
        }
        $provided = preg_replace('/\Asha256=/i', '', trim($signature)) ?? '';
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        return $provided !== '' && hash_equals($expected, $provided);
    }

    public function parseCallback(string $rawBody): array
    {
        try {
            $payload = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw FinancialException::withCode('payout_webhook_payload_invalid', 'The KBank payout callback payload is invalid.', [], $exception);
        }
        if (! is_array($payload)) {
            throw FinancialException::withCode('payout_webhook_payload_invalid', 'The KBank payout callback payload is invalid.');
        }
        $reference = data_get($payload, 'reference', data_get($payload, 'transactionReference',
            data_get($payload, 'clientReference', data_get($payload, 'requestId', data_get($payload, 'data.reference',
                data_get($payload, 'data.transactionReference', data_get($payload, 'data.clientReference', data_get($payload, 'data.requestId', ''))))))));
        $state = data_get($payload, 'state', data_get($payload, 'status', data_get($payload, 'transactionStatus',
            data_get($payload, 'data.state', data_get($payload, 'data.status', data_get($payload, 'data.transactionStatus', ''))))));
        $providerReference = data_get($payload, 'providerReference', data_get($payload, 'transactionId',
            data_get($payload, 'data.providerReference', data_get($payload, 'data.transactionId'))));
        $currency = data_get($payload, 'currency', data_get($payload, 'data.currency'));
        if (! is_string($reference) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,63}\z/D', $reference) !== 1
            || ! is_string($state) || $state === '' || strlen($state) > 64 || preg_match('/[\x00-\x1f\x7f]/', $state)
            || ($providerReference !== null && (! is_string($providerReference)
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:/-]{0,190}\z/D', $providerReference) !== 1))
            || ($currency !== null && (! is_string($currency) || strlen($currency) > 8 || preg_match('/[\x00-\x1f\x7f]/', $currency)))) {
            throw FinancialException::withCode('payout_webhook_payload_invalid', 'The KBank payout callback payload is incomplete.');
        }

        return [
            'reference' => $reference,
            'state' => $state,
            'providerReference' => is_string($providerReference) && $providerReference !== '' ? $providerReference : null,
            'amount' => $this->exactAmount($payload, $rawBody),
            'currency' => is_string($currency) ? strtoupper($currency) : null,
        ];
    }

    private function laneEnabled(): bool
    {
        return (bool) config('thai_payout.enabled', false)
            && (bool) config('thai_payout.banks.kbank.enabled', false);
    }

    private function accessToken(): string
    {
        if (! $this->configured()) {
            throw FinancialException::withCode('thai_payout_adapter_not_configured', 'KBank payout credentials are not configured.');
        }
        $config = (array) config('thai_payout.banks.kbank', []);
        $clientId = trim((string) $config['client_id']);
        $cacheKey = self::tokenCacheKey($clientId);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::acceptJson()
            ->asForm()
            ->timeout(max(1, (int) ($config['timeout_seconds'] ?? 15)))
            ->retry(0)
            ->withOptions(['allow_redirects' => false])
            ->withBasicAuth($clientId, (string) $config['client_secret'])
            ->post($this->url('token_path'), ['grant_type' => 'client_credentials']);
        if (! $response->successful()) {
            throw FinancialException::withCode('thai_payout_token_unavailable', 'KBank authentication did not succeed.');
        }
        $payload = $response->json();
        $token = is_array($payload) ? ($payload['access_token'] ?? data_get($payload, 'data.access_token')) : null;
        $expires = is_array($payload) ? ($payload['expires_in'] ?? data_get($payload, 'data.expires_in')) : null;
        if (! is_string($token) || trim($token) === '' || (! is_int($expires) && ! (is_string($expires) && ctype_digit($expires)))) {
            throw FinancialException::withCode('thai_payout_token_unavailable', 'KBank returned an unreadable authentication response.');
        }
        Cache::put($cacheKey, $token, now()->addSeconds(max(1, (int) $expires - 30)));

        return $token;
    }

    public static function tokenCacheKey(string $clientId): string
    {
        return 'thai-payout:kbank:token:'.hash('sha256', $clientId);
    }

    /** @return array<string, string> */
    private function requestHeaders(string $reference, string $token): array
    {
        $config = (array) config('thai_payout.banks.kbank', []);
        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            (string) ($config['client_id_header'] ?? 'x-ibm-client-id') => (string) $config['client_id'],
            (string) ($config['request_id_header'] ?? 'X-Request-ID') => $reference,
            (string) ($config['idempotency_header'] ?? 'Idempotency-Key') => $reference,
        ];
    }

    /** @return array<string, mixed> */
    private function transferBody(PayoutInstruction $instruction): array
    {
        $config = (array) config('thai_payout.banks.kbank', []);
        if ($instruction->destinationType !== 'bank_account' || $instruction->bankCode === null) {
            throw FinancialException::withCode('invalid_payout_destination', 'KBank Corporate Fund Transfer requires a bank-account destination.');
        }

        return [
            'debitAccountNumber' => (string) $config['source_account'],
            'creditAccountNumber' => $instruction->accountNumber,
            'creditAccountBankCode' => $instruction->bankCode,
            'beneficiaryName' => $instruction->accountName,
            'transactionAmount' => $instruction->amount,
            'transactionReference' => $instruction->reference,
            'paymentDetails' => $instruction->memo,
        ];
    }

    private function mapResponse(Response $response): PayoutOutcome
    {
        if ($response->status() < 200 || ($response->status() >= 300 && $response->status() < 400)) {
            return PayoutOutcome::uncertain(rawSanitised: ['http_status' => $response->status()]);
        }
        if ($response->status() >= 500 || in_array($response->status(), [408, 425, 429], true)) {
            return PayoutOutcome::uncertain(rawSanitised: ['http_status' => $response->status()]);
        }
        if ($response->status() >= 400) {
            return PayoutOutcome::rejected(message: 'KBank definitively rejected the payout request.', rawSanitised: ['http_status' => $response->status()]);
        }
        try {
            $payload = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return PayoutOutcome::uncertain(rawSanitised: ['http_status' => $response->status(), 'response' => 'unreadable']);
        }
        if (! is_array($payload)) {
            return PayoutOutcome::uncertain(rawSanitised: ['http_status' => $response->status(), 'response' => 'unreadable']);
        }
        $stateText = strtoupper((string) data_get($payload, 'data.status', data_get($payload, 'status', data_get($payload, 'data.state', ''))));
        $providerReference = data_get($payload, 'data.transactionId', data_get($payload, 'data.providerReference', data_get($payload, 'transactionId')));
        $providerReference = is_string($providerReference)
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:/-]{0,190}\z/D', $providerReference) === 1
            ? $providerReference : null;
        $amount = $this->exactAmount($payload, $response->body());
        $currency = data_get($payload, 'data.currency', $payload['currency'] ?? null);
        $currency = is_string($currency) && strlen($currency) <= 8 && preg_match('/[\x00-\x1f\x7f]/', $currency) !== 1
            ? strtoupper($currency) : null;
        $safe = array_filter([
            'http_status' => $response->status(),
            'provider_status' => $stateText !== '' ? substr($stateText, 0, 40) : null,
            'provider_reference' => $providerReference,
            'amount' => $amount,
            'currency' => is_string($currency) ? strtoupper($currency) : null,
        ], static fn (mixed $value): bool => $value !== null);

        return match ($stateText) {
            'ACCEPTED', 'PENDING', 'PROCESSING', 'QUEUED' => $providerReference !== null
                ? PayoutOutcome::accepted($providerReference, $safe)
                : PayoutOutcome::uncertain(null, $safe),
            'SETTLED', 'COMPLETED', 'SUCCESS', 'SUCCEEDED', 'SUCCESSFUL' => $providerReference !== null
                ? PayoutOutcome::settled($providerReference, $safe)
                : PayoutOutcome::uncertain(null, $safe),
            'REJECTED', 'FAILED', 'DECLINED' => PayoutOutcome::rejected($providerReference, 'KBank confirmed that the payout was rejected.', $safe),
            default => PayoutOutcome::uncertain($providerReference, $safe),
        };
    }

    /** @param array<string, mixed> $payload */
    private function exactAmount(array $payload, string $body): ?string
    {
        $value = data_get($payload, 'data.amount', $payload['amount'] ?? null);
        $amount = is_string($value) || is_int($value) ? (string) $value : null;
        if ($amount === null && preg_match('/"amount"\s*:\s*(-?[0-9]+(?:\.[0-9]+)?)/', $body, $matches) === 1) {
            $amount = $matches[1];
        }

        return is_string($amount) && strlen($amount) <= 28
            && preg_match('/\A-?[0-9]{1,24}(?:\.[0-9]{1,2})?\z/D', $amount) === 1
            ? $amount : null;
    }

    private function url(string $key, ?string $resolvedPath = null): string
    {
        $config = (array) config('thai_payout.banks.kbank', []);
        $path = $resolvedPath ?? (string) ($config[$key] ?? '');

        return rtrim((string) ($config['base_url'] ?? ''), '/').'/'.ltrim($path, '/');
    }

    private function safePath(string $path): bool
    {
        return preg_match('#\\A/[A-Za-z0-9_./{}-]+\\z#D', $path) === 1
            && preg_match('#(?:\\A|/)\\.\\.(?:/|\\z)#', $path) !== 1;
    }

    private function safeBaseUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return false;
        }
        $host = strtolower($parts['host']);
        if (in_array($host, ['localhost', 'localhost.localdomain'], true) || str_ends_with($host, '.example')) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) === false
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_array($value) ? trim((string) ($value[0] ?? '')) : trim((string) $value);
            }
        }

        return '';
    }
}
