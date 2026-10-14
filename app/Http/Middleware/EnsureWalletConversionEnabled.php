<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\WalletConversionDirection;
use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the conversion lane before a controller can be reached.
 *
 * WHY THIS IS MIDDLEWARE AND NOT A CHECK INSIDE THE SERVICE
 * The service already refuses a disabled direction — that check is the one that
 * actually protects the money, and it must stay where it is. This middleware is
 * the SECOND line, and it exists for a different reason: cost and clarity. A
 * request to a closed lane should not allocate a request DTO, build a policy
 * decision, or hold a route through the application at all, and an operator
 * reading the route file should be able to see which lanes are gated without
 * opening the engine. The two checks read the same configuration value, so they
 * cannot disagree.
 *
 * WHY THE ANSWER IS A 503 AND NOT A 404 FOR A CLIENT
 * A disabled feature is temporarily unavailable, not nonexistent. A 404 would
 * tell the caller they typed the wrong address and would hide the feature from
 * support tooling that probes which surfaces exist; a 503 with a stable code
 * says "this is switched off on purpose" and, because it is retryable by
 * nature, tells a client not to ask again right now.
 *
 * WHY A BROWSER GETS THE PAGE BACK INSTEAD OF THE 503
 * A machine can act on a status code; a person cannot. A member with the page
 * open who presses Convert at the moment an operator closes the lane was
 * previously shown a bare 503 error page, which reads as "the platform is
 * broken" and tells them nothing about their money. They are now sent back to
 * the conversion page with the closure as a flash message, on a page whose body
 * already explains that conversions are paused and that no balance was touched —
 * which is the same answer the web lane gives to every other refusal, and the
 * only version of it written for a human. The API lane's 503 with its stable code
 * and Retry-After hint is unchanged, because that is the answer an integration
 * needs.
 *
 * WHY THE DIRECTION IS READ FROM THE REQUEST
 * Each direction has its own switch, so gating the whole prefix on the master
 * switch alone would let a request through to an engine that then refuses it —
 * which is exactly the extra work and the confusing error this middleware
 * exists to avoid. The direction is taken from the route, the body or the
 * query string, in that order, because a GET has no body and a POST may carry
 * it in either.
 */
class EnsureWalletConversionEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('conversions.enabled', true)) {
            return $this->refuse(
                $request,
                'conversion_disabled',
                'Wallet conversions are temporarily unavailable. Please try again later.',
                null,
            );
        }

        $direction = $this->directionFrom($request);

        if ($direction !== null
            && ! (bool) config('conversions.directions.'.$direction->value.'.enabled', true)) {
            return $this->refuse(
                $request,
                'conversion_direction_disabled',
                sprintf('The %s direction is temporarily unavailable.', $direction->label()),
                $direction->value,
            );
        }

        return $next($request);
    }

    /**
     * The requested direction, or null when the request does not name one.
     *
     * Returning null rather than guessing a default is deliberate: a request
     * that does not state its direction will be refused by validation, with a
     * message about the missing field, instead of being silently interpreted as
     * Cash -> Win and refused for a different reason than the real one.
     */
    private function directionFrom(Request $request): ?WalletConversionDirection
    {
        $value = $request->route('direction')
            ?? $request->input('direction')
            ?? $request->query('direction');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return WalletConversionDirection::tryFrom(trim($value));
    }

    private function refuse(Request $request, string $code, string $message, ?string $direction): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return ApiResponse::error(
                code: $code,
                message: $message,
                status: 503,
                details: $direction === null ? [] : ['direction' => $direction],
                headers: ['Retry-After' => '900'],
            );
        }

        // WHY A BROWSER IS ANSWERED DIFFERENTLY FROM A CLIENT
        // 503 is the right STATUS for the lane being shut — and it stays the
        // status for every caller that has no session to carry a sentence in,
        // including a probe, a script, and the framework's own error page. But a
        // member who has just pressed Convert should not be shown a bare error
        // page for something they did not do and cannot fix: they are sent back
        // to the page, which explains the closure, and told in words that their
        // money was not touched. The distinction is whether there is a session —
        // `hasSession()` — and NOT the Accept header, because a browser form post
        // does not advertise what it is either.
        if ($request->hasSession()) {
            return redirect()
                ->route('player.wallet.conversion')
                ->with('error', $message);
        }

        abort(503, $message);
    }
}
