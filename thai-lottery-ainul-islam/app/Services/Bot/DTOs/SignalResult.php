<?php

declare(strict_types=1);

namespace App\Services\Bot\DTOs;

use InvalidArgumentException;

/** A signal's normalized strength, not its configurable weighted score. */
final readonly class SignalResult
{
    /** @param list<string> $reasons Stable codes only; never raw IP, UA or account data. */
    public function __construct(
        public string $signal,
        public int $strength,
        public array $reasons = [],
    ) {
        if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $signal)) {
            throw new InvalidArgumentException('Invalid bot signal identifier.');
        }

        if ($strength < 0 || $strength > 100) {
            throw new InvalidArgumentException('Signal strength must be between 0 and 100.');
        }

        if (! array_is_list($reasons) || count($reasons) > 16) {
            throw new InvalidArgumentException('Signal reasons must be a bounded list.');
        }

        foreach ($reasons as $reason) {
            if (! is_string($reason) || ! preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $reason)) {
                throw new InvalidArgumentException('Signal reasons must be stable, non-PII codes.');
            }
        }
    }
}
