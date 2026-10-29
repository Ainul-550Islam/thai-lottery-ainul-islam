<?php

declare(strict_types=1);

namespace App\Services\Payment\Slip;

use App\DTOs\Payment\SlipVerificationResult;
use App\Exceptions\FinancialException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use App\Enums\Currency;
use App\Services\Finance\Money;
use App\Support\LosslessJson;
use JsonException;
use Throwable;

/** EasySlip API v2 adapter. It stores only the verification facts needed downstream. */
final class EasySlipProvider implements SlipVerificationProviderInterface
{
    public function name(): string
    {
        return 'easyslip';
    }

    public function verifyQr(string $payload): SlipVerificationResult
    {
        $this->assertConfigured();
        if (trim($payload) === '' || strlen($payload) > 1000) {
            throw FinancialException::withCode('slip_invalid', 'The slip QR payload is invalid.');
        }

        try {
            $response = Http::withToken((string) config('slip_verification.providers.easyslip.token'))
                ->acceptJson()
                ->asJson()
                ->timeout(max(1, (int) config('slip_verification.providers.easyslip.timeout', 12)))
                ->retry(0)
                ->withOptions(['allow_redirects' => false])
                ->post($this->url(), ['payload' => $payload, 'checkDuplicate' => true]);
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
        if (! is_int($fileSize) || $fileSize < 1) {
            throw FinancialException::withCode('slip_provider_error', 'The quarantined slip image could not be read.');
        }
        if ($fileSize > 4_000_000) {
            throw FinancialException::withCode('slip_image_too_large', 'The image exceeds EasySlip 4 MB image upload limit.');
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
            $response = Http::withToken((string) config('slip_verification.providers.easyslip.token'))
                ->acceptJson()
                ->timeout(max(1, (int) config('slip_verification.providers.easyslip.timeout', 12)))
                ->retry(0)
                ->withOptions(['allow_redirects' => false])
                ->attach('image', $bytes, $filename, ['Content-Type' => $mime])
                ->post($this->url(), ['checkDuplicate' => true]);
        } catch (Throwable $exception) {
            throw FinancialException::withCode('slip_provider_unreachable', 'The slip verification provider could not be reached.', [], $exception);
        }

        return $this->mapResponse($response);
    }

    private function assertConfigured(): void
    {
        $token = trim((string) config('slip_verification.providers.easyslip.token', ''));
        if (! (bool) config('slip_verification.enabled', false)
            || $token === '' || ! $this->isSecureEndpoint($this->url())) {
            throw FinancialException::withCode('slip_provider_not_configured', 'EasySlip is not configured for secure verification.');
        }
    }

    private function url(): string
    {
        return rtrim((string) config('slip_verification.providers.easyslip.base_url', ''), '/').'/'.ltrim(
            (string) config('slip_verification.providers.easyslip.verify_path', '/verify/bank'),
            '/',
        );
    }

    private function isSecureEndpoint(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) (is_array($parts) ? ($parts['host'] ?? '') : ''));
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host !== 'api.easyslip.com'
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
            throw FinancialException::withCode('slip_provider_error', 'EasySlip returned a non-final verification response.');
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw FinancialException::withCode('slip_provider_error', 'EasySlip rejected the configured verification credentials.');
        }
        if ($response->status() >= 500 || $response->status() === 429) {
            throw FinancialException::withCode('slip_provider_unreachable', 'EasySlip is temporarily unavailable.');
        }

        try {
            $payload = json_decode($response->body(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw FinancialException::withCode('slip_provider_error', 'EasySlip returned an unreadable verification response.', [], $exception);
        }
        if (! is_array($payload)) {
            throw FinancialException::withCode('slip_provider_error', 'EasySlip returned an unreadable verification response.');
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $rawSlip = is_array($data['rawSlip'] ?? null) ? $data['rawSlip'] : [];
        if (array_key_exists('isDuplicate', $data) && ! is_bool($data['isDuplicate'])) {
            throw FinancialException::withCode('slip_provider_error', 'EasySlip returned an ambiguous duplicate status.');
        }
        $errorCode = strtoupper((string) data_get($payload, 'error.code', ''));
        $duplicate = ($data['isDuplicate'] ?? false) === true
            || in_array($errorCode, ['DUPLICATE', 'DUPLICATE_SLIP', 'SLIP_ALREADY_VERIFIED'], true);

        if ($response->failed() && ! $duplicate) {
            if (in_array($response->status(), [400, 404, 422], true)
                || in_array($errorCode, ['SLIP_NOT_FOUND', 'VALIDATION_ERROR', 'INVALID_SLIP'], true)) {
                throw FinancialException::withCode('slip_invalid', 'The slip could not be verified as a valid transfer.');
            }
            throw FinancialException::withCode('slip_provider_error', 'EasySlip refused the verification request.');
        }
        if (($payload['success'] ?? false) !== true && ! $duplicate) {
            if (in_array($errorCode, ['API_SERVER_ERROR', 'SERVICE_UNAVAILABLE'], true)) {
                throw FinancialException::withCode('slip_provider_unreachable', 'EasySlip is temporarily unavailable.');
            }
            throw FinancialException::withCode('slip_invalid', 'The slip could not be verified as a valid transfer.');
        }

        $transRef = (string) ($rawSlip['transRef'] ?? $data['transRef'] ?? '');
        $amount = $this->exactAmount($response->body());
        try {
            $amount = $amount !== null ? Money::of($amount, Currency::THB)->toString() : null;
        } catch (Throwable) {
            $amount = null;
        }
        $currency = (string) data_get($rawSlip, 'amount.local.currency', data_get($rawSlip, 'currency', ''));
        if ($currency === '' && strtoupper((string) ($rawSlip['countryCode'] ?? '')) === 'TH') {
            $currency = 'THB';
        }
        $date = (string) ($rawSlip['date'] ?? '');
        $receiverAccount = (string) data_get(
            $rawSlip,
            'receiver.account.bank.account',
            data_get($rawSlip, 'receiver.account.proxy.account', data_get($data, 'matchedAccount.bankNumber', '')),
        );
        $receiverName = (string) data_get(
            $rawSlip,
            'receiver.account.name.th',
            data_get($rawSlip, 'receiver.account.name.en', data_get($data, 'matchedAccount.nameTh', '')),
        );
        $senderName = (string) data_get($rawSlip, 'sender.account.name.th', data_get($rawSlip, 'sender.account.name.en', ''));
        $sendingBank = (string) data_get($rawSlip, 'sender.bank.short', data_get($rawSlip, 'sender.bank.id', ''));
        $receivingBank = (string) data_get($rawSlip, 'receiver.bank.short', data_get($rawSlip, 'receiver.bank.id', ''));

        if (preg_match('~\A[A-Za-z0-9][A-Za-z0-9._:/-]{0,190}\z~D', $transRef) !== 1
            || $amount === null || $date === '') {
            throw FinancialException::withCode('slip_provider_error', 'EasySlip did not return complete, usable transfer facts.');
        }
        try {
            $slipDateTime = CarbonImmutable::parse($date);
        } catch (Throwable $exception) {
            throw FinancialException::withCode('slip_provider_error', 'EasySlip returned an invalid transfer timestamp.', [], $exception);
        }

        $masked = ProviderAccountMask::lastFourIfExposed($receiverAccount);

        return new SlipVerificationResult(
            transRef: $transRef,
            amount: $amount,
            currency: strtoupper($currency),
            slipDateTime: $slipDateTime,
            sendingBank: substr($sendingBank, 0, 80),
            receiverAccountMasked: $masked,
            receiverName: substr($receiverName, 0, 160),
            senderName: substr($senderName, 0, 160),
            rawSanitised: [
                'trans_ref_sha256' => hash('sha256', $transRef),
                'amount' => $amount,
                'currency' => strtoupper($currency),
                'slip_datetime' => $slipDateTime->toIso8601String(),
                'sending_bank' => substr($sendingBank, 0, 40),
                'receiving_bank' => substr($receivingBank, 0, 40),
                'receiver_account_masked' => $masked,
                'duplicate' => $duplicate,
            ],
            valid: (bool) ($payload['success'] ?? false),
            duplicate: $duplicate,
            receivingBank: substr($receivingBank, 0, 80),
        );
    }

    /** Read only the provider's actual amount field, retaining its JSON spelling. */
    private function exactAmount(string $body): ?string
    {
        try {
            $payload = LosslessJson::decode($body);
        } catch (JsonException) {
            return null;
        }
        $local = data_get($payload, 'data.rawSlip.amount.local.amount');
        if (is_string($local)) {
            return $local;
        }
        $amount = data_get($payload, 'data.rawSlip.amount.amount');

        return is_string($amount) ? $amount : null;
    }

}
