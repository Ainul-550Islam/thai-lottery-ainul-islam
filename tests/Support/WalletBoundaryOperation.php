<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Models\Wallet;
use App\Services\Finance\FinancialTransactionService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;

/** Real service dispatch shared by single-process and isolated native probes. */
final class WalletBoundaryOperation
{
    public static function profiles(): array
    {
        $result = [];
        foreach (['lock', 'credit-lock', 'debit-lock', 'many', 'relock'] as $op) {
            $result[$op] = [$op, 'wallet_lock_outside_transaction'];
        }
        foreach (['apply-credit', 'apply-debit', 'reverse-credit', 'reverse-debit'] as $op) {
            $result[$op] = [$op, 'wallet_mutation_outside_transaction'];
        }
        $result['consume'] = ['consume', 'wallet_hold_outside_transaction'];
        foreach (['lock-funds', 'unlock-funds', 'lock-wallet', 'unlock-wallet', 'hold', 'reserve', 'release', 'release-if-held', 'credit', 'debit', 'execute'] as $op) {
            $result[$op] = [$op, 'wallet_transaction_state_invalid'];
        }

        return $result;
    }

    public static function run(string $operation, Wallet $wallet): mixed
    {
        $money = Money::of('30.00', $wallet->currency);
        $locks = app(WalletLockService::class);
        $wallets = app(WalletService::class);
        $holds = app(WalletHoldService::class);
        $key = 'synthetic-wallet-boundary-'.bin2hex(random_bytes(12));

        return match ($operation) {
            'lock' => $locks->lock($wallet->id),
            'credit-lock' => $locks->lockForCredit($wallet->id, $wallet->currency),
            'debit-lock' => $locks->lockForDebit($wallet->id, $wallet->currency),
            'many' => $locks->lockMany([$wallet->id, $wallet->id]),
            'relock' => $locks->relock($wallet),
            'apply-credit' => $wallets->applyCredit($wallet, $money, FinancialTransactionType::Deposit),
            'apply-debit' => $wallets->applyDebit($wallet, $money, FinancialTransactionType::Withdrawal),
            'reverse-credit' => $wallets->applyReversal($wallet, $money, LedgerEntryType::Credit),
            'reverse-debit' => $wallets->applyReversal($wallet, $money, LedgerEntryType::Debit),
            'consume' => $holds->consume($wallet, $money),
            'lock-funds' => $wallets->lockFunds($wallet, $money),
            'unlock-funds' => $wallets->unlockFunds($wallet, $money),
            'lock-wallet' => $wallets->lockWallet($wallet, 'synthetic boundary fixture'),
            'unlock-wallet' => $wallets->unlockWallet($wallet),
            'hold' => $holds->hold($wallet, $money),
            'reserve' => $holds->reserve($wallet, $money),
            'release' => $holds->release($wallet, $money),
            'release-if-held' => $holds->releaseIfHeld($wallet, $money),
            'credit' => $wallets->credit($wallet, $money, idempotencyKey: $key),
            'debit' => $wallets->debit($wallet, $money, idempotencyKey: $key),
            'execute' => app(FinancialTransactionService::class)->execute($wallet, $money,
                FinancialTransactionType::Deposit, LedgerEntryType::Credit, $key),
            default => throw new \LogicException('Unknown synthetic boundary operation.'),
        };
    }

    /** Entire monetary and audit rows, not just a balance-only checksum. */
    public static function paper(): array
    {
        $paper = [];
        foreach (['wallets', 'financial_transactions', 'ledger_entries', 'ledger_accounts', 'audit_logs'] as $table) {
            $paper[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $paper;
    }
}
