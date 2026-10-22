<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\FinancialException;

/** No refund-specific provider/merchant adapter or incoming-fee/dispute policy has been authorized. */
final class ExternalRefundPolicy
{
    public const CODE = 'external_refund_policy_not_configured';

    public const MESSAGE = 'External refund/chargeback processing requires a verified provider-specific policy and original-payment proof; no money or settled obligation was changed.';

    public function refuse(): never
    {
        // Deliberately not enabled by a caller boolean, event name, human reason or generic signature.
        throw FinancialException::withCode(self::CODE, self::MESSAGE);
    }
}
