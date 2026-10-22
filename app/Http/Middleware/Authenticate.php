<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;
use Illuminate\Http\Request;

/**
 * Authentication Middleware with JSON and Active-User Defense.
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
