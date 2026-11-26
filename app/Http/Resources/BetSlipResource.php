<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BetSlip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The draft slip as a client sees it.
 *
 * NO ARITHMETIC, NO COMPUTED PRICES
 * Totals are the stored denormalised values maintained by BetSlipService; prices
 * come from BetSlipPricingService and are passed in by the controller under a
 * separate `pricing` key. Merging the two here would hide which number is a fact
 * (the stake) and which is provisional (the potential win).
 *
 * `member_id` is absent: the only slip a caller can load is their own.
 *
 * @mixin BetSlip
 */
final class BetSlipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BetSlip $slip */
        $slip = $this->resource;

        return [
            'id' => (int) $slip->getKey(),
            'reference' => (string) $slip->reference,
            'status' => $slip->status->value,
            'currency' => (string) $slip->currency,
            'item_count' => (int) $slip->item_count,
            // Decimal:2 casts come back as STRINGS; forwarded unchanged.
            'total_stake' => (string) $slip->total_stake,
            'expires_at' => $slip->expires_at?->toIso8601String(),
            'confirmed_at' => $slip->confirmed_at?->toIso8601String(),
            'confirmed_reference' => $slip->confirmed_reference,
            'editable' => $slip->isEditable(),
        ];
    }
}
