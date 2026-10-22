<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseData;
use App\DTOs\BetPurchaseResult;

/** Internal ordinary-purchase boundary; no new money writer or alternate ledger path. */
interface BetAmendmentReplacement
{
    public function purchaseAmendment(BetPurchaseData $data, BetAmendmentPermit $permit): BetPurchaseResult;
}
