<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Currency;
use App\Exceptions\FinancialException;

/** Pure complete monetary encoding plan; never let the store round a draft. */
final class LedgerEntryMoneyPreflight
{
    private const MAX_AMOUNT = '999999999999999999.99'; // DECIMAL(20,2)

    private const MAX_SNAPSHOT = '9999999999999999999999.99'; // DECIMAL(24,2)

    /**
     * Resolve exactly the snapshots the old writer selected, including explicit
     * system nulls and legacy null fallback. Unused override keys stay ignored.
     *
     * @param  list<array{amount: Money}>  $entries  fully normalised drafts
     * @param  list<Money>  $projectedBalances  already checked account plan
     * @param  array<int, mixed>  $balancesAfter  trusted caller snapshots
     * @return list<array{amount: string, balance_after: string|null}>
     */
    public function prepare(array $entries, array $projectedBalances, array $balancesAfter, bool $preserveExplicitNulls): array
    {
        $plan = [];

        foreach ($entries as $index => $entry) {
            $currency = $entry['amount']->currency();
            $amount = $this->exact($entry['amount']->toString(), $currency, $index, 'amount', self::MAX_AMOUNT);
            $snapshot = ($preserveExplicitNulls && array_key_exists($index, $balancesAfter))
                ? $balancesAfter[$index]
                : ($balancesAfter[$index] ?? $projectedBalances[$index]->toString());

            if ($snapshot !== null && ! is_string($snapshot)) {
                throw FinancialException::withCode(
                    'ledger_entry_balance_after_invalid',
                    'A selected ledger balance snapshot must be an exact decimal string or an explicit system null.',
                    ['entry_index' => $index, 'field' => 'balance_after', 'currency' => $currency->value],
                );
            }

            $plan[] = [
                'amount' => $amount,
                'balance_after' => $snapshot === null ? null : $this->exact($snapshot, $currency, $index, 'balance_after', self::MAX_SNAPSHOT),
            ];
        }

        return $plan;
    }

    private function exact(string $raw, Currency $currency, int $index, string $field, string $maximum): string
    {
        try {
            // Explicit column scale also checks a Money object created at a
            // custom finer scale. Trailing zeroes are safe; lost cents are not.
            $money = Money::of($raw, $currency, 2);
        } catch (FinancialException $error) {
            throw FinancialException::withCode(
                'ledger_entry_'.$field.'_invalid',
                'A ledger monetary field cannot be encoded as an exact two-decimal value.',
                ['entry_index' => $index, 'field' => $field, 'currency' => $currency->value, 'money_error' => $error->errorCode()],
                $error,
            );
        }

        if ($money->absolute()->isGreaterThan(Money::of($maximum, $currency, 2))) {
            throw FinancialException::withCode(
                'ledger_entry_'.$field.'_out_of_range',
                'A ledger monetary field exceeds its existing database column range.',
                ['entry_index' => $index, 'field' => $field, 'currency' => $currency->value],
            );
        }

        return $money->toString();
    }
}
