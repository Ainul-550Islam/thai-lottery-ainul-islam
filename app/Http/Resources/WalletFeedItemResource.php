<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the money feed.
 *
 * `amount` is the ABSOLUTE value and `direction` carries the sign, so a client never
 * has to render "--10.00" or guess from a description. The projection already
 * happened in WalletTransactionFeedService::project(); this class only retypes.
 *
 * @property array<string, mixed> $resource
 */
final class WalletFeedItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = (array) $this->resource;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'reference' => (string) ($row['reference'] ?? ''),
            'type' => (string) ($row['type'] ?? 'adjustment'),
            'label' => (string) ($row['label'] ?? ''),
            'direction' => (string) ($row['direction'] ?? 'credit'),
            'amount' => (string) ($row['amount'] ?? '0.00'),
            'currency' => (string) ($row['currency'] ?? 'THB'),
            'status' => (string) ($row['status'] ?? ''),
            'description' => $row['description'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }
}
