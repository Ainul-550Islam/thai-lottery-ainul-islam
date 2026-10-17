<?php

declare(strict_types=1);

namespace App\Services\Ledger;

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
 * Implements strict, immutable, balanced double-entry accounting with cryptographic
 * SHA-256 tamper-evident hash chaining.
 */
class DoubleEntryLedgerService
{
    /**
     * Post a balanced ledger transaction containing multiple debit and credit entries.
     *
     * @param string $referenceType E.g., 'ORDER_PLACEMENT', 'PAYOUT', 'COMMISSION'
     * @param string $referenceId E.g., Order UUID or Payout UUID
     * @param string $currency E.g., 'THB'
     * @param array<int, array{account_id: string, direction: 'DEBIT'|'CREDIT', amount: string}> $entries
     * @param string|null $idempotencyKey
     * @param string|null $description
     * @return LedgerTransaction
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
            $amount = $entry['amount'];
            if (bccomp($amount, '0.0000', 4) <= 0) {
                throw new InvalidArgumentException("Entry amount must be strictly positive: {$amount}");
            }

            if ($entry['direction'] === 'DEBIT') {
                $totalDebit = bcadd($totalDebit, $amount, 4);
            } elseif ($entry['direction'] === 'CREDIT') {
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

        // 2. Fetch the previous transaction hash for cryptographic audit chain
        /** @var LedgerTransaction|null $latestTx */
        $latestTx = LedgerTransaction::orderBy('posted_at', 'desc')->lockForUpdate()->first();
        $previousHash = $latestTx?->hash ?? str_repeat('0', 64);

        $txUuid = Str::uuid()->toString();
        $txNumber = 'TX-' . date('YmdHis') . '-' . strtoupper(Str::random(8));

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

        // 4. Create LedgerTransaction header
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
            'posted_at' => now(),
        ]);

        // 5. Post Individual Entries and update account running balances
        $sequence = 1;
        foreach ($entries as $entryData) {
            /** @var LedgerAccount $account */
            $account = LedgerAccount::where('id', $entryData['account_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if (!$account->is_active) {
                throw new RuntimeException("Ledger account is inactive: {$account->account_number}");
            }

            // Normal balance calculation:
            // ASSET / EXPENSE: Increase with DEBIT (+), Decrease with CREDIT (-)
            // LIABILITY / EQUITY / REVENUE: Increase with CREDIT (+), Decrease with DEBIT (-)
            $isAssetOrExpense = in_array($account->type, ['ASSET', 'EXPENSE'], true);
            $isDebit = $entryData['direction'] === 'DEBIT';

            if ($isAssetOrExpense) {
                $newBalance = $isDebit
                    ? bcadd($account->current_balance, $entryData['amount'], 4)
                    : bcsub($account->current_balance, $entryData['amount'], 4);
            } else {
                $newBalance = $isDebit
                    ? bcsub($account->current_balance, $entryData['amount'], 4)
                    : bcadd($account->current_balance, $entryData['amount'], 4);
            }

            // Update account balance
            $account->current_balance = $newBalance;
            $account->save();

            // Create immutable entry
            LedgerEntry::create([
                'id' => Str::uuid()->toString(),
                'ledger_transaction_id' => $transaction->id,
                'ledger_account_id' => $account->id,
                'direction' => $entryData['direction'],
                'amount' => $entryData['amount'],
                'currency' => $currency,
                'balance_after' => $newBalance,
                'sequence' => $sequence++,
                'created_at' => now(),
            ]);
        }

        return $transaction;
    }

    /**
     * Get or create a user liability ledger account.
     */
    public function getOrCreateUserAccount(string $userId, string $currency = 'THB'): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            [
                'user_id' => $userId,
                'currency' => $currency,
            ],
            [
                'id' => Str::uuid()->toString(),
                'account_number' => "USR-LIAB-{$userId}-{$currency}",
                'name' => "User Liability Account [{$userId}]",
                'type' => 'LIABILITY',
                'current_balance' => '0.0000',
                'is_active' => true,
            ]
        );
    }

    /**
     * Get or create system revenue/pool account.
     */
    public function getSystemAccount(string $accountNumber, string $name, string $type, string $currency = 'THB'): LedgerAccount
    {
        return LedgerAccount::firstOrCreate(
            ['account_number' => $accountNumber],
            [
                'id' => Str::uuid()->toString(),
                'name' => $name,
                'type' => $type,
                'currency' => $currency,
                'current_balance' => '0.0000',
                'user_id' => null,
                'is_active' => true,
            ]
        );
    }
}
