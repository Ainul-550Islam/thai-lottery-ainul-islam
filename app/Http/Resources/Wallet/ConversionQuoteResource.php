<?php

declare(strict_types=1);

namespace App\Http\Resources\Wallet;

use App\DTOs\Wallet\ConversionQuoteData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A quote, as the API presents it.
 *
 * WHY THE EXPIRY IS GIVEN THREE WAYS
 * An absolute timestamp is what a client stores; seconds remaining is what a
 * countdown needs; and both together are what makes a bug report decidable
 * ("the quote said 118 seconds and the app waited 3 minutes"). Sending only one
 * of the two has caused more support tickets in more systems than any other
 * single field, so all three travel together and none of them is derived
 * client-side from a clock the client cannot trust.
 *
 * WHY THE RULE VERSION IS EXPOSED
 * A member who was quoted 1.00 THB and later charged 1.05 has a legitimate
 * complaint, and the answer is in this field: the rule version changed between
 * the quote and the execution, and the execution compares versions precisely so
 * that this cannot happen silently. Publishing the version lets a support agent
 * confirm which tariff the member was shown without reading the database.
 */
class ConversionQuoteResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ConversionQuoteData $quote */
        $quote = $this->resource;

        return [
            'reference' => $quote->reference,
            'direction' => $quote->direction->value,
            'direction_label' => $quote->direction->label(),
            'currency' => $quote->currency->value,

            'amount_in' => bcadd($quote->amountIn, '0', 2),
            'fee_amount' => bcadd($quote->feeAmount, '0', 2),
            'amount_out' => bcadd($quote->amountOut, '0', 2),
            'rate' => $quote->rate,
            'fee_bearer' => $quote->feeBearer->value,
            'fee_rule_key' => $quote->feeRuleKey,
            'fee_rule_version' => $quote->feeRuleVersion,

            'issued_at' => $quote->issuedAt->format(DATE_ATOM),
            'expires_at' => $quote->expiresAt->format(DATE_ATOM),
            'seconds_remaining' => $quote->secondsRemaining(),
            'expired' => $quote->isExpired(),
        ];
    }
}
