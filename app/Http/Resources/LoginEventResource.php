<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoginEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoginEvent
 */
final class LoginEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LoginEvent $event */
        $event = $this->resource;
        $viewer = $request->user();
        $isOwner = $viewer instanceof User
            && $event->member_id !== null
            && (int) $viewer->getKey() === (int) $event->member_id;

        return [
            'id' => (int) $event->getKey(),
            'ip' => $isOwner ? $event->ip : $event->maskedIp(),
            'ip_country' => $event->ip_country,
            'device' => $event->device,
            'os' => $event->os,
            'browser' => $event->browser,
            'is_new_device' => (bool) $event->is_new_device,
            'trusted_device_id' => $event->trusted_device_id,
            'outcome' => $event->outcome->value,
            'occurred_at' => $event->occurred_at->toIso8601String(),
        ];
    }
}
