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
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user instanceof User) {
            try {
                if (Cache::add('presence:'.$user->getKey(), 1, now()->addMinute())) {
                    User::query()
                        ->whereKey($user->getKey())
                        ->update(['last_seen_at' => now()]);
                }
            } catch (\Throwable $exception) {
                // Presence is an aggregate convenience, never a request blocker.
                report($exception);
            }
        }

        return $response;
    }
}
