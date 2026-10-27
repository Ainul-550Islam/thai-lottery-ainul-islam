<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Authorization for every private channel an event in app/Events publishes on.
| A private channel with no callback here is refused with a 403 by the
| broadcaster, which is the fail-closed default - but it also means the player
| it was meant for never receives anything, which is the state this lane was
| found in (events on `user.{id}` and `user.{id}.wallet`, callbacks only for
| `App.Models.User.{id}`).
|
| THE RULE IS THE SAME ON EVERY CHANNEL
| Only the owner may subscribe to a channel whose name carries their id, and
| only while their account is active - the same test the `active` middleware
| (App\Http\Middleware\EnsureUserIsActive) applies to every REST surface that
| can show money, so a suspended or banned account loses its socket the moment
| it loses its API. Guests never reach these callbacks: the /broadcasting/auth
| route runs under the `auth` + `auth.session` middleware (bootstrap/app.php).
|
| Every callback returns a plain bool and never throws: a malformed id or an
| unexpected model state is a refusal, not a 500 that leaks a stack trace to a
| socket client.
|
*/

$ownsChannel = static function (User $user, string $id): bool {
    try {
        if (! ctype_digit($id)) {
            return false;
        }

        if ((int) $user->getKey() !== (int) $id) {
            return false;
        }

        return $user->isActive();
    } catch (Throwable) {
        return false;
    }
};

// Laravel's conventional per-user channel (used by Notification broadcasts).
Broadcast::channel('App.Models.User.{id}', static function (User $user, string $id) use ($ownsChannel): bool {
    return $ownsChannel($user, $id);
});

// The money chain: wallet, bet, deposit, withdrawal and payout events.
Broadcast::channel('user.{id}', static function (User $user, string $id) use ($ownsChannel): bool {
    return $ownsChannel($user, $id);
});

// The scalar wallet lane name used by the finance services' sync events.
Broadcast::channel('user.{id}.wallet', static function (User $user, string $id) use ($ownsChannel): bool {
    return $ownsChannel($user, $id);
});
