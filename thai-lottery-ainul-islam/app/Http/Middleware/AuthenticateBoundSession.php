<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\EnforcesAccountStatusForSession;
use App\Http\Middleware\Concerns\EnforcesPasswordSessionBinding;
use Illuminate\Session\Middleware\AuthenticateSession;

/**
 * Web `auth.session` middleware that refuses unbound legacy sessions.
 *
 * Registered under the existing `auth.session` alias in bootstrap/app.php,
 * so every route Part 50 placed behind `auth.session` gains the
 * fail-closed legacy behaviour without a single route change. Bound
 * sessions keep the stock framework behaviour exactly (recaller check,
 * hash comparison, post-response binding refresh); only sessions with
 * NO password binding — and no validated recaller cookie — are logged
 * out instead of being silently adopted. See
 * EnforcesPasswordSessionBinding for the full rationale and rollout note.
 *
 * Part 60 adds the per-request account-status gate: an account that can no
 * longer log in is retired here too, so a status change does not depend on the
 * revocation that accompanies it having succeeded.
 */
final class AuthenticateBoundSession extends AuthenticateSession
{
    use EnforcesAccountStatusForSession;
    use EnforcesPasswordSessionBinding;
}
