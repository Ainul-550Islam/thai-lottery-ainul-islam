<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records the last activity time of a logged-in member, for the public
 * "members active" counter (competitor parity C17, session-based).
 *
 * Guests are ignored. A cache lock lets at most one database write per member
 * per minute, so this adds no per-request write load.
 */
final class TrackMemberPresence
{
    /**
     * Document reads that never count as activity.
     *
     * Reading the Terms or the Privacy Notice is a deliberate, often one-off, legal
     * read: it must not touch the account row at all. Writing last_seen_at from these
     * pages both inflated the "members active" counter and rewrote the user record
     * during a request that promises to only read it - the consent-integrity test
     * caught exactly that, because it compares the whole row before and after.
     */
    private const NON_ACTIVITY_PATHS = ['terms', 'privacy', 'legal/consent'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (in_array(trim($request->path(), '/'), self::NON_ACTIVITY_PATHS, true)) {
            return $response;
        }

        $user = $request->user();

        if ($user instanceof User && Cache::add('presence:'.$user->getKey(), 1, now()->addMinute())) {
            User::query()
                ->whereKey($user->getKey())
                ->update(['last_seen_at' => now()]);
        }

        return $response;
    }
}
