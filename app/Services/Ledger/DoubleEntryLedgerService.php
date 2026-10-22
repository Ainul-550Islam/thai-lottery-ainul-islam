<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Enums\LedgerAccountType;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Class DoubleEntryLedgerService
 *
 * Implements strict, immutable, balanced double-entry accounting aligned with both
 * the canonical 2024 `ledger_accounts` / `financial_transactions` / `ledger_entries`
 * schema AND the SHA-256 tamper-evident `ledger_transactions` hash chain.
 */
class DoubleEntryLedgerService
{
    /**
     * Post a balanced ledger transaction containing multiple debit and credit entries.
     *
     * @param  string  $referenceType  E.g., 'ORDER_PLACEMENT', 'PAYOUT', 'COMMISSION', 'DEPOSIT_SETTLEMENT'
     * @param  string  $referenceId  E.g., Order ID/UUID or Payout ID/UUID
     * @param  string  $currency  E.g., 'THB'
     * @param  array<int, array{account_id: int|string, direction: string, amount: string}>  $entries
     *
     * @throws InvalidArgumentException|RuntimeException
     */
    public function postTransaction(
        string $referenceType,
        string $referenceId,
        string $currency,
        array $entries,
        ?string $idempotencyKey = null,
        ?string $description = null
    ): LedgerTransaction {
        if (empty($entries)) {
            throw new InvalidArgumentException('Ledger transaction must contain at least two entries.');
        }

        // 1. Enforce Debit == Credit invariant using BCMath
        $totalDebit = '0.0000';
        $totalCredit = '0.0000';

        foreach ($entries as $entry) {
            $amount = (string) $entry['amount'];
            if (bccomp($amount, '0.0000', 4) <= 0) {
                throw new InvalidArgumentException("Entry amount must be strictly positive: {$amount}");
            }

            $dir = strtoupper((string) $entry['direction']);
            if ($dir === 'DEBIT') {
                $totalDebit = bcadd($totalDebit, $amount, 4);
            } elseif ($dir === 'CREDIT') {
                $totalCredit = bcadd($totalCredit, $amount, 4);
            } else {
                throw new InvalidArgumentException("Invalid entry direction: {$entry['direction']}");
            }
        }

        if (bccomp($totalDebit, $totalCredit, 4) !== 0) {
            throw new InvalidArgumentException(
                "Unbalanced double-entry transaction! Total Debit ({$totalDebit}) does not equal Total Credit ({$totalCredit})."
            );
        }

        // 2. Fetch the unspent chain tip for cryptographic audit chain (clock-independent across UUID PKs)
        /** @var LedgerTransaction|null $latestTx */
        $latestTx = LedgerTransaction::query()
            ->whereNotExists(static function ($query): void {
                $query->selectRaw('1')
                    ->from('ledger_transactions as child')
                    ->whereColumn('child.previous_hash', 'ledger_transactions.hash');
            })
            ->lockForUpdate()
            ->first();
        $previousHash = $latestTx?->hash ?? str_repeat('0', 64);

        $txUuid = Str::uuid()->toString();
        $txNumber = 'TX-'.date('YmdHis').'-'.strtoupper(Str::random(8));

        // 3. Compute current transaction cryptographic hash
        $rawPayloadToHash = implode('|', [
            $previousHash,
            $txNumber,
            $referenceType,
            $referenceId,
            $currency,
            $totalDebit,
            $idempotencyKey ?? '',
        ]);
        $currentHash = hash('sha256', $rawPayloadToHash);

        $now = now();

        // 4. Create LedgerTransaction header (SHA-256 chain)
        $transaction = LedgerTransaction::create([
            'id' => $txUuid,
            'transaction_number' => $txNumber,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'idempotency_key' => $idempotencyKey,
            'currency' => $currency,
            'total_amount' => $totalDebit,
            'description' => $description,
            'status' => 'POSTED',
            'previous_hash' => $previousHash,
            'hash' => $currentHash,
            'posted_at' => $now,
        ]);

        // 5. Create parent financial_transactions row required by ledger_entries FK
        $financialTxId = (int) DB::table('financial_transactions')->insertGetId([
            'uuid' => $txUuid,
            'reference_number' => $txNumber,
            'user_id' => null,
            'wallet_id' => null,
            'type' => strtolower($referenceType),
            'status' => 'completed',
            'currency' => $currency,
            'amount' => bcadd($totalDebit, '0', 2),
            'fee' => '0.00',
            'description' => $description,
            'idempotency_key' => $idempotencyKey !== null ? 'FT-'.substr(hash('sha256', $idempotencyKey), 0, 48) : null,
            'reference_type' => $referenceType,
            'reference_id' => ctype_digit($referenceId) ? (int) $referenceId : null,
            'processed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // 6. Post balanced entries and update running account balances
        foreach ($entries as $idx => $entryData) {
            /** @var LedgerAccount $account */
            $account = LedgerAccount::where('id', $entryData['account_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (! $account->is_active) {
                throw new RuntimeException("Ledger account is inactive: {$account->code}");
            }

            $accountTypeValue = $account->type instanceof LedgerAccountType
                ? $account->type->value
                : strtolower((string) $account->type);

            $isAssetOrExpense = in_array($accountTypeValue, ['asset', 'expense'], true);
            $isDebit = strtoupper((string) $entryData['direction']) === 'DEBIT';
            $entryAmount = bcadd((string) $entryData['amount'], '0', 2);
            $currentBalance = bcadd((string) ($account->current_balance ?? '0.00'), '0', 2);

            $newBalance = $isAssetOrExpense
                ? ($isDebit ? bcadd($currentBalance, $entryAmount, 2) : bcsub($currentBalance, $entryAmount, 2))
                : ($isDebit ? bcsub($currentBalance, $entryAmount, 2) : bcadd($currentBalance, $entryAmount, 2));

            $account->current_balance = $newBalance;
            $account->save();

            $entry = LedgerEntry::create([
                'ledger_transaction_id' => $txUuid,
                'account_id' => (string) $account->id,
                'direction' => $isDebit ? 'DEBIT' : 'CREDIT',
                'sequence' => $idx + 1,
                'ledger_account_id' => $account->id,
                'financial_transaction_id' => $financialTxId,
                'wallet_id' => isset($entryData['wallet_id']) ? (int) $entryData['wallet_id'] : null,
                'type' => $isDebit ? 'debit' : 'credit',
                'amount' => $entryAmount,
                'currency' => $currency,
                'description' => $description,
                'reference_type' => $referenceType,
                'reference_id' => ctype_digit($referenceId) ? (int) $referenceId : null,
                'posted_at' => $now,
            ]);
            $entry->balance_after = $newBalance;
            $entry->save();
        }

        return $transaction;
    }

    /**
     * Get or create a user liability ledger account (code bounded to <= 32 chars).
     */
    public function getOrCreateUserAccount(string $userId, string $currency = 'THB'): LedgerAccount
    {
        $rawCode = "USR-LIAB-{$userId}-{$currency}";
        $code = strlen($rawCode) <= 32
            ? $rawCode
            : ('UL-'.substr(hash('sha256', $userId), 0, 22).'-'.strtoupper($currency));

        return LedgerAccount::firstOrCreate(
            ['code' => $code],
            [
                'name' => "User Liability Account [{$userId}]",
                'type' => 'liability',
                'currency' => $currency,
                'is_active' => true,
                'metadata' => ['owner_user_id' => $userId],
            ]
        );
    }

    /**
     * Get or create system asset/revenue/expense account (code bounded to <= 32 chars).
     */
    public function getSystemAccount(string $accountNumber, string $name, string $type, string $currency = 'THB'): LedgerAccount
    {
        $code = strlen($accountNumber) <= 32
            ? $accountNumber
            : ('SYS-'.substr(hash('sha256', $accountNumber), 0, 21).'-'.strtoupper($currency));

        return LedgerAccount::firstOrCreate(
            ['code' => $code],
            [
                'name' => $name,
                'type' => strtolower($type),
                'currency' => $currency,
                'is_active' => true,
            ]
        );
    }
}
