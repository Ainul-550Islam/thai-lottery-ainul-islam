<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\Enums\AuditAction;
use App\Enums\LedgerEntryType;
use App\Enums\PayoutStatus;
use App\Enums\RiskLevel;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Services\Finance\LedgerBalanceValidator;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;

/** Canonical financial completion is the only writer of a player's Paid claim and paid-window closure. */
final class PrizeClaimCompletionService
{
    public function complete(Payout $payout): bool
    {
        return DB::transaction(function () use ($payout): bool {
            $row = Payout::query()->lockForUpdate()->findOrFail($payout->id);
            $guard = app(PrizeClaimGuard::class);
            $this->assertEvidence($row);
            $tx = $row->financialTransaction;
            $m = $row->metadata ?? [];
            $claim = $m['claim'] ?? [];
            if (! is_array($claim) || ! array_key_exists('claimant_user_id', $claim)) {
                return false; // No invented player claim on an automatically settled obligation.
            }
            $guard->assertPayableClaim($row);
            if (($claim['status'] ?? null) === 'paid') {
                if ((int) ($claim['financial_transaction_id'] ?? 0) !== (int) $tx->id) {
                    $guard->refuse($row);
                }

                return false;
            }
            $claim['status'] = 'paid';
            $claim['paid_at'] = now()->toIso8601String();
            $claim['financial_transaction_id'] = (int) $tx->id;
            $m['claim'] = $claim;
            $window = $m['claim_window'] ?? [];
            if (! is_array($window) || ! $guard->hasWindowSnapshot($row)) {
                $guard->refuse($row);
            }
            $m['claim_window'] = array_merge($window, ['status' => 'closed', 'closed_at' => now()->toIso8601String(), 'closed_via' => 'canonical_payment']);
            $row->metadata = $m;
            $row->save();
            AuditLog::query()->create(['action' => AuditAction::Update, 'risk_level' => RiskLevel::High,
                'auditable_type' => Payout::class, 'auditable_id' => $row->id, 'description' => 'Player claim marked paid from exact canonical financial evidence.',
                'metadata' => ['payout_id' => (int) $row->id, 'financial_transaction_id' => (int) $tx->id]]);

            return true;
        }, 3);
    }

    /** Read-only proof validation is also required before serving a terminal Paid replay. */
    public function assertEvidence(Payout $row): void
    {
        $guard = app(PrizeClaimGuard::class);
        $tx = $row->financialTransaction;
        if ($row->status !== PayoutStatus::Completed || ! $tx || $tx->status !== TransactionStatus::Completed || $tx->type !== TransactionType::Payout
            || $tx->reference_type !== Payout::class || (int) $tx->reference_id !== (int) $row->id || (int) $tx->user_id !== (int) $row->user_id
            || $tx->currency !== $row->currency || bccomp((string) $tx->amount, (string) $row->amount, 2) !== 0
            || ($tx->wallet_id !== null && (int) $tx->wallet_id !== (int) $row->wallet_id)) {
            $guard->refuse($row);
        }
        app(LedgerBalanceValidator::class)->assertTransactionBalanced($tx);
        $entries = $tx->ledgerEntries()->with('ledgerAccount')->get();
        $debit = $entries->filter(fn ($e) => $e->type === LedgerEntryType::Debit);
        $credit = $entries->filter(fn ($e) => $e->type === LedgerEntryType::Credit);
        $expense = LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_PRIZE_EXPENSE, $row->currency);
        $source = LedgerPostingService::accountCodeFor($tx->wallet_id === null ? WalletService::ACCOUNT_SYSTEM_CASH : WalletService::ACCOUNT_PLAYER_LIABILITY, $row->currency);
        if ($entries->count() !== 2 || $debit->count() !== 1 || $credit->count() !== 1
            || $debit->first()->ledgerAccount?->code !== $expense || $credit->first()->ledgerAccount?->code !== $source
            || $entries->contains(fn ($e) => $e->currency !== $row->currency || bccomp((string) $e->amount, (string) $row->amount, 2) !== 0)) {
            $guard->refuse($row);
        }
    }
}
