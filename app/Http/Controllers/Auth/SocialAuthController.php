<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleSignupConfirmRequest;
use App\Http\Support\AuthAuditRecorder;
use App\Services\Auth\Google\GoogleOidcClient;
use App\Services\Auth\Google\GoogleProfile;
use App\Services\Auth\Google\GoogleRegistrationService;
use App\Services\Auth\Google\GoogleSignInException;
use App\Services\Auth\Google\GoogleSignInService;
use App\Services\Legal\LegalDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Google is the only enabled provider. Facebook and X stay closed exactly as before:
 * they would need their own verified-identity flow, and the old email-only linking is gone.
 */
final class SocialAuthController extends Controller
{
    public function __construct(
        private readonly GoogleOidcClient $google,
        private readonly GoogleSignInService $signIn,
        private readonly GoogleRegistrationService $registration,
        private readonly LegalDocumentService $legalDocuments,
        private readonly AuthAuditRecorder $audit,
    ) {}

    /** Step 1: one tap -> Google. */
    public function redirectToProvider(Request $request, string $provider): RedirectResponse
    {
        $this->assertGoogleAvailable($provider);

        return redirect()->away($this->google->authorizationUrl($request));
    }

    /** Step 2: Google -> us. Verify, then log in / park a new member / refuse an email clash. */
    public function handleProviderCallback(Request $request, string $provider): RedirectResponse
    {
        $this->assertGoogleAvailable($provider);

        try {
            $profile = $this->google->profileFromCallback($request);
        } catch (GoogleSignInException $e) {
            // Reason goes to the audit log only; the visitor gets one generic message.
            $this->audit->recordFailure($request, 'google', 'google_'.$e->reason);

            return redirect()->route('login')->withErrors(['login' => __('public_pages.google_signin_failed')]);
        }

        return match ($this->signIn->resolve($request, $profile)) {
            GoogleSignInService::LOGGED_IN => redirect()->intended(route('player.dashboard')),
            GoogleSignInService::NEEDS_SIGNUP => redirect()->route('social.google.confirm'),
            default => redirect()->route('login')->withErrors(['login' => __('public_pages.google_email_conflict_login')]),
        };
    }

    /** Step 3 (new members only): the minimum the law and the referral rule still require. */
    public function showGoogleConfirm(Request $request): View|RedirectResponse
    {
        $pending = $this->signIn->pending($request);

        if ($pending === null) {
            return redirect()->route('login')->withErrors(['login' => __('public_pages.google_signup_expired')]);
        }

        $locale = (string) app()->getLocale();
        $terms = $this->legalDocuments->currentData('terms', $locale);
        $privacy = $this->legalDocuments->currentData('privacy', $locale);

        return view('auth.google-confirm', [
            'profile' => GoogleProfile::fromSession($pending),
            'termsDocument' => is_array($terms) ? $terms : [],
            'privacyDocument' => is_array($privacy) ? $privacy : [],
            'legalAvailable' => ($terms['status'] ?? null) === 'AVAILABLE' && ($privacy['status'] ?? null) === 'AVAILABLE',
        ]);
    }

    public function completeGoogleSignup(GoogleSignupConfirmRequest $request): RedirectResponse
    {
        $pending = $this->signIn->pending($request);

        if ($pending === null) {
            return redirect()->route('login')->withErrors(['login' => __('public_pages.google_signup_expired')]);
        }

        // A ValidationException (bad referral, under-age DOB, stale legal version, ...) rolls the
        // whole transaction back and sends the field errors to the confirm form.
        $user = $this->registration->register($request, $pending, $request->validated());

        $request->session()->forget(GoogleSignInService::PENDING_KEY);
        $this->signIn->login($request, $user); // same hardening as every other login path

        return redirect()->route('player.dashboard')->with('status', __('public_pages.google_welcome_status'));
    }

    private function assertGoogleAvailable(string $provider): void
    {
        // Literal translation keys on purpose: BanglaPublicPagesTest scans for them statically.
        if ($provider !== 'google') {
            throw ValidationException::withMessages(['provider' => __('public_pages.social_unavailable_until_link_verified')]);
        }

        if (! $this->google->enabled()) {
            throw ValidationException::withMessages(['provider' => __('public_pages.google_signin_unavailable')]);
        }
    }
}
