<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;
use Illuminate\Http\Request;

/**
 * Authentication middleware: JSON-shaped 401s and the admin login redirect - nothing else.
 *
 * This class does NOT enforce account status. It used to be headed "JSON and Active-User
 * Defense", but no status predicate exists anywhere in its body, so a reader looking here for
 * the active-account rule found a promise instead of a gate. That rule lives in two other
 * places, and each is pinned by a test rather than by a comment:
 *   - App\Http\Middleware\EnsureUserIsActive, the `active` alias, on 154 routes; and
 *   - AdminAccess::canAccessPanel(), via $user->status->canTransact(), which is what the
 *     `can:access-admin` gate over the whole admin prefix calls.
 * The two player money services carry the same predicate again since #35; see
 * AccountStatusGateConsistencyTest for the equivalence of all four doors.
 */
class Authenticate extends BaseAuthenticate
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        if ($request->is('admin') || $request->is('admin/*')) {
            return url('/admin/login');
        }

        return $request->expectsJson() || $request->is('api/*')
            ? null
            : route('login');
    }

    /**
     * Handle unauthenticated responses for API/JSON calls.
     */
    protected function unauthenticated($request, array $guards)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            abort(ApiResponse::error(
                code: 'unauthenticated',
                message: 'Authentication is required to access this resource.',
                status: 401
            ));
        }

        parent::unauthenticated($request, $guards);
    }
}
