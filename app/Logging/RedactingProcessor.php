<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Throwable;

/**
 * The Monolog processor behind RedactSensitiveData.
 *
 * Extracted into its own file so it can be unit-tested without a Laravel
 * logger instance, and so the key list can be injected (tests pass their own).
 *
 * Monolog 3 hands processors an immutable LogRecord, so this returns a modified
 * copy rather than mutating in place.
 */
final class RedactingProcessor
{
    /**
     * @param  list<string>  $keys  lowercase substrings that mark a key as sensitive
     * @param  list<string>  $allowed  keys that are never redacted even if they match
     */
    public function __construct(
        private readonly array $keys = RedactSensitiveData::DEFAULT_KEYS,
        private readonly array $allowed = RedactSensitiveData::DEFAULT_ALLOWED_KEYS,
        private readonly string $mask = '[REDACTED]',
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactString($record->message),
            context: $this->redactArray($record->context),
            extra: $this->redactArray($record->extra),
        );
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    public function redactArray(array $values, int $depth = 0): array
    {
        // Bounded depth: a cyclic context object logged by mistake must not turn
        // a log write into a hang on the request path.
        if ($depth > 8) {
            return ['[TRUNCATED]'];
        }

        $redacted = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $redacted[$key] = $this->mask;

                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redactArray($value, $depth + 1);

                continue;
            }

            if (is_string($value)) {
                $redacted[$key] = $this->redactString($value);

                continue;
            }

            if ($value instanceof Throwable) {
                // Keep the exception object (its trace is what makes the log
                // useful) but rewrite its message so a DSN cannot reach the sink.
                $redacted[$key] = $this->scrubThrowable($value);

                continue;
            }

            if (is_object($value)) {
                // Never let an object's __toString() run unredacted into a log;
                // DTOs in this codebase do carry credential fields.
                $redacted[$key] = '[object '.$value::class.']';

                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    public function redactString(string $value): string
    {
        // Authorization headers, in every form clients write them.
        $value = (string) preg_replace(
            '/\b(Bearer|Basic|Token)\s+[A-Za-z0-9\-._~+\/]{8,}=*/i',
            '$1 '.$this->mask,
            $value,
        );

        // key=value and key: value pairs embedded in a message.
        $value = (string) preg_replace(
            '/(?<![A-Za-z0-9_])(password|passwd|pwd|secret|token|api_key|apikey|authorization|signature)\s*[=:]\s*[^\s,;&\'"]+/i',
            '$1='.$this->mask,
            $value,
        );

        // Credentials in a URL: scheme://user:password@host
        $value = (string) preg_replace(
            '#([a-z][a-z0-9+.\-]*://[^:/\s]+:)[^@\s]+@#i',
            '$1'.$this->mask.'@',
            $value,
        );

        // Database DSNs of the form mysql://user:pass@host/db appear verbatim in
        // PDO exceptions and are not key=value pairs.
        return (string) preg_replace(
            '/\b(mysql|pgsql|postgres|redis|amqp):\/\/([^:\s]+):([^@\s]+)@/i',
            '$1://$2:'.$this->mask.'@',
            $value,
        );
    }

    public function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '.', ' ', ' '], '_', $key));

        if (in_array($normalized, $this->allowed, true)) {
            return false;
        }

        foreach ($this->keys as $needle) {
            if ($needle !== '' && str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clone the exception with a scrubbed message, preserving type and trace.
     *
     * `withMessage()` on a Throwable in PHP 8+ returns a new instance of the
     * same class, keeping the trace — which is what we want: the trace is the
     * useful part, the message is the part that leaks.
     */
    private function scrubThrowable(Throwable $throwable): Throwable
    {
        $scrubbed = $this->redactString($throwable->getMessage());

        if ($scrubbed === $throwable->getMessage()) {
            return $throwable;
        }

        try {
            return $throwable::class === \Error::class
                ? new \Error($scrubbed, $throwable->getCode(), $throwable)
                : $throwable->withMessage($scrubbed);
        } catch (Throwable) {
            // If the class cannot be reconstructed, log the safe fallback rather
            // than the unredacted original.
            return new \RuntimeException($scrubbed, $throwable->getCode());
        }
    }
}
