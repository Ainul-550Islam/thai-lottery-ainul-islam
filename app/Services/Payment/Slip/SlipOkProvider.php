<?php

declare(strict_types=1);

namespace App\Services\Payment\Slip;

use App\DTOs\Payment\SlipVerificationResult;
use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Services\Finance\Money;
use App\Support\LosslessJson;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/** SlipOK API adapter using its documented branch endpoint and x-authorization key. */
final class SlipOkProvider implements SlipVerificationProviderInterface
{
    public function name(): string
    {
        return 'slipok';
    }

    public function verifyQr(string $payload): SlipVerificationResult
    {
        $this->assertConfigured();
        if (trim($payload) === '' || strlen($payload) > 1000) {
            throw FinancialException::withCode('slip_invalid', 'The slip QR payload is invalid.');
        }

        try {
            $response = Http::withHeaders([$this->authorizationHeader() => (string) config('slip_verification.providers.slipok.api_key')])
                ->acceptJson()
                ->asJson()
                ->timeout(max(1, (int) config('slip_verification.providers.slipok.timeout', 12)))
                ->retry(0)
                ->withOptions(['allow_redirects' => false])
                // SlipOK checks its branch's duplicate history only when log is enabled.
                ->post($this->url(), ['data' => $payload, 'log' => true]);
        } catch (Throwable $exception) {
            throw FinancialException::withCode('slip_provider_unreachable', 'The slip verification provider could not be reached.', [], $exception);
        }

        return $this->mapResponse($response);
    }

    public function verifyImage(UploadedFile|string $image): SlipVerificationResult
    {
        $this->assertConfigured();
        $path = $image instanceof UploadedFile ? (string) $image->getRealPath() : $image;
        $fileSize = $path !== '' && is_file($path) ? filesize($path) : false;
        if (! is_int($fileSize) || $fileSize < 1 || $fileSize > 5 * 1024 * 1024) {
            throw FinancialException::withCode('slip_image_too_large', 'The image exceeds SlipOK 5 MiB image upload limit.');
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes) || $bytes === '') {
            throw FinancialException::withCode('slip_provider_error', 'The quarantined slip image could not be read.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $filename = match ($mime) {
            'image/jpeg' => 'slip.jpg',
            'image/png' => 'slip.png',
            default => throw FinancialException::withCode('slip_invalid', 'The quarantined slip image format is not supported.'),
        };
        try {
            $response = Http::withHeaders([$this->authorizationHeader() => (string) config('slip_verification.providers.slipok.api_key')])
                ->acceptJson()
                ->timeout(max(1, (int) config('slip_verification.providers.slipok.timeout', 12)))
                ->retry(0)
                ->withOptions(['allow_redirects' => false])
                ->attach('files', $bytes, $filename, ['Content-Type' => $mime])
                // Multipart encodes true as 1, matching SlipOK's PHP example.
                ->post($this->url(), ['log' => true]);
        } catch (Throwable $exception) {
            throw FinancialException::withCode('slip_provider_unreachable', 'The slip verification provider could not be reached.', [], $exception);
        }

        return $this->mapResponse($response);
    }

    private function assertConfigured(): void
    {
        $branch = trim((string) config('slip_verification.providers.slipok.branch_id', ''));
        $key = trim((string) config('slip_verification.providers.slipok.api_key', ''));
        if (! (bool) config('slip_verification.enabled', false)
            || $branch === '' || $key === '' || ! $this->isSecureEndpoint($this->url())) {
            throw FinancialException::withCode('slip_provider_not_configured', 'SlipOK is not configured for secure verification.');
        }
    }

    private function authorizationHeader(): string
    {
        $name = trim((string) config('slip_verification.providers.slipok.authorization_header', 'x-authorization'));

        return $name !== '' ? $name : 'x-authorization';
    }

    private function url(): string
    {
        $path = (string) config('slip_verification.providers.slipok.verify_path', '/api/line/apikey/{branch_id}');
        $path = str_replace('{branch_id}', rawurlencode((string) config('slip_verification.providers.slipok.branch_id', '')), $path);

        return rtrim((string) config('slip_verification.providers.slipok.base_url', ''), '/').'/'.ltrim($path, '/');
    }

