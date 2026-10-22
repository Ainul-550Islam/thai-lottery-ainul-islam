<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\Enums\Currency;
use App\Exceptions\BetPurchaseException;
use App\Exceptions\FinancialException;
use App\Models\User;
use App\Services\Payment\StripeRefundRecoveryService;

/** New paid wagers only. A pending proved refund is not spendable wagering authority. */
final class StripeRefundWagerGuard
{
    /** Early source check: no chart locks before the canonical risk lock order. */
    public function assertNoPendingExposure(User $player, Currency $currency): void
    {
        $this->check($player, $currency, false);
    }

    /** Final authority, after risk locks and before any new bet/debit. */
    public function assertAllowed(User $player, Currency $currency): void
    {
        $this->check($player, $currency, true);
    }

    private function check(User $player, Currency $currency, bool $proveClosure): void
    {
        try {
            $pending = app(StripeRefundRecoveryService::class)->hasWagerExposureInTransaction($player, $currency, $proveClosure);
        } catch (FinancialException $e) {
            throw BetPurchaseException::withCode('bet_purchase_refund_exposure_unproven',
                'New paid wagers require review of the existing refunded-deposit recovery evidence.',
                ['user_id' => (int) $player->id, 'currency' => $currency->value, 'source_code' => $e->errorCode()], $e);
        }
        if ($pending) {
            throw BetPurchaseException::withCode('bet_purchase_refund_exposure_pending',
                'New paid wagers in this currency are unavailable while a confirmed deposit refund remains unrecovered.',
                ['user_id' => (int) $player->id, 'currency' => $currency->value]);
        }
    }
}
