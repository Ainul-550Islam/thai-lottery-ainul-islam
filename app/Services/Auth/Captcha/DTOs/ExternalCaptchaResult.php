<?php

declare(strict_types=1);

namespace App\Services\Auth\Captcha\DTOs;

use InvalidArgumentException;

/** Safe outcome only: never a provider body, credential or raw response token. */
final readonly class ExternalCaptchaResult
{
    public function __construct(public string $status, public string $code)
    {
        if (! in_array($status, ['verified', 'rejected', 'unavailable', 'misconfigured'], true)
            || ! preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $code)
            || (($status === 'verified') !== ($code === 'VERIFIED'))) {
            throw new InvalidArgumentException('Invalid external CAPTCHA outcome.');
        }
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    /** @return array{status: string, code: string} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'code' => $this->code];
    }
}
