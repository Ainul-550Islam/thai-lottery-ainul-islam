<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\LedgerEntryType;
use App\Exceptions\FinancialException;
use App\Models\LedgerAccount;

/** Pure projection from admitted, locked accounts; no model mutation or I/O. */
final class LedgerAccountBalanceProjector
{
    // Both ledger_accounts.current_balance and ledger_entries.balance_after
    // are signed DECIMAL(24,2): 22 integer digits and two fractional digits.
    private const MAX_BALANCE = '9999999999999999999999.99';

    /**
     * Preserve input order and each intermediate balance, not just the final
     * net. Opposite sides on one account must use its earlier projected value.
     *
     * @param  array<int, LedgerAccount>  $accounts  keyed by admitted account id
     * @param  list<array{ledger_account_id: int, type: LedgerEntryType, amount: Money}>  $entries  normalised balanced drafts
     * @return list<Money> the checked account balance after each line
     */
    public function project(array $accounts, array $entries): array
    {
        $balances = [];
        $projected = [];

        foreach ($entries as $index => $entry) {
            $id = $entry['ledger_account_id'];
            $account = $accounts[$id];

            if (! isset($balances[$id])) {
                $balances[$id] = Money::fromDatabase((string) $account->current_balance, $account->currency);
                $this->assertFits($balances[$id], $id, $index, 'current');
            }

            $next = $account->type->increasesWith($entry['type'])
                ? $balances[$id]->plus($entry['amount'])
                : $balances[$id]->minus($entry['amount']);

            $this->assertFits($next, $id, $index, 'projected');
            $balances[$id] = $next;
            $projected[] = $next;
        }

        return $projected;
    }

    private function assertFits(Money $balance, int $accountId, int $index, string $phase): void
    {
        if ($balance->absolute()->isGreaterThan(Money::of(self::MAX_BALANCE, $balance->currency()))) {
            throw FinancialException::withCode(
                'ledger_account_balance_out_of_range',
                'A ledger account balance cannot be represented by the signed DECIMAL(24,2) book.',
                [
                    'ledger_account_id' => $accountId,
                    'entry_index' => $index,
                    'currency' => $balance->currency()->value,
                    'balance_phase' => $phase,
                ],
            );
        }
    }
}
