<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use App\Enums\WalletConversionRejectionReason;
use Throwable;

final class ConversionQuoteNotFoundException extends ConversionException
{
    public const ERROR_CODE = 'quote_not_found';

    public function __construct(
        string $quoteReference = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $quoteReference !== ''
                ? sprintf('Conversion quote [%s] was not found.', $quoteReference)
                : 'Conversion quote was not found.',
            rejectionReason: WalletConversionRejectionReason::QuoteNotFound,
            errorCode: self::ERROR_CODE,
            context: array_filter(['quote_reference' => $quoteReference]),
            httpStatus: 404,
            previous: $previous,
        );
    }
}
