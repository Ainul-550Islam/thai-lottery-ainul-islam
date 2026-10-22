<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use LogicException;

/** Safely retire the browser session when an operator changes its own password. */
final class PasswordSessionBoundary
{
    private const REQUEST_ATTRIBUTE = 'security.password_change.boundary_user_id';

    public function __construct(private readonly DatabaseManager $database) {}

    /** Refuse an enclosing transaction so logout can never happen before commit. */
    public function prepare(Request $request, User $target): void
    {
        if (! $target->exists || $target->getKey() === null) {
            throw new LogicException('Password session boundary requires a persisted account.');
        }
        if ($this->database->connection()->transactionLevel() > 0) {
            throw new LogicException('Password changes cannot be nested in an outer transaction.');
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, (int) $target->getKey());
    }

    /** Invalidate the current session only after the credential transaction succeeds. */
    public function finish(Request $request, User $target): void
    {
        $boundUserId = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        $request->attributes->remove(self::REQUEST_ATTRIBUTE);
        if (! is_int($boundUserId) || $boundUserId !== (int) $target->getKey()) {
            throw new LogicException('Password session boundary does not match the prepared account.');
        }
        if (! $request->hasSession() || (int) $request->user()?->getAuthIdentifier() !== $boundUserId) {
            return;
        }

        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
