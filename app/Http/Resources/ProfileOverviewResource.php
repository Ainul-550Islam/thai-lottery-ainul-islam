<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The member's own profile — already masked by ProfileOverviewService.
 *
 * This class does NOT unmask anything and has no access to the raw values, which is
 * the point: the resource layer is the most likely place for a "just for debugging"
 * full email, so it never receives one.
 *
 * @property array<string, mixed> $resource
 */
final class ProfileOverviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $profile */
        $profile = (array) $this->resource;

        return [
            'member' => $profile['member'] ?? [],
            'personal' => $profile['personal'] ?? [],
            'verification' => $profile['verification'] ?? [],
            'balances' => $profile['balances'] ?? [],
            'wallet_types_available' => $profile['wallet_types_available'] ?? [],
        ];
    }
}
