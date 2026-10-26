<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Thrown when real-money features are accessed without a valid licence.
 */
final class RealMoneyNotLicensedException extends Exception
{
    public function __construct(
        string $code,
        string $message,
        int $codeInt = 0,
        ?Exception $previous = null,
    ) {
        parent::__construct($message, $codeInt, $previous);
        // $code is stored as a string error code in $this->code via a custom accessor pattern;
        // PHP's Exception::$code is int, so we keep the string code in the message prefix
        // and expose it through errorCode() below.
        $this->code = $codeInt;
        $this->stringCode = $code;
    }

    /** @var string */
    private string $stringCode;

    public function errorCode(): string
    {
        return $this->stringCode;
    }
}
