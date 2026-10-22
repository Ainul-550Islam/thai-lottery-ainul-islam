<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\FinancialException;

/**
 * Compare only the locked financial inputs that authorized and projected a post.
 * This pure witness owns no locks, transaction, model, callback or database I/O.
 * Fresh current rows are supplied by the posting service after payload callbacks.
 */
final readonly class LedgerPostingInputWitness
{
    private const TRANSACTION_FIELDS = ['id', 'user_id', 'wallet_id', 'currency', 'amount', 'status', 'deleted_at'];

    private const ACCOUNT_FIELDS = ['id', 'type', 'currency', 'current_balance', 'is_active', 'deleted_at'];

    private function __construct(private array $transaction, private array $accounts) {}

    /** @param array<int, array<string, mixed>> $accounts */
    public static function capture(array $transaction, array $accounts): self
    {
        return new self(self::fields($transaction, self::TRANSACTION_FIELDS), self::accountFields($accounts));
    }

    /** @param array<int, array<string, mixed>> $accounts */
    public function assertUnchanged(?array $transaction, array $accounts): void
    {
        if ($transaction === null) {
            self::refuse('financial_transaction', 'row');
        }
        $this->compare($this->transaction, self::fields($transaction, self::TRANSACTION_FIELDS), 'financial_transaction');
        $current = self::accountFields($accounts);
        if (array_keys($this->accounts) !== array_keys($current)) {
            self::refuse('ledger_account', 'row_set');
        }
        foreach ($this->accounts as $id => $expected) {
            $this->compare($expected, $current[$id], 'ledger_account');
        }
    }

    private static function fields(array $row, array $fields): array
    {
        $selected = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $selected[$field] = $row[$field];
            }
        }

        return $selected;
    }

    private static function accountFields(array $accounts): array
    {
        $selected = [];
        foreach ($accounts as $id => $row) {
            $selected[$id] = self::fields($row, self::ACCOUNT_FIELDS);
        }
        ksort($selected, SORT_NUMERIC);

        return $selected;
    }

    private function compare(array $expected, array $current, string $entity): void
    {
        foreach (array_unique(array_merge(array_keys($expected), array_keys($current))) as $field) {
            if (array_key_exists($field, $expected) !== array_key_exists($field, $current)
                || ($expected[$field] ?? null) !== ($current[$field] ?? null)) {
                self::refuse($entity, $field);
            }
        }
    }

    private static function refuse(string $entity, string $field): never
    {
        throw FinancialException::withCode(
            'ledger_posting_inputs_changed',
            'The current financial inputs no longer match the admitted posting plan.',
            ['stage' => 'payload_preflight', 'entity' => $entity, 'field' => $field],
        );
    }
}
