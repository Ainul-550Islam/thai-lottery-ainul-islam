<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The HTTP boundary around a password write.
 *
 * A password change owns its commit (the writer services run their own
 * transaction, for the same reason settlement chunks do). A caller sitting
 * inside an enclosing HTTP transaction would silently annex that commit:
 * the password, the session revocation and the audit row would all ride on
 * a transaction the caller might roll back AFTER the browser has been told
 * the password changed — or commit later than the response that logged the
 * browser out. prepare() therefore refuses enclosing transactions before
 * anything mutates.
 *
 * finish() is the post-commit half: when the account whose password changed
 * is the account holding THIS browser session (an operator editing their own
 * record, a member changing their own password), the current session must
 * die with the old credential. It is invalidated only after the write has
 * committed — logging the browser out pre-commit would strand the member if
 * the write then failed.
 */
final class PasswordSessionBoundary
{
    /**
     * Refuse to begin a password write inside a caller's transaction.
     */
    public function prepare(Request $request, User $target): void
    {
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException(
                'Refusing to change a password inside an enclosing transaction: the password write must own its commit.',
            );
        }
    }

    /**
     * After a committed password write, end the current browser session if
     * it belongs to the account whose credential changed.
     */
    public function finish(Request $request, User $target): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $current = Auth::guard('web')->user();

        if (! $current instanceof User || ! $current->is($target)) {
            return;
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
