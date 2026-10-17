<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\EnforcesAccountStatusForSession;
use App\Http\Middleware\Concerns\EnforcesPasswordSessionBinding;
use Filament\Http\Middleware\AuthenticateSession as FilamentAuthenticateSession;

/**
 * Filament panel session middleware with the same fail-closed binding.
 *
 * Filament's own AuthenticateSession only overrides the unauthenticated
 * redirect (to the panel login); it inherits the stock adoption of
 * unbound legacy sessions. This subclass keeps Filament's redirect and
 * adds the EnforcesPasswordSessionBinding pre-check, so an admin
 * session without a password binding is retired exactly like a member
 * one. Wired in AdminPanelProvider in place of the Filament class.
 *
 * Part 60 adds the per-request account-status gate here as well. Measured
 * behaviour, not an assumption: on a panel request Filament's own
 * `Filament\Http\Middleware\Authenticate` aborts 403 on
 * `canAccessPanel()` before this class runs, so a suspended operator is refused
 * by the panel and the retirement itself happens on their next member route.
 */
final class FilamentAuthenticateBoundSession extends FilamentAuthenticateSession
{
    use EnforcesAccountStatusForSession;
    use EnforcesPasswordSessionBinding;
}
