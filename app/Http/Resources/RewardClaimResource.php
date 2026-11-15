<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FreeBetVoucher;
use App\Models\RewardCatalogItem;
use App\Models\RewardClaim;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RewardClaim
 */
final class RewardClaimResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var RewardClaim $claim */
        $claim = $this->resource;
        $item = $claim->relationLoaded('item') ? $claim->item : null;
        $voucher = $claim->relationLoaded('voucher') ? $claim->voucher : null;

        return [
            'id' => (int) $claim->getKey(),
            'item_id' => (int) $claim->item_id,
            'item_code' => $item instanceof RewardCatalogItem ? $item->code : null,
            'item_name' => $item instanceof RewardCatalogItem ? $item->name : null,
            'type' => $item instanceof RewardCatalogItem ? $item->type->value : null,
            'points_spent' => (int) $claim->points_spent,
            'status' => $claim->status->value,
            'idempotency_key' => $claim->idempotency_key,
            'financial_transaction_id' => $claim->financial_transaction_id,
            'payload' => $claim->payload ?? [],
            'voucher' => $voucher instanceof FreeBetVoucher ? [
                'id' => (int) $voucher->getKey(),
                'code' => $voucher->code,
                'amount' => (string) $voucher->amount,
                'currency' => $voucher->currency,
                'status' => $voucher->status->value,
                'expires_at' => $voucher->expires_at->toIso8601String(),
            ] : null,
            'completed_at' => $claim->completed_at?->toIso8601String(),
            'created_at' => $claim->created_at?->toIso8601String(),
        ];
    }
}
