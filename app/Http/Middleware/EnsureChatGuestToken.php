<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Chat\ChatIdentityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the anonymous visitor's identity before any chat endpoint runs.
 *
 * ============================================================================
 * THE SAME-REQUEST PROBLEM THIS EXISTS TO SOLVE
 * ============================================================================
 * A cookie set on the RESPONSE is invisible to the REQUEST that set it. If
 * issuing the token happened inside a controller, the very first chat call
 * would see no identity: the conversation would be created without a guest
 * hash, the cookie would arrive with the response, and the NEXT request
 * would then fail to find a conversation matching its brand-new hash. The
 * visitor would see a chat that forgets them on every reload.
 *
 * So the token is issued HERE, before the controller, and handed to the
 * controller through a request attribute (ChatIdentityService::
 * REQUEST_ATTR_NEW_TOKEN) which tokenFromRequest() reads with priority. The
 * same request that creates the identity can immediately use it.
 *
 * ============================================================================
 * WHO GETS A TOKEN
 * ============================================================================
 * Only browsers that have neither a chat cookie nor a signed-in session.
 * Signed-in members are identified by their user id and need no token; a
 * browser that already has a cookie keeps it (issuing a new one would
 * orphan whatever conversation the old token pointed at).
 *
 * The middleware never BLOCKS. A request with no identity simply proceeds;
 * the endpoints decide for themselves whether the absence is acceptable
 * (availability: yes; send: no). Middleware that refused here would have to
 * duplicate every endpoint's rule, and the two would drift.
 */
final class EnsureChatGuestToken
{
    public function __construct(
        private readonly ChatIdentityService $identity,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $hasCookie = is_string($request->cookie(ChatIdentityService::COOKIE_NAME))
            && $request->cookie(ChatIdentityService::COOKIE_NAME) !== '';

        $hasSession = $request->user() !== null;

        if (! $hasCookie && ! $hasSession) {
            $token = $this->identity->newToken();

            // In-process hand-off for this request...
            $request->attributes->set(ChatIdentityService::REQUEST_ATTR_NEW_TOKEN, $token);
        }

        $response = $next($request);

        // ...and the durable copy for every request after it. Attached only
        // when we issued a token, so an existing identity is never clobbered
        // and a signed-in member never gets a guest cookie.
        if (! $hasCookie && ! $hasSession) {
            $issued = $request->attributes->get(ChatIdentityService::REQUEST_ATTR_NEW_TOKEN);

            if (is_string($issued) && $issued !== '') {
                $response->headers->setCookie($this->identity->buildCookie($issued));
            }
        }

        return $response;
    }
}
