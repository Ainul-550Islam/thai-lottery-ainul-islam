<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

final class RobotsNoindexPolicy
{
    /**
     * Private, authenticated, auth-form, transactional, and admin surfaces must carry
     * `noindex, nofollow` so search crawlers index only public editorial and product pages.
     */
    public static function shouldNoindex(Request $request): bool
    {
        if ($request->is(
            'admin',
            'admin/*',
            'agent',
            'agent/*',
            'api/*',
            'player',
            'player/*',
            'member',
            'member/*',
            'wallet',
            'wallet/*',
            'dashboard',
            'dashboard/*',
            'bets',
            'bets/*',
            'betting',
            'betting/*',
            'profile',
            'profile/*',
            'notifications',
            'notifications/*',
            'deposit',
            'deposit/*',
            'withdrawal',
            'withdrawal/*',
            'support',
            'support/*',
            'login',
            'member/login',
            'register',
            'member/register',
            'forgot-password',
            'reset-password',
            'reset-password/*',
            'two-factor*',
            'payment/*',
        )) {
            return true;
        }

        return false;
    }
}
