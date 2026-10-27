<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\DepositStatus;
use App\Enums\TransactionStatus;
use App\Enums\WithdrawalStatus;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\Withdrawal;
use App\Services\Finance\FinancialStateTransitionService;
use App\Services\Finance\FinancialTransactionService;
use Illuminate\Support\Facades\DB;

final class LifecycleBoundaryOperation
{
    public static function profiles(): array
    {
        return array_combine($p = ['deposit-lock', 'withdrawal-lock', 'transaction-lock', 'deposit-transition', 'withdrawal-transition', 'transaction-prepare', 'transaction-fail'], array_map(fn ($s) => [$s], $p));
    }

    public static function run(string $operation, Deposit $deposit, Withdrawal $withdrawal, FinancialTransaction $transaction): mixed
    {
        $states = app(FinancialStateTransitionService::class);
        $transactions = app(FinancialTransactionService::class);

        return match ($operation) {
            'deposit-lock' => $states->lockDeposit($deposit),
            'withdrawal-lock' => $states->lockWithdrawal($withdrawal),
            'transaction-lock' => $states->lockFinancialTransaction($transaction),
            'deposit-transition' => $states->transitionDeposit($deposit, DepositStatus::Cancelled),
            'withdrawal-transition' => $states->transitionWithdrawal($withdrawal, WithdrawalStatus::Cancelled),
            'transaction-prepare' => $transactions->transitionTo($transaction, TransactionStatus::Failed),
            'transaction-fail' => $transactions->markFailed($transaction, 'synthetic boundary failure'),
            default => throw new \LogicException('Unknown lifecycle boundary operation.'),
        };
    }

    public static function paper(): array
    {
        $result = [];
        foreach (['deposits', 'withdrawals', 'financial_transactions', 'deposit_checkout_attempts', 'wallets', 'ledger_entries'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $result;
    }
}
