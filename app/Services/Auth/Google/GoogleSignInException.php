<?php

declare(strict_types=1);

namespace App\Services\Auth\Google;

use RuntimeException;

/** $reason is a short machine code for the audit log. It is never shown to the visitor. */
final class GoogleSignInException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('google_signin_failed:'.$reason);
    }
}
