<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Currency;
use App\Exceptions\FinancialException;
use App\Services\Finance\LedgerEntryMoneyPreflight;
use App\Services\Finance\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure monetary encoding, without Laravel boot, database affinity or I/O. */
final class LedgerEntryMoneyPreflightTest extends TestCase
{
    public static function validAmounts(): array
    {
        $cases = [];
        foreach ([Currency::THB, Currency::BDT] as $currency) {
            foreach ([['0.01', 2, '0.01'], ['+00010.00', 2, '10.00'], ['999999999999999999.99', 2, '999999999999999999.99'], ['10.000', 3, '10.00'], ['0.010000000000', 12, '0.01']] as $i => $row) {
                $cases[$currency->value.'-'.$i] = [$currency, ...$row];
            }
        }

        return $cases;
    }

    #[DataProvider('validAmounts')]
    public function test_amounts_are_canonicalised_without_mutating_the_input_money(Currency $currency, string $raw, int $scale, string $expected): void
    {
        $money = Money::of($raw, $currency, $scale);
        $before = $money->toString();
        $plan = (new LedgerEntryMoneyPreflight)->prepare([['amount' => $money]], [Money::of('7.25', $currency)], [], false);
        $this->assertSame([['amount' => $expected, 'balance_after' => '7.25']], $plan);
        $this->assertSame($before, $money->toString());
        $this->assertSame($scale, $money->scale());
    }

    public static function invalidAmounts(): array
    {
        $cases = [];
        foreach ([Currency::THB, Currency::BDT] as $currency) {
            foreach ([['1000000000000000000.00', 2, 'out_of_range'], ['0.001', 3, 'invalid'], ['0.009', 3, 'invalid'], ['10.001', 3, 'invalid']] as $i => $row) {
                $cases[$currency->value.'-'.$i] = [$currency, ...$row];
            }
        }

        return $cases;
    }

    #[DataProvider('invalidAmounts')]
    public function test_storage_range_and_custom_money_precision_are_checked(Currency $currency, string $raw, int $scale, string $suffix): void
    {
        $money = Money::of($raw, $currency, $scale);
        try {
            (new LedgerEntryMoneyPreflight)->prepare([['amount' => $money]], [Money::of('7.25', $currency)], [], false);
            $this->fail('A lossy or unrepresentable amount was admitted.');
        } catch (FinancialException $error) {
            $this->assertSame('ledger_entry_amount_'.$suffix, $error->errorCode());
            $this->assertSame(0, $error->contextValue('entry_index'));
            $this->assertSame('amount', $error->contextValue('field'));
            if ($suffix === 'invalid') {
                $this->assertSame('money_precision_loss', $error->contextValue('money_error'));
                $this->assertInstanceOf(FinancialException::class, $error->getPrevious());
            }
            $this->assertSame($raw, $money->toString());
        }
    }

    public static function invalidSnapshots(): array
    {
        return [[1], [1.25], [true], [false], [[]], [new \stdClass], [''], ['1e2'], ['0.001'], ['NaN'], ['Infinity'], ['10000000000000000000000.00'], ['-10000000000000000000000.00'], ["\xff"]];
    }

    #[DataProvider('invalidSnapshots')]
    public function test_selected_snapshot_types_exactness_and_range_are_checked(mixed $snapshot): void
    {
        try {
            (new LedgerEntryMoneyPreflight)->prepare([['amount' => Money::of('10.00', Currency::THB)]], [Money::of('7.25', Currency::THB)], [$snapshot], true);
            $this->fail('An invalid snapshot was admitted.');
        } catch (FinancialException $error) {
            $range = is_string($snapshot) && in_array($snapshot, ['10000000000000000000000.00', '-10000000000000000000000.00'], true);
            $this->assertSame('ledger_entry_balance_after_'.($range ? 'out_of_range' : 'invalid'), $error->errorCode());
            $this->assertSame(0, $error->contextValue('entry_index'));
            $this->assertSame('balance_after', $error->contextValue('field'));
            $this->assertSame('THB', $error->contextValue('currency'));
        }
    }

    public static function validSnapshots(): array
    {
        return [['0.00', '0.00'], ['-0.00', '0.00'], [' +000123.4500 ', '123.45'], ['9999999999999999999999.99', '9999999999999999999999.99'], ['-9999999999999999999999.99', '-9999999999999999999999.99'], ['-12.25', '-12.25'], ['0.010000000000', '0.01'], ['1', '1.00']];
    }

    #[DataProvider('validSnapshots')]
    public function test_signed_snapshot_boundaries_and_lossless_forms_are_canonical(string $raw, string $expected): void
    {
        $plan = (new LedgerEntryMoneyPreflight)->prepare([['amount' => Money::of('10.00', Currency::THB)]], [Money::of('7.25', Currency::THB)], [$raw], true);
        $this->assertSame('10.00', $plan[0]['amount']);
        $this->assertSame($expected, $plan[0]['balance_after']);
    }

    public static function nullModes(): array
    {
        return [[false, false], [false, true], [true, false], [true, true]];
    }

    #[DataProvider('nullModes')]
    public function test_explicit_system_null_and_legacy_fallback_remain_distinct(bool $explicit, bool $present): void
    {
        $plan = (new LedgerEntryMoneyPreflight)->prepare([['amount' => Money::of('10.00', Currency::THB)]], [Money::of('-7.25', Currency::THB)], $present ? [null] : [], $explicit);
        $this->assertSame($explicit && $present ? null : '-7.25', $plan[0]['balance_after']);
    }

    public static function lateFields(): array
    {
        return [['amount'], ['balance_after']];
    }

    #[DataProvider('lateFields')]
    public function test_later_invalid_field_refuses_the_whole_plan_without_changing_inputs(string $field): void
    {
        $good = Money::of('10.00', Currency::THB);
        $entries = [['amount' => $good], ['amount' => $field === 'amount' ? Money::of('0.009', Currency::THB, 3) : $good]];
        $overrides = $field === 'balance_after' ? [1 => 1.5] : [];
        $before = $entries;
        try {
            (new LedgerEntryMoneyPreflight)->prepare($entries, [$good, $good], $overrides, false);
            $this->fail('A later invalid field was admitted.');
        } catch (FinancialException $error) {
            $this->assertSame('ledger_entry_'.$field.'_invalid', $error->errorCode());
            $this->assertSame(1, $error->contextValue('entry_index'));
            $this->assertSame($before, $entries);
        }
    }

    public static function overrideModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('overrideModes')]
    public function test_unused_snapshot_keys_remain_ignored(bool $explicit): void
    {
        $plan = (new LedgerEntryMoneyPreflight)->prepare([['amount' => Money::of('10.00', Currency::THB)]], [Money::of('7.25', Currency::THB)], [10 => ['unused invalid value']], $explicit);
        $this->assertSame([['amount' => '10.00', 'balance_after' => '7.25']], $plan);
    }
}
