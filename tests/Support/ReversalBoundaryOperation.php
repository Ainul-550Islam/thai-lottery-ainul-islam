<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;

/** Actual canonical originals and service calls; funding balances are explicit fixtures. */
final class ReversalBoundaryOperation
{
    public static function fixture(string $kind, bool $replayed = false, Currency $currency = Currency::THB): array
    {
        $user = User::factory()->create();
        $wallets = app(WalletService::class);
        $wallet = $wallets->getOrCreateWallet($user, $currency);
        $wallet->forceFill(['balance' => $kind === 'wallet-debit' ? '1100.00' : '900.00'])->save();
        $money = Money::of('100.00', $currency);
        $key = 'synthetic-reversal-boundary-'.bin2hex(random_bytes(12));
        if ($kind === 'system') {
            $original = DB::transaction(function () use ($money, $key, $currency): FinancialTransaction {
                $row = FinancialTransaction::factory()->ofType(TransactionType::Settlement)->create([
                    'wallet_id' => null, 'user_id' => null, 'amount' => '100.00', 'fee' => '0.00',
                    'currency' => $currency, 'metadata' => ['engine_type' => 'settlement'], 'idempotency_key' => $key,
                ]);
                app(LedgerPostingService::class)->post($row, [
                    ['ledger_account_id' => LedgerAccount::query()->where('code', LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_SYSTEM_CASH, $currency))->sole()->id,
                        'type' => LedgerEntryType::Debit, 'amount' => $money],
                    ['ledger_account_id' => LedgerAccount::query()->where('code', LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_ADJUSTMENT_EQUITY, $currency))->sole()->id,
                        'type' => LedgerEntryType::Credit, 'amount' => $money],
                ]);
                $row->status = TransactionStatus::Completed;
                $row->processed_at = now();
                $row->save();

                return $row;
            });
        } else {
            $original = $kind === 'wallet-debit'
                ? $wallets->debit($wallet, $money, FinancialTransactionType::Withdrawal, $key)
                : $wallets->credit($wallet, $money, FinancialTransactionType::Deposit, $key);
        }
        $compensation = $replayed ? app(FinancialReversalService::class)->reverse($original) : null;

        return compact('user', 'wallet', 'original', 'compensation', 'kind');
    }

    public static function run(string $mode, FinancialTransaction $original): ?FinancialTransaction
    {
        return match ($mode) {
            'reverse-new', 'reverse-replay' => app(FinancialReversalService::class)->reverse($original),
            'existing-empty', 'existing-replay' => app(FinancialReversalService::class)->existingReversalFor($original),
            default => throw new \LogicException('Unknown synthetic reversal boundary operation.'),
        };
    }

    public static function paper(): array
    {
        $out = [];
        foreach (['wallets', 'financial_transactions', 'ledger_entries', 'ledger_accounts', 'audit_logs'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $out;
    }

    public static function snapshot(int $walletId, int $originalId): array
    {
        return [
            'balance' => bcadd((string) DB::table('wallets')->where('id', $walletId)->value('balance'), '0', 2),
            'status' => DB::table('financial_transactions')->where('id', $originalId)->value('status'),
            'compensations' => DB::table('financial_transactions')->where('reference_type', FinancialTransaction::class)->where('reference_id', $originalId)->count(),
        ];
    }
}
