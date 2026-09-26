<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Support\Admin\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GLO-8 authorization middleware: default-deny for GLO mutation routes.
 *
 * The required permission arrives as a middleware parameter from routes/api.php:
 *   ->middleware('glo.permission:request glo freezes')
 *
 * A missing/empty parameter fails closed with 403 — a misconfigured route never
 * becomes world-writable. Permissions are checked against the seeded catalogue
 * via AdminAccess (super-admin passes through the existing Gate::before rule).
 */
class GloEnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission = ''): Response
    {
        $permission = trim($permission);

        if ($permission === '') {
            return ApiResponse::error(
                'glo_forbidden',
                'GLO permission is not configured for this route (default deny).',
                403,
            );
        }

        $user = $request->user();

        if (! AdminAccess::allows($user, $permission)) {
            return ApiResponse::error(
                'glo_forbidden',
                'You are not permitted to perform this GLO action.',
                403,
            );
        }

        return $next($request);
    }
}