    private function isSecureEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) (is_array($parts) ? ($parts['host'] ?? '') : ''));
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host !== 'api.slipok.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || in_array($host, ['localhost', 'localhost.localdomain'], true)
            || str_ends_with($host, '.example')) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP) === false
            || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function mapResponse(Response $response): SlipVerificationResult
    {
        if ($response->status() < 200 || ($response->status() >= 300 && $response->status() < 400)) {
            throw FinancialException::withCode('slip_provider_error', 'SlipOK returned a non-final verification response.');
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw FinancialException::withCode('slip_provider_error', 'SlipOK rejected the configured verification credentials.');
        }
        if ($response->status() >= 500 || $response->status() === 429) {
            throw FinancialException::withCode('slip_provider_unreachable', 'SlipOK is temporarily unavailable.');
        }

        try {
            $payload = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw FinancialException::withCode('slip_provider_error', 'SlipOK returned an unreadable verification response.', [], $exception);
        }
        if (! is_array($payload)) {
            throw FinancialException::withCode('slip_provider_error', 'SlipOK returned an unreadable verification response.');
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        if (array_key_exists('isDuplicate', $data) && ! is_bool($data['isDuplicate'])) {
            throw FinancialException::withCode('slip_provider_error', 'SlipOK returned an ambiguous duplicate status.');
        }
        $code = strtoupper((string) ($payload['code'] ?? $data['code'] ?? ''));
        $duplicate = in_array($code, ['1012', 'DUPLICATE', 'DUPLICATE_SLIP'], true)
            || ($data['isDuplicate'] ?? false) === true;
        if ($response->failed() && ! $duplicate) {
            if (in_array($response->status(), [400, 404, 422], true)
                || in_array($code, ['1005', '1006', '1007', '1008', '1011'], true)) {
                throw FinancialException::withCode('slip_invalid', 'The slip could not be verified as a valid transfer.');
            }
            throw FinancialException::withCode('slip_provider_error', 'SlipOK refused the verification request.');
        }
        // SlipOK documents two mandatory boolean flags: request success and
        // QR validity. Neither a truthy string/number nor one true flag can
        // override a false or missing flag on the other level.
        $outerSuccess = $payload['success'] ?? null;
        $innerSuccess = $data['success'] ?? null;
        $valid = $outerSuccess === true && $innerSuccess === true;
        if (! $valid && ! $duplicate) {
            if (in_array($code, ['1009', '1010'], true)) {
                throw FinancialException::withCode('slip_provider_unreachable', 'SlipOK cannot confirm this transfer at this time.');
            }
            if ($outerSuccess === false || $innerSuccess === false) {
                throw FinancialException::withCode('slip_invalid', 'The slip could not be verified as a valid transfer.');
            }
            throw FinancialException::withCode('slip_provider_error', 'SlipOK returned incomplete or malformed verification status.');
        }

        $transRef = (string) ($data['transRef'] ?? $data['trans_ref'] ?? '');
        $amount = $this->exactAmount($response->body());
        try {
            $amount = $amount !== null ? Money::of($amount, Currency::THB)->toString() : null;
        } catch (Throwable) {
            $amount = null;
        }
        $timestamp = (string) ($data['transTimestamp'] ?? '');
        if ($timestamp === '') {
            $date = (string) ($data['transDate'] ?? '');
            $time = (string) ($data['transTime'] ?? '00:00:00');
            if (preg_match('/^\d{8}$/D', $date) === 1) {
                $timestamp = substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2).'T'.$time.'+07:00';
            }
        }
        $currency = strtoupper((string) ($data['currency'] ?? $data['paidLocalCurrency'] ?? ''));
        if ($currency === '764' || ($currency === '' && strtoupper((string) ($data['countryCode'] ?? '')) === 'TH')) {
            $currency = 'THB';
        }
        $receiver = is_array($data['receiver'] ?? null) ? $data['receiver'] : [];
        $receiverAccount = (string) data_get($receiver, 'account.value', data_get($receiver, 'proxy.value', ''));
        $receiverName = (string) ($receiver['displayName'] ?? $receiver['name'] ?? '');
        $sender = is_array($data['sender'] ?? null) ? $data['sender'] : [];
        $senderName = (string) ($sender['displayName'] ?? $sender['name'] ?? '');
        $sendingBank = (string) ($data['sendingBank'] ?? '');
        $receivingBank = (string) ($data['receivingBank'] ?? '');

        if (preg_match('~\A[A-Za-z0-9][A-Za-z0-9._:/-]{0,190}\z~D', $transRef) !== 1
            || $amount === null || $timestamp === '') {
            if ($duplicate) {
                // A 1012 duplicate response need not include the original slip facts.
                throw FinancialException::withCode('slip_duplicate', 'SlipOK reported that this slip was already submitted.');
            }
            throw FinancialException::withCode('slip_provider_error', 'SlipOK did not return complete, usable transfer facts.');
        }
        try {
            $slipDateTime = CarbonImmutable::parse($timestamp);
        } catch (Throwable $exception) {
            throw FinancialException::withCode('slip_provider_error', 'SlipOK returned an invalid transfer timestamp.', [], $exception);
        }

        $masked = ProviderAccountMask::lastFourIfExposed($receiverAccount);

        return new SlipVerificationResult(
            transRef: $transRef,
            amount: $amount,
            currency: $currency,
            slipDateTime: $slipDateTime,
            sendingBank: substr($sendingBank, 0, 80),
            receiverAccountMasked: $masked,
            receiverName: substr($receiverName, 0, 160),
            senderName: substr($senderName, 0, 160),
            rawSanitised: [
                'trans_ref_sha256' => hash('sha256', $transRef),
                'amount' => $amount,
                'currency' => $currency,
                'slip_datetime' => $slipDateTime->toIso8601String(),
                'sending_bank' => substr($sendingBank, 0, 40),
                'receiving_bank' => substr($receivingBank, 0, 40),
                'receiver_account_masked' => $masked,
                'duplicate' => $duplicate,
            ],
            valid: $valid,
            duplicate: $duplicate,
            receivingBank: substr($receivingBank, 0, 80),
        );
    }

    private function exactAmount(string $body): ?string
    {
        try {
            $payload = LosslessJson::decode($body);
        } catch (JsonException) {
            return null;
        }
        $amount = data_get($payload, 'data.amount');

        return is_string($amount) ? $amount : null;
    }

}
