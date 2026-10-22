<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\Payout;
use App\Services\Payment\PayoutTransferGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Book independently confirmed external prize disbursement; NEVER send money or credit a wallet. */
final class ExternalPayoutCompletionService
{
    public function post(Payout $row): FinancialTransaction
    {
        if (DB::transactionLevel() < 1 || $row->financial_transaction_id !== null) {
            app(PayoutTransferGuard::class)->refuse($row);
        }
        $ledger = app(LedgerPostingService::class);
        $expense = $ledger->resolveAccount(LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_PRIZE_EXPENSE, $row->currency));
        $cash = $ledger->resolveAccount(LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_SYSTEM_CASH, $row->currency));
        $tx = new FinancialTransaction;
        $tx->fill(['reference_number' => 'EXT-PRIZE-'.strtoupper(bin2hex(random_bytes(12))),
            'user_id' => $row->user_id, 'wallet_id' => null, 'type' => TransactionType::Payout,
            'currency' => $row->currency, 'amount' => (string) $row->amount, 'fee' => '0.00',
            'description' => 'Independently confirmed external prize transfer.',
            'idempotency_key' => hash('sha256', 'external-prize:'.$row->id),
            'reference_type' => Payout::class, 'reference_id' => (int) $row->id,
            'metadata' => ['external_payout' => true]]);
        $tx->uuid = (string) Str::uuid();
        $tx->status = TransactionStatus::Pending;
        $tx->save();
        $ledger->post($tx, [
            ['ledger_account_id' => (int) $expense->id, 'type' => LedgerEntryType::Debit, 'amount' => (string) $row->amount,
                'metadata' => ['purpose' => 'prize_payout', 'external_payout' => true]],
            ['ledger_account_id' => (int) $cash->id, 'type' => LedgerEntryType::Credit, 'amount' => (string) $row->amount,
                'metadata' => ['purpose' => 'prize_payout', 'external_payout' => true]],
        ]);
        $tx->status = TransactionStatus::Completed;
        $tx->processed_at = now();
        $tx->save();

        return $tx;
    }
}
