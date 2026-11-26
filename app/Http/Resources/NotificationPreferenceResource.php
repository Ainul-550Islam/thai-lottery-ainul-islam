<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One cell of the notification matrix.
 *
 * The three fields that matter are `enabled`, `mandatory` and `locked`. `reason` is
 * non-null exactly when the cell cannot be changed, so a client can render a lock
 * with an explanation instead of a toggle that silently refuses.
 *
 * @property array<string, mixed> $resource
 */
final class NotificationPreferenceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = (array) $this->resource;

        return [
            'event_type' => (string) ($row['event_type'] ?? ''),
            'label' => (string) ($row['label'] ?? ''),
            'category' => (string) ($row['category'] ?? 'other'),
            'channel' => (string) ($row['channel'] ?? 'in_app'),
            'enabled' => (bool) ($row['enabled'] ?? true),
            'mandatory' => (bool) ($row['mandatory'] ?? false),
            'locked' => (bool) ($row['locked'] ?? false),
            'reason' => $row['reason'] ?? null,
            'quiet_hours' => $row['quiet_hours'] ?? null,
        ];
    }
}
