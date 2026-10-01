<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Current Authenticated User API Controller.
 *
 * Scopes all data strictly to $request->user(), preventing cross-user data leakage.
 * Serializes only public-safe fields (never internal passwords, remember tokens,
 * full raw KYC files, or unmasked credentials).
 */
class MeController
{
    /**
     * Get the authenticated user's profile and account overview.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiResponse::error(
                code: 'unauthenticated',
                message: 'Authentication required.',
                status: 401,
            );
        }

        $wallets = Wallet::query()
            ->where('user_id', $user->id)
            ->get()
            ->map(fn (Wallet $w): array => [
                'currency' => $w->currency->value ?? 'THB',
                'balance' => (string) $w->balance,
                'available_balance' => (string) bcsub((string) $w->balance, (string) ($w->locked_balance ?? '0'), 2),
                'status' => $w->status->value ?? 'active',
            ]);

        $kycStatus = method_exists($user, 'kycStatus')
            ? $user->kycStatus()->value
            : ($user->kyc_status?->value ?? 'unverified');

        return ApiResponse::success(
            data: [
                'user' => [
                    'name' => (string) $user->name,
                    'username' => (string) $user->username,
                    'email' => (string) $user->email,
                    'phone' => $user->phone,
                    'avatar_url' => $user->avatar_url,
                    'status' => $user->status->value ?? 'active',
                    'email_verified' => $user->email_verified_at !== null,
                    'phone_verified' => $user->phone_verified_at !== null,
                    'kyc_status' => $kycStatus,
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                    'created_at' => $user->created_at?->toIso8601String(),
                ],
                'wallets' => $wallets,
            ],
            message: 'User profile retrieved successfully.',
        );
    }
}
