<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Currency;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Exceptions\FinancialException;
use App\Models\LedgerAccount;
use App\Services\Finance\LedgerAccountBalanceProjector;
use App\Services\Finance\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Exact 22-digit boundary arithmetic without SQLite numeric affinity or I/O. */
final class LedgerAccountBalanceProjectorTest extends TestCase
{
    private const MAX = '9999999999999999999999.99';

    private static function account(LedgerAccountType $type, string $balance, Currency $currency = Currency::THB): LedgerAccount
    {
        $account = new LedgerAccount;
        $account->setRawAttributes(['id' => 7, 'type' => $type->value, 'currency' => $currency->value, 'current_balance' => $balance], true);

        return $account;
    }

    private static function line(LedgerEntryType $side, string $amount, Currency $currency = Currency::THB): array
    {
        return ['ledger_account_id' => 7, 'type' => $side, 'amount' => Money::of($amount, $currency)];
    }

    public static function directions(): array
    {
        $cases = [];
        foreach (LedgerAccountType::cases() as $type) {
            foreach (LedgerEntryType::cases() as $side) {
                foreach ([Currency::THB, Currency::BDT] as $currency) {
                    $cases[$type->value.'-'.$side->value.'-'.$currency->value] = [$type, $side, $currency];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('directions')]
    public function test_all_account_categories_use_exact_normal_balance_direction_without_mutation(LedgerAccountType $type, LedgerEntryType $side, Currency $currency): void
    {
        $account = self::account($type, '-2.50', $currency);
        $before = $account->getAttributes();
        $projected = (new LedgerAccountBalanceProjector)->project([7 => $account], [self::line($side, '7.25', $currency)]);
        $this->assertCount(1, $projected);
        $this->assertSame($type->increasesWith($side) ? '4.75' : '-9.75', $projected[0]->toString());
        $this->assertSame($currency, $projected[0]->currency());
        $this->assertSame($before, $account->getAttributes());
        $this->assertFalse($account->isDirty());
    }

    public static function boundaries(): array
    {
        $cases = [];
        foreach (LedgerAccountType::cases() as $type) {
            foreach ([false, true] as $negative) {
                foreach ([false, true] as $cross) {
                    $cases[$type->value.'-'.($negative ? 'lower' : 'upper').'-'.($cross ? 'cross' : 'reach')] = [$type, $negative, $cross];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('boundaries')]
    public function test_signed_decimal_boundary_is_inclusive_and_one_cent_outside_is_refused(LedgerAccountType $type, bool $negative, bool $cross): void
    {
        $start = ($negative ? '-' : '').($cross ? self::MAX : '9999999999999999999999.98');
        $account = self::account($type, $start);
        $before = $account->getAttributes();
        $side = $negative ? $type->normalBalance()->opposite() : $type->normalBalance();
        try {
            $projected = (new LedgerAccountBalanceProjector)->project([7 => $account], [self::line($side, '0.01')]);
            $this->assertFalse($cross, 'An out-of-range balance was admitted.');
            $this->assertSame(($negative ? '-' : '').self::MAX, $projected[0]->toString());
        } catch (FinancialException $error) {
            $this->assertTrue($cross);
            $this->assertSame('ledger_account_balance_out_of_range', $error->errorCode());
            $this->assertSame(['ledger_account_id' => 7, 'entry_index' => 0, 'currency' => 'THB', 'balance_phase' => 'projected'], $error->context());
        } finally {
            $this->assertSame($before, $account->getAttributes());
            $this->assertFalse($account->isDirty());
        }
    }

    public static function intermediates(): array
    {
        $cases = [];
        foreach (LedgerAccountType::cases() as $type) {
            foreach ([false, true] as $overflow) {
                $cases[$type->value.'-'.($overflow ? 'transient-overflow' : 'legal-carry')] = [$type, $overflow];
            }
        }

        return $cases;
    }

    #[DataProvider('intermediates')]
    public function test_each_intermediate_uses_the_prior_projected_balance_even_when_final_net_is_zero(LedgerAccountType $type, bool $overflow): void
    {
        $start = $overflow ? self::MAX : '9999999999999999999999.98';
        $account = self::account($type, $start);
        $before = $account->getAttributes();
        $lines = [self::line($type->normalBalance(), '0.01'), self::line($type->normalBalance()->opposite(), '0.01')];
        try {
            $projected = (new LedgerAccountBalanceProjector)->project([7 => $account], $lines);
            $this->assertFalse($overflow, 'Final-net cancellation hid an unrepresentable intermediate.');
            $this->assertSame([self::MAX, $start], array_map(fn (Money $balance) => $balance->toString(), $projected));
        } catch (FinancialException $error) {
            $this->assertTrue($overflow);
            $this->assertSame('ledger_account_balance_out_of_range', $error->errorCode());
            $this->assertSame(0, $error->contextValue('entry_index'));
            $this->assertSame('projected', $error->contextValue('balance_phase'));
        } finally {
            $this->assertSame($before, $account->getAttributes());
        }
    }

    public static function invalidCurrentBalances(): array
    {
        return [['10000000000000000000000.00'], ['-10000000000000000000000.00']];
    }

    #[DataProvider('invalidCurrentBalances')]
    public function test_a_corrupt_out_of_range_current_balance_is_not_repaired_by_a_later_leg(string $start): void
    {
        $account = self::account(LedgerAccountType::Asset, $start);
        $before = $account->getAttributes();
        $side = str_starts_with($start, '-') ? LedgerEntryType::Debit : LedgerEntryType::Credit;
        try {
            (new LedgerAccountBalanceProjector)->project([7 => $account], [self::line($side, '1.00')]);
            $this->fail('A corrupt current balance was silently repaired.');
        } catch (FinancialException $error) {
            $this->assertSame('ledger_account_balance_out_of_range', $error->errorCode());
            $this->assertSame('current', $error->contextValue('balance_phase'));
            $this->assertSame($before, $account->getAttributes());
        }
    }
}
