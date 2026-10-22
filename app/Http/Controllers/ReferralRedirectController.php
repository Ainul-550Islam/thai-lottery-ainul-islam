<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Agent\PublicReferralLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Public Referral Landing Redirect (`/r/{code}`, Audit §D6.4).
 *
 * Records a deduplicated, privacy-hashed visit against active intake-eligible
 * agents, persists the referral code in session + an HttpOnly SameSite=Lax
 * cookie so attribution survives browsing before registration, and redirects
 * cleanly to `GET /register?ref={CODE}`.
 *
 * Unknown/inactive codes still redirect safely to `GET /register` without
 * exposing error pages or allowing open redirects.
 */
final class ReferralRedirectController extends Controller
{
    public function __construct(
        private readonly PublicReferralLinkService $referrals,
    ) {}

    public function __invoke(Request $request, string $code): RedirectResponse
    {
        $normalised = $this->referrals->normaliseCode($code);

        if ($normalised !== null) {
            $agent = $this->referrals->recordVisit(
                rawCode: $normalised,
                ipAddress: $request->ip(),
                refererUrl: $request->headers->get('referer'),
                locale: app()->getLocale(),
            );

            if ($agent !== null) {
                if ($request->hasSession()) {
                    $request->session()->put(PublicReferralLinkService::SESSION_KEY, $agent->agent_code);
                }

                $cookieName = (string) config('conversion_tooling.referrals.cookie_name', 'tl_ref');
                $ttlMinutes = max(5, (int) config('conversion_tooling.referrals.cookie_ttl_minutes', 43200));

                return redirect()
                    ->route('register', ['ref' => $agent->agent_code])
                    ->cookie(
                        name: $cookieName,
                        value: $agent->agent_code,
                        minutes: $ttlMinutes,
                        path: '/',
                        domain: null,
                        secure: $request->isSecure(),
                        httpOnly: true,
                        raw: false,
                        sameSite: 'lax',
                    );
            }
        }

        return redirect()->route('register');
    }
}
