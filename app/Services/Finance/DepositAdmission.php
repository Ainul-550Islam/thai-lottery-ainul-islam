<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\BetPurchaseException;
use App\Exceptions\DepositException;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ResponsibleGaming\ResponsibleGamingEnforcementService;
use App\Services\ResponsibleGaming\ResponsibleGamingPlayerLock;

/** Current NEW deposit intent/credit authority, not permission inferred from an old approval or callback. */
final class DepositAdmission
{
    /** Caller already owns the current wallet lock; coordinate across distinct player wallets before deposit rows. */
    public function lockPlayer(Wallet $wallet): User
    {
        try {
            return app(ResponsibleGamingPlayerLock::class)->lock((int) $wallet->user_id);
        } catch (BetPurchaseException $e) {
            throw DepositException::withCode('deposit_player_unavailable', 'The current deposit account or its transaction lock is unavailable.');
        }
    }

    public function assertAllowed(Wallet $wallet, string $grossAmount, ?int $replacingDepositId = null): void
    {
        $user = $this->lockPlayer($wallet);
        if (! $user->isActive()) {
            throw DepositException::withCode('deposit_player_unavailable', 'The current account cannot accept a new deposit.');
        }
        app(ResponsibleGamingEnforcementService::class)->assertDepositAllowed(
            $user, $grossAmount, $wallet->currency->value, $replacingDepositId,
        );
    }
}
