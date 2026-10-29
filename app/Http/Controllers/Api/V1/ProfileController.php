<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Rules\StrongPasswordRule;
use App\Models\User;
use App\Services\Auth\PasswordChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Player Profile and Account Settings API Controller.
 */
final class ProfileController
{
    /**
     * Get authenticated player's full profile details.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success(
            data: [
                'user' => [
                    'name' => (string) $user->name,
                    'username' => (string) $user->username,
                    'email' => (string) $user->email,
                    'phone' => $user->phone,
                    'avatar_url' => $user->avatar_url,
                    'status' => $user->status->value,
                    'email_verified' => $user->email_verified_at !== null,
                    'phone_verified' => $user->phone_verified_at !== null,
                    'preferences' => $user->preferences ?? [],
                    'last_login_at' => $user->last_login_at?->toIso8601String(),
                    'created_at' => $user->created_at?->toIso8601String(),
                ],
            ],
            message: (string) trans('player.profile_retrieved'),
        );
    }

    /**
     * Update authenticated player's profile info.
     */
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'avatar_url' => ['nullable', 'url', 'max:500'],
            'preferences' => ['nullable', 'array'],
        ]);

        if (array_key_exists('name', $validated)) {
            $user->name = trim((string) $validated['name']);
        }

        if (array_key_exists('phone', $validated)) {
            $user->phone = $validated['phone'] !== null ? trim((string) $validated['phone']) : null;
        }

        if (array_key_exists('avatar_url', $validated)) {
            $user->avatar_url = $validated['avatar_url'];
        }

        if (array_key_exists('preferences', $validated)) {
            $user->preferences = $validated['preferences'];
        }

        $user->save();

        return ApiResponse::success(
            data: [
                'user' => [
                    'name' => (string) $user->name,
                    'username' => (string) $user->username,
                    'email' => (string) $user->email,
                    'phone' => $user->phone,
                    'avatar_url' => $user->avatar_url,
                    'status' => $user->status->value,
                    'preferences' => $user->preferences ?? [],
                ],
            ],
            message: (string) trans('player.profile_updated'),
        );
    }

    /**
     * Update authenticated player's password with current password verification.
     */
    public function updatePassword(Request $request, PasswordChangeService $passwords): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            // Audit S2: the SAME centralised rule registration and reset
            // use — the API profile surface must not drift weaker.
            'password' => ['required', 'string', new StrongPasswordRule(), 'confirmed'],
        ]);

        try {
            $passwords->change($user, $validated['current_password'], $validated['password']);
        } catch (ValidationException $exception) {
            if (! array_key_exists('current_password', $exception->errors())) {
                throw $exception;
            }

            return ApiResponse::error(
                code: 'invalid_current_password',
                message: (string) trans('player.current_password_invalid'),
                status: 422,
            );
        }

        // Sanctum can also authenticate a first-party browser session. Do not
        // let that in-memory session be written back after revocation.
        $webUser = $request->hasSession() ? Auth::guard('web')->user() : null;
        if ($webUser instanceof User && $webUser->is($user)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return ApiResponse::success(
            data: [],
            message: (string) trans('player.password_updated'),
        );
    }
}
