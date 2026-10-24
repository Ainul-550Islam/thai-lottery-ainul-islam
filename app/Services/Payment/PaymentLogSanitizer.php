<?php

declare(strict_types=1);

namespace App\Services\Payment;

/**
 * Redacts secrets, signatures, tokens, card/account numbers, and PII from
 * payment gateway logs and persisted webhook metadata.
 */
final class PaymentLogSanitizer
{
    /**
     *Exact normalized key names that must never be logged or persisted in cleartext.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'secret',
        'api_key',
        'apikey',
        'app_secret',
        'client_secret',
        'webhook_secret',
        'private_key',
        'password',
        'passphrase',
        'pin',
        'otp',
        'token',
        'access_token',
        'refresh_token',
        'id_token',
        'authorization',
        'signature',
        'x_signature',
        'x_webhook_signature',
        'x_sandbox_signature',
        'stripe_signature',
        'card_number',
        'pan',
        'cvv',
        'cvc',
        'account_number',
        'bank_account_number',
        'iban',
        'promptpay_id',
        'national_id',
        'voucher_url',
        'url',
    ];

    /**
     * @param  array<string|int, mixed>|null  $payload
     * @return array<string|int, mixed>
     */
    public static function sanitize(?array $payload, int $depth = 0): array
    {
        if ($payload === null || $payload === []) {
            return [];
        }

        if ($depth > 8) {
            return ['_truncated' => '[MAX_DEPTH]'];
        }

        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = self::sanitize($value, $depth + 1);

                continue;
            }

            if (is_string($value)) {
                $sanitized[$key] = self::redactInlineSecrets($value);

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', trim($key)));
        $normalized = trim($normalized, '_');

        if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (['secret', 'password', 'signature', 'token', 'private_key', 'api_key', 'cvv', 'cvc'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private static function redactInlineSecrets(string $value): string
    {
        // Redact Bearer tokens, TrueMoney voucher URLs, and Stripe-style live/test keys if embedded in free text.
        $redacted = preg_replace(
            [
                '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
                '/https:\/\/gift\.truemoney\.com\/campaign\/\?v=[A-Za-z0-9]+/i',
                '/\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9]+/',
            ],
            [
                'Bearer [REDACTED]',
                'https://gift.truemoney.com/campaign/?v=[REDACTED]',
                '[REDACTED_KEY]',
            ],
            $value,
        );

        return is_string($redacted) ? $redacted : $value;
    }
}
