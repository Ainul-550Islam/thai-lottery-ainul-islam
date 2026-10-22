<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\BetPurchaseContext;
use App\Exceptions\BetPurchaseException;
use App\Models\Wallet;
use App\Services\ResponsibleGaming\ResponsibleGamingEnforcementService;
use App\Services\ResponsibleGaming\ResponsibleGamingPlayerLock;

/** NEW paid wager authority, never a gate on historical funding replay or refunds. */
final class BetPurchaseAdmission
{
    public function assertAllowed(BetPurchaseContext $context, Wallet $wallet): void
    {
        // Wallet has already been locked by the purchase root. Policy writers never
        // acquire wallets, and must take this same player mutex before policy rows.
        $user = app(ResponsibleGamingPlayerLock::class)->lock($context->userId());
        if ($context->data->userId !== (int) $user->id || (int) $wallet->id !== $context->walletId()
            || (int) $wallet->user_id !== (int) $user->id || $wallet->currency !== $context->currency()) {
            throw BetPurchaseException::invariantViolated('The current purchase wallet does not belong to the player/currency.');
        }
        if (! $user->isActive()) {
            throw BetPurchaseException::userUnavailable((int) $user->id, 'the current account cannot place a wager');
        }
        app(StripeRefundWagerGuard::class)->assertNoPendingExposure($user, $wallet->currency);
        app(ResponsibleGamingEnforcementService::class)->assertBetAllowed(
            $user, $context->stakeMoney()->toString(), $wallet->currency->value,
        );
    }
}
