<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;

/**
 * Redacts credential-shaped values from every log record before it is written.
 *
 * WHY A GLOBAL TAP AND NOT JUST StructuredLogger
 * ----------------------------------------------
 * `App\Services\Observability\StructuredLogger` already redacts, and it is the
 * right tool for code that remembers to use it. The problem is the code that
 * does not: `Log::error('Payment failed', $request->all())`, a framework
 * exception whose context carries the request payload, or a `report($e)` from
 * a gateway client that puts the DSN in the exception message. Those paths never
 * touch StructuredLogger, and they are exactly the paths that fire during an
 * incident — when somebody is most likely to paste the log into a ticket.
 *
 * Laravel's `tap` hook runs once when a channel's logger is created, which makes
 * it the one place where redaction can be applied to ALL records on that channel
 * without editing every call site.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 * It does not heuristically hunt for "high entropy strings" in free text. Such a
 * rule redacts order references and trace ids while still missing a password
 * embedded in prose: it fails at both jobs. Redaction here is by KEY
 * (`password`, `token`, `authorization`, …) and by known value shapes
 * (`Bearer …`, `key=value`, DSNs), which is deterministic and testable.
 */
class RedactSensitiveData
{
    /**
     * Keys whose values are never logged, matched case-insensitively as
     * substrings so `x-api-key`, `apiKey` and `apikey` all hit.
     *
     * @var list<string>
     */
    public const DEFAULT_KEYS = [
        'password',
        'passwd',
        'pwd',
        'credentials',
        'secret',
        'token',
        'authorization',
        'cookie',
        'session_id',
        'api_key',
        'apikey',
        'access_key',
        'private_key',
        'client_secret',
        'signature',
        'cvv',
        'cvc',
        'card_number',
        'cardnumber',
        'pan',
        'iban',
        'account_number',
        'routing_number',
        'otp',
        'pin',
        'mfa',
        'recovery_code',
    ];

    /** Keys that look sensitive but whose values are safe to log. */
    public const DEFAULT_ALLOWED_KEYS = [
        'token_type',
        'tokens',
        'password_min_length',
    ];

    public function __invoke(Logger $logger): void
    {
        if (! (bool) config('logging.redaction.enabled', true)) {
            return;
        }

        // A custom PSR logger may not be Monolog. The framework wrapper still
        // redacts its incoming record before it reaches that destination.
        if (method_exists($logger->getLogger(), 'pushProcessor')) {
            $logger->pushProcessor(self::processor());
        }
    }

    public static function processor(): RedactingProcessor
    {
        $extraKeys = (array) config('logging.redaction.keys', []);

        $keys = array_values(array_unique(array_merge(
            self::DEFAULT_KEYS,
            array_map(static fn ($value): string => strtolower((string) $value), $extraKeys),
        )));

        $allowedConfig = config('logging.redaction.allowed_keys');
        $allowed = array_values(array_unique(array_map(
            static fn ($value): string => strtolower((string) $value),
            (array) ($allowedConfig === null ? self::DEFAULT_ALLOWED_KEYS : $allowedConfig),
        )));

        $mask = (string) config('logging.redaction.mask', '[REDACTED]');

        return new RedactingProcessor($keys, array_diff($allowed, $keys), $mask);
    }
}
