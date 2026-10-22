<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Authorization for private/presence broadcast channels. Only the owner may subscribe to
| their own user channel; a foreign caller yields false and the subscription is refused
| before any payload is delivered.
|
*/

Broadcast::channel('App.Models.User.{id}', function (User $user, string $id): bool {
    return (int) $user->getKey() === (int) $id;
});

/*
| The same ownership rule, spelled the way resources/js/echo.js subscribes.
|
| The player-facing events (WalletBalanceUpdated, BetPlaced, DepositStatusUpdated,
| WithdrawalStatusUpdated) each broadcast on PrivateChannel('user.{id}') as well as on
| PrivateChannel('App.Models.User.{id}'), and echo.js calls client.private(`user.${userId}`).
| A private channel with no callback here is refused outright - not "unauthenticated but
| allowed": BroadcastController authorizes by finding a matching callback, finds none, and
| every subscription got a 403 while the code on both ends looked complete. This is the
| second half of that contract: identical predicate, so neither name can drift open.
*/
Broadcast::channel('user.{id}', function (User $user, string $id): bool {
    return (int) $user->getKey() === (int) $id;
});
