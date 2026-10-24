<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinancialTransactionType;
use App\Enums\PayoutBatchStatus;
use App\Enums\PayoutStatus;
use App\Enums\PrizePayoutMethod;
use App\Events\PrizePayoutCompleted;
use App\Models\Payment;
use App\Models\Payout;
use App\Services\Observability\CorrelationContext;
use Illuminate\Support\Facades\DB;

/** Wallet-only canonical executor. Uncertain/unauthorized work is held, never stamped as a final failure. */
final class ApprovedWalletPayoutService
{
    public function execute(int $payoutId): array
    {
        if (config('finance.prize_payout.safety_mode') !== 'LIVE') {
            app(PayoutBatchMembershipService::class)->refuse();
        }
        $route = Payout::query()->findOrFail($payoutId);

        return DB::transaction(function () use ($route): array {
            // Consistent finance lock order: wallet before payout, not the old inverse.
            $wallet = app(WalletLockService::class)->lock((int) $route->wallet_id);
            $row = Payout::query()->lockForUpdate()->findOrFail($route->id);
            $guard = app(PayoutBatchMembershipService::class);
            $batch = $guard->assertMember($row);
            if (app(PayoutApprovalGuard::class)->method($row) !== PrizePayoutMethod::Wallet
                || (int) $row->wallet_id !== (int) $wallet->id || (int) $row->user_id !== (int) $wallet->user_id
                || $row->currency !== $wallet->currency || array_key_exists('transfer', $row->metadata ?? [])
                || Payment::withTrashed()->where('payable_type', Payout::class)->where('payable_id', $row->id)->exists()) {
                $guard->refuse();
            }
            if ($row->status === PayoutStatus::Completed) {
                app(PayoutApprovalService::class)->complete($row);

                return ['result' => 'replayed', 'amount' => (string) $row->amount];
            }
            if ($row->status !== PayoutStatus::Pending || $row->financial_transaction_id !== null || $batch->status !== PayoutBatchStatus::Processing) {
                $guard->refuse();
            }
            app(PayoutApprovalGuard::class)->assertApproved($row);
            $tx = app(WalletService::class)->credit($wallet, Money::fromDatabase((string) $row->amount, $row->currency),
                FinancialTransactionType::Payout, 'approved-wallet-payout:'.$row->id,
                ['reference_type' => Payout::class, 'reference_id' => (int) $row->id,
                    'description' => 'Approved original wallet prize payout.', 'metadata' => ['payout_id' => (int) $row->id]]);
            $row->financial_transaction_id = $tx->id;
            $row->status = PayoutStatus::Completed;
            $row->processed_at = now();
            $row->save();
            app(PayoutApprovalService::class)->complete($row);
            app(\App\Services\Prize\PrizeClaimCompletionService::class)->complete($row);
            event(new PrizePayoutCompleted($row, $row->user, CorrelationContext::get()));

            return ['result' => 'paid', 'amount' => (string) $row->amount];
        }, 3);
    }
}
