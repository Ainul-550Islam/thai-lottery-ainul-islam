<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Social sign-in is closed pending an authenticated account-linking flow.
 *
 * The former implementation silently linked a local account solely from an
 * OAuth profile's email and created members without the canonical registration
 * and consent checks. Existing links produced that way are not evidence of
 * local account ownership. Do not enable social login until historical links
 * are reviewed and a provider-specific, session-bound linking flow is shipped.
 */
final class SocialAuthController extends Controller
{
    public function redirectToProvider(string $provider): RedirectResponse
    {
        $this->refuse($provider);
    }

    public function handleProviderCallback(string $provider): RedirectResponse
    {
        // Do not exchange a code, issue a session, create a user, or trust a
        // historical link while local account ownership is unproven.
        $this->refuse($provider);
    }

    private function refuse(string $provider): never
    {
        if (! in_array($provider, ['facebook', 'twitter', 'google'], true)) {
            throw ValidationException::withMessages(['provider' => __('public_pages.social_invalid_provider')]);
        }

        throw ValidationException::withMessages(['provider' => __('public_pages.social_unavailable_until_link_verified')]);
    }
}
