<?php

declare(strict_types=1);

namespace App\Http\Resources\Wallet;

use App\Enums\Currency;
use App\Models\WalletConversion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One conversion, as the API presents it.
 *
 * WHAT IS DELIBERATELY ABSENT
 * Wallet ids are exposed because a client needs them to reconcile its own view,
 * but no balance is included: the member's balance is available from the wallet
 * endpoint, and putting a balance into a conversion payload would mean a client
 * that cached it holds a stale number that looks authoritative. Internal
 * columns — the actor's user id, the idempotency key, the raw metadata blob —
 * are omitted entirely. An idempotency key is a client-generated nonce, but it
 * is a first-class credential for replaying a request, so it never travels back
 * out in a response body.
 *
 * WHY `is_reversible` IS COMPUTED HERE
 * Whether a conversion may still be reversed depends on its status and on a
 * configured window measured from completion. The model already knows both, so
 * the flag is asked of the model rather than re-derived in the resource; a
 * resource that computed its own window would render a button for a reversal the
 * engine is about to refuse.
 */
class WalletConversionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WalletConversion $conversion */
        $conversion = $this->resource;

        return [
            'id' => (int) $conversion->getKey(),
            'reference' => (string) $conversion->reference,
            'direction' => $conversion->direction->value,
            'direction_label' => $conversion->direction->label(),
            'status' => $conversion->status->value,
            'status_label' => $conversion->status->label(),

            'currency' => $conversion->currency instanceof Currency ? $conversion->currency->value : (string) $conversion->currency,
            'amount_in' => bcadd((string) $conversion->amount_in, '0', 2),
            'fee_amount' => bcadd((string) $conversion->fee_amount, '0', 2),
            'amount_out' => bcadd((string) $conversion->amount_out, '0', 2),
            'fee_rate' => (string) $conversion->fee_rate,
            'fee_rule_key' => (string) $conversion->fee_rule_key,
            'fee_rule_version' => (string) $conversion->fee_rule_version,

            'from_wallet_id' => (int) $conversion->from_wallet_id,
            'to_wallet_id' => (int) $conversion->to_wallet_id,
            'from_wallet_type' => (string) $conversion->from_wallet_type,
            'to_wallet_type' => (string) $conversion->to_wallet_type,
            'from_balance_after' => $conversion->from_balance_after === null
                ? null
                : bcadd((string) $conversion->from_balance_after, '0', 2),
            'to_balance_after' => $conversion->to_balance_after === null
                ? null
                : bcadd((string) $conversion->to_balance_after, '0', 2),

            'channel' => $conversion->channel?->value,
            'quote_reference' => $conversion->quote_reference,
            'is_reversible' => $conversion->canBeReversed()
                && $conversion->isInsideReversalWindow((int) config('conversions.reversal.window_hours', 24)),

            'reversal_of' => $conversion->reversal_of_id === null ? null : [
                'id' => (int) $conversion->reversal_of_id,
                'reversed_by_id' => $conversion->reversed_by_id === null ? null : (int) $conversion->reversed_by_id,
                'reason' => $conversion->reversal_reason,
            ],

            'created_at' => $conversion->created_at?->format(DATE_ATOM),
            'completed_at' => $conversion->completed_at?->format(DATE_ATOM),
            'reversed_at' => $conversion->reversed_at?->format(DATE_ATOM),
        ];
    }
}
