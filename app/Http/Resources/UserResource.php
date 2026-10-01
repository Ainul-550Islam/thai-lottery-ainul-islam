<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public-Safe User Account API Resource.
 *
 * Serializes authenticated user attributes while strictly concealing password hashes,
 * MFA secrets, sensitive KYC document file paths, and internal risk scores.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        $kycStatus = method_exists($user, 'kycStatus')
            ? $user->kycStatus()->value
            : ($user->kyc_status?->value ?? 'unverified');

        return [
            'name' => (string) $user->name,
            'username' => (string) $user->username,
            'email' => (string) $user->email,
            'phone' => $user->phone,
            'status' => $user->status->value ?? (string) $user->status,
            'is_active' => method_exists($user, 'isActive') ? $user->isActive() : ($user->status->value ?? '') === 'active',
            'kyc_status' => $kycStatus,
            'email_verified' => $user->email_verified_at !== null,
            'phone_verified' => $user->phone_verified_at !== null,
            'created_at' => $user->created_at?->toIso8601String(),
            'wallets' => WalletResource::collection($this->whenLoaded('wallets')),
        ];
    }
}
