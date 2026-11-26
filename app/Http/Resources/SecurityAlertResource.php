<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One security alert.
 *
 * `ip_hint` is the masked network (the full address stays in the operator surface),
 * and `kind` is the stable member-facing bucket. `event_type` is kept raw so an
 * unfamiliar event is still identifiable rather than silently reclassified.
 *
 * @property array<string, mixed> $resource
 */
final class SecurityAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $alert */
        $alert = (array) $this->resource;

        return [
            'id' => (int) ($alert['id'] ?? 0),
            'kind' => (string) ($alert['kind'] ?? 'other'),
            'event_type' => (string) ($alert['event_type'] ?? ''),
            'risk_level' => $alert['risk_level'] ?? null,
            'occurred_at' => $alert['occurred_at'] ?? null,
            'reviewed' => (bool) ($alert['reviewed'] ?? false),
            'ip_hint' => $alert['ip_hint'] ?? null,
        ];
    }
}
