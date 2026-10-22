<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\FinancialException;
use App\Exceptions\WithdrawalException;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payment\StripeRefundRecoveryService;
use Illuminate\Support\Facades\DB;

/** New request/reservation authority only; never a generic debit or settlement hook. */
final class StripeRefundWithdrawalGuard
{
    public function assertAllowed(Wallet $wallet): void
    {
        if (DB::transactionLevel() < 1 || ! DB::connection()->getPdo()->inTransaction()) {
            $this->refuse('withdrawal_refund_exposure_unproven');
        }
        // Reentrant SELECTED wallet -> player. Never acquire another original wallet.
        $current = Wallet::query()->whereKey($wallet->id)->lockForUpdate()->first();
        if (! $current || (int) $current->user_id !== (int) $wallet->user_id || $current->currency !== $wallet->currency) {
            $this->refuse('withdrawal_refund_exposure_unproven');
        }
        $player = User::query()->whereKey($current->user_id)->lockForUpdate()->first();
        if (! $player) {
            $this->refuse('withdrawal_refund_exposure_unproven');
        }
        try {
            $pending = app(StripeRefundRecoveryService::class)->hasRecoveryExposureInTransaction($player, $current->currency);
        } catch (FinancialException $e) {
            throw WithdrawalException::withCode('withdrawal_refund_exposure_unproven',
                'New withdrawals require review of the existing deposit-refund recovery evidence.',
                ['currency' => $current->currency->value, 'source_code' => $e->errorCode()], $e);
        }
        if ($pending) {
            $this->refuse('withdrawal_refund_exposure_pending');
        }
    }

    private function refuse(string $code): never
    {
        throw WithdrawalException::withCode($code,
            'New withdrawal requests or reservations require deposit-refund recovery review.');
    }
}
