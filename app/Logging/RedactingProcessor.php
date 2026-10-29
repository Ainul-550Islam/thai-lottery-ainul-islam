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
            // `tokens` is also used for a numeric count, but an array or text
            // under that same key may contain actual bearer/refresh secrets.
            if (is_string($key) && ($this->isSensitiveKey($key)
                || (strtolower($key) === 'tokens' && ! is_int($value) && ! is_float($value)))) {
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
                // Replace the entire chain; forwarding the original lets a
                // formatter inspect unredacted causes or serialized properties.
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
        // Quoted JSON-like keys may own scalar values OR whole nested objects
        // or arrays. Scan complete balanced values, not a regex prefix. This
        // does not make unknown arbitrary prose safe to log.
        $value = $this->redactQuotedPairs($value);

        // Authorization headers, in every form clients write them.
        $value = (string) preg_replace(
            '/\b(Bearer|Basic|Token)\s+[A-Za-z0-9\-._~+\/]{8,}=*/i',
            '$1 '.$this->mask,
            $value,
        );

        // key=value and key: value pairs embedded in a message.
        $value = (string) preg_replace_callback(
            '/(?<![A-Za-z0-9_])(password|passwd|pwd|secret|tokens?|api_key|apikey|authorization|signature)\s*[=:]\s*([^\s,;&\'"]+)/i',
            function (array $match): string {
                if (strtolower($match[1]) === 'tokens' && preg_match('/\A-?\d+(?:\.\d+)?\z/D', $match[2]) === 1
                    && ! $this->isSensitiveKey($match[1])) {
                    return $match[0];
                }

                return $match[1].'='.$this->mask;
            },
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

    /** Redact complete values in quoted key/value text without parsing unrelated numbers. */
    private function redactQuotedPairs(string $input): string
    {
        $length = strlen($input);
        $result = '';
        $i = 0;
        while ($i < $length) {
            $quote = $input[$i];
            if ($quote !== '"' && $quote !== "'") {
                $result .= $quote;
                $i++;
                continue;
            }
            $end = $this->quotedEnd($input, $i);
            if ($end === null) {
                // Incomplete unknown text is not provably safe; leave it for
                // the other known-shape rules rather than inventing a value.
                $result .= substr($input, $i);
                break;
            }
            $rawKey = substr($input, $i, $end - $i);
            $key = $quote === '"' ? json_decode($rawKey, true) : stripcslashes(substr($rawKey, 1, -1));
            $colon = $end;
            while ($colon < $length && ctype_space($input[$colon])) {
                $colon++;
            }
            $pluralTokens = is_string($key) && strtolower($key) === 'tokens';
            $sensitive = is_string($key) && $this->isSensitiveKey($key);
            if ((! $sensitive && ! $pluralTokens) || $colon >= $length || $input[$colon] !== ':') {
                $result .= $rawKey;
                $i = $end;
                continue;
            }
            $start = $colon + 1;
            while ($start < $length && ctype_space($input[$start])) {
                $start++;
            }
            $valueEnd = $this->valueEnd($input, $start);
            $rawValue = substr($input, $start, $valueEnd - $start);
            if ($pluralTokens && ! $sensitive && preg_match('/\A-?\d+(?:\.\d+)?\z/D', $rawValue) === 1) {
                $result .= substr($input, $i, $valueEnd - $i);
            } else {
                $result .= substr($input, $i, $start - $i).json_encode($this->mask, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $i = $valueEnd;
        }

        return $result;
    }

    /** Return the byte after a quoted string, honoring escaped quotes. */
    private function quotedEnd(string $input, int $start): ?int
    {
        $quote = $input[$start];
        $length = strlen($input);
        for ($i = $start + 1; $i < $length; $i++) {
            if ($input[$i] === '\\') {
                $i++;
            } elseif ($input[$i] === $quote) {
                return $i + 1;
            }
        }

        return null;
    }

    /** Return the byte after the entire sensitive value; malformed composites fail closed. */
    private function valueEnd(string $input, int $start): int
    {
        $length = strlen($input);
        if ($start >= $length) {
            return $length;
        }
        $first = $input[$start];
        if ($first === '"' || $first === "'") {
            return $this->quotedEnd($input, $start) ?? $length;
        }
        if ($first !== '{' && $first !== '[') {
            $i = $start;
            while ($i < $length && ! ctype_space($input[$i]) && ! str_contains(',;}&]', $input[$i])) {
                $i++;
            }

            return $i;
        }
        $stack = [];
        for ($i = $start; $i < $length; $i++) {
            $char = $input[$i];
            if ($char === '"' || $char === "'") {
                $end = $this->quotedEnd($input, $i);
                if ($end === null) {
                    return $length;
                }
                $i = $end - 1;
            } elseif ($char === '{' || $char === '[') {
                $stack[] = $char;
                if (count($stack) > 32) {
                    return $length;
                }
            } elseif ($char === '}' || $char === ']') {
                $open = array_pop($stack);
                if (($char === '}' && $open !== '{') || ($char === ']' && $open !== '[')) {
                    return $length;
                }
                if ($stack === []) {
                    return $i + 1;
                }
            }
        }

        return $length;
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
     * Never hand a formatter the original Throwable: it may have a sensitive
     * previous exception or a JsonSerializable payload even when its own
     * message is harmless. Reconstruct a bounded, sanitized cause chain. We
     * retain class, source location and file/line-only frames for diagnosis,
     * not stack arguments or arbitrary exception properties.
     */
    private function scrubThrowable(Throwable $throwable, int $depth = 0): Throwable
    {
        $class = explode("\0", $throwable::class, 2)[0];
        $class = preg_replace('/[^A-Za-z0-9_\\\\@]/', '?', $class) ?? 'Throwable';
        $location = $this->redactString(basename($throwable->getFile())).':'.$throwable->getLine();
        $frames = [];
        foreach (array_slice($throwable->getTrace(), 0, 16) as $frame) {
            if (is_string($frame['file'] ?? null)) {
                $frames[] = $this->redactString(basename($frame['file'])).':'.(int) ($frame['line'] ?? 0);
            }
        }
        $message = '['.$class.' @ '.$location.'] '.$this->redactString($throwable->getMessage());
        if ($frames !== []) {
            $message .= ' [trace: '.implode(' ', $frames).']';
        }

        $previous = $throwable->getPrevious();
        $safePrevious = $previous === null
            ? null
            : ($depth < 8 ? $this->scrubThrowable($previous, $depth + 1) : new \RuntimeException('[cause chain truncated]'));
        $code = $throwable->getCode();

        return new \RuntimeException($message, is_int($code) ? $code : 0, $safePrevious);
    }
}
