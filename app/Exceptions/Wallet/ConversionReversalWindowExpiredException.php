<?php

declare(strict_types=1);

namespace App\Exceptions\Wallet;

use App\Enums\WalletConversionRejectionReason;
use Throwable;

final class ConversionReversalWindowExpiredException extends ConversionException
{
    public const ERROR_CODE = 'reversal_window_expired';

    public function __construct(
        string $conversionReference = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $conversionReference !== ''
                ? sprintf('Reversal window has expired for conversion [%s].', $conversionReference)
                : 'Reversal window has expired for this conversion.',
            rejectionReason: WalletConversionRejectionReason::InvalidState,
            errorCode: self::ERROR_CODE,
            context: array_filter(['conversion_reference' => $conversionReference]),
            httpStatus: 422,
            previous: $previous,
        );
    }
}
