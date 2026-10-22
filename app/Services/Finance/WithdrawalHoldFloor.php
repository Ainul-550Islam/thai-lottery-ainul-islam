<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\FinancialException;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/** Central mechanical minimum, not authority to settle, repair or release an obligation. */
final class WithdrawalHoldFloor
{
    /** Caller owns the actual wallet row lock. Never accept a request-supplied floor or exclusion. */
    public function assertCovered(Wallet $wallet, ?Money $proposedLocked = null): void
    {
        if (DB::transactionLevel() < 1) {
            $this->refuse();
        }
        // A locking/current read is essential: an ambient REPEATABLE READ
        // snapshot may predate the wallet lock and a newly approved owner.
        $total = '0.00';
        foreach (DB::table('withdrawal_reservations')->where('wallet_id', $wallet->id)->where('state', 'active')
            ->orderBy('id')->lockForUpdate()
            ->selectRaw('user_id, currency, financial_transaction_id, closed_at, CAST(amount AS CHAR) AS exact_amount')->cursor() as $owner) {
            if ((int) $owner->user_id !== (int) $wallet->user_id || $owner->currency !== $wallet->currency->value
                || $owner->financial_transaction_id !== null || $owner->closed_at !== null
                || preg_match('/^\d+(?:\.\d{1,2})?$/D', $owner->exact_amount) !== 1
                || bccomp($owner->exact_amount, '0', 2) <= 0) {
                $this->refuse();
            }
            $total = bcadd($total, $owner->exact_amount, 2);
        }
        $total = bcadd($total, app(WithdrawalInitialHoldService::class)->minimum($wallet), 2);
        // The floors ADD. Independent maximum checks could let one owner spend another's money.
        $total = bcadd($total, app(FinancialHoldOwnership::class)->minimum($wallet), 2);
        if (bccomp((string) $wallet->locked_balance, $total, 2) < 0
            || bccomp((string) $wallet->balance, (string) $wallet->locked_balance, 2) < 0
            || ($proposedLocked !== null && ($proposedLocked->currency() !== $wallet->currency
                || bccomp($proposedLocked->toString(), $total, 2) < 0))) {
            $this->refuse();
        }
    }

    private function refuse(): never
    {
        throw FinancialException::withCode('wallet_withdrawal_floor_conflict',
            'Wallet mutation would spend, release or conceal an incompatible active withdrawal or local financial-hold obligation. Preserve funds for controlled review.');
    }
}
