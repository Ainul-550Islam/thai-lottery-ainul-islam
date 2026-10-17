<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property \App\Models\BlockedIp $resource
 */
class BlockedIpResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'ip_or_cidr' => $this->resource->ip_or_cidr,
            'ip_version' => $this->resource->ip_version,
            'is_cidr' => $this->resource->is_cidr,
            'reason_code' => $this->resource->reason_code,
            'source' => $this->resource->source,
            'is_active' => $this->resource->is_active,
            'expires_at' => $this->resource->expires_at?->toIso8601String(),
            'unblocked_at' => $this->resource->unblocked_at?->toIso8601String(),
            'notes' => $this->resource->notes,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
