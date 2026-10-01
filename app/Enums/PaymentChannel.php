<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Provider/channel vocabulary for the payment transaction boundary.
 */
enum PaymentChannel: string
{
    case PROMPTPAY = 'promptpay';
    case BANK_TRANSFER = 'bank_transfer';
    case BKASH = 'bkash';
    case NAGAD = 'nagad';
    case CRYPTO = 'crypto';
    case CARD = 'card';
}
