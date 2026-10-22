<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
/**
 * Brackets an admin password change so the session side effects cannot run early.
 *
 * prepare() runs before anything is mutated. It refuses to start inside an
 * enclosing database transaction: a change that is still uncommitted when the
 * session is torn down could be rolled back after the browser was logged out.
 *
 * finish() runs after the change has committed. When the operator edited their own
 * account, the current session no longer matches the stored credential, so it is
 * ended here. Other sessions of the target are retired by the credential
 * generation, when the schema records one. A session that is not the operator's
 * is never touched by this class.
 */
final class PasswordSessionBoundary
{
    public function prepare(Request $request, User $target): void
    {
        if (DB::transactionLevel() > 0) {
            throw new \RuntimeException(
                'A password change cannot start inside an enclosing database transaction.',
            );
        }
    }

    public function finish(Request $request, User $target): void
    {
        $operatorIsTarget = $request->user() !== null
            && (int) $request->user()->getAuthIdentifier() === (int) $target->getKey();

        if (! $operatorIsTarget) {
            return;
        }

        Auth::guard()->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
