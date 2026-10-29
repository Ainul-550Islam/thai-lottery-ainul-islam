<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Exceptions\FinancialException;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Services\Ledger\DoubleEntryLedgerService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Tests standalone commits, without a RefreshDatabase wrapping transaction. */
final class DoubleEntryStandaloneAtomicityTest extends TestCase
{
    use DatabaseTruncation;

    public function test_inactive_second_account_cannot_leave_a_header_first_entry_or_changed_balance(): void
    {
        self::assertSame(0, DB::transactionLevel());
        $ledger = app(DoubleEntryLedgerService::class);
        $active = $ledger->getSystemAccount('ATOMIC-THB-ASSET', 'Active asset', 'asset', 'THB');
        $inactive = $ledger->getSystemAccount('ATOMIC-THB-LIABILITY', 'Inactive liability', 'liability', 'THB');
        $inactive->update(['is_active' => false]);
        $before = $this->counts();
        $balance = $active->fresh()->current_balance;

        $error = null;
        try {
            $ledger->postTransaction('ATOMIC_TEST', 'atomic-1', 'THB', [
                ['account_id' => $active->id, 'direction' => 'DEBIT', 'amount' => '12.50'],
                ['account_id' => $inactive->id, 'direction' => 'CREDIT', 'amount' => '12.50'],
            ]);
        } catch (\RuntimeException $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(\RuntimeException::class, $error, 'Inactive account was accepted.');
        self::assertStringContainsString('inactive', $error->getMessage());

        self::assertSame(0, DB::transactionLevel());
        self::assertSame($before, $this->counts());
        self::assertSame($balance, $active->fresh()->current_balance);
    }

    public function test_caught_nested_failure_rolls_back_to_a_savepoint_without_aborting_the_outer_transaction(): void
    {
        $ledger = app(DoubleEntryLedgerService::class);
        $asset = $ledger->getSystemAccount('ATOMIC-THB-ASSET', 'Asset', 'asset', 'THB');
        $liability = $ledger->getSystemAccount('ATOMIC-THB-LIABILITY', 'Liability', 'liability', 'THB');
        $inactive = $ledger->getSystemAccount('ATOMIC-THB-INACTIVE', 'Inactive', 'liability', 'THB');
        $inactive->update(['is_active' => false]);
        $before = $this->counts();

        DB::transaction(function () use ($ledger, $asset, $liability, $inactive, $before): void {
            $asset->update(['name' => 'Parent change retained']);
            $error = null;
            try {
                $ledger->postTransaction('NESTED_TEST', 'bad', 'THB', [
                    ['account_id' => $asset->id, 'direction' => 'DEBIT', 'amount' => '12.50'],
                    ['account_id' => $inactive->id, 'direction' => 'CREDIT', 'amount' => '12.50'],
                ]);
            } catch (\RuntimeException $caught) {
                $error = $caught;
            }
            self::assertInstanceOf(\RuntimeException::class, $error, 'Inactive account was accepted.');
            self::assertStringContainsString('inactive', $error->getMessage());
            self::assertSame(1, DB::transactionLevel());
            self::assertSame($before, $this->counts());
            $ledger->postTransaction('NESTED_TEST', 'good', 'THB', [
                ['account_id' => $asset->id, 'direction' => 'DEBIT', 'amount' => '3.25'],
                ['account_id' => $liability->id, 'direction' => 'CREDIT', 'amount' => '3.25'],
            ]);
        });

        self::assertSame(0, DB::transactionLevel());
        self::assertSame('Parent change retained', $asset->fresh()->name);
        self::assertSame('3.25', $asset->fresh()->current_balance);
        self::assertSame('3.25', $liability->fresh()->current_balance);
        self::assertSame($before['headers'] + 1, LedgerTransaction::query()->count());
        self::assertSame($before['entries'] + 2, LedgerEntry::query()->count());
    }

    public function test_account_currency_must_match_the_transaction_currency(): void
    {
        $ledger = app(DoubleEntryLedgerService::class);
        $thb = $ledger->getSystemAccount('ATOMIC-THB-ASSET', 'THB asset', 'asset', 'THB');
        $usd = $ledger->getSystemAccount('ATOMIC-USD-LIABILITY', 'USD liability', 'liability', 'USD');
        $before = $this->counts();

        $error = null;
        try {
            $ledger->postTransaction('CURRENCY_TEST', 'currency-1', 'THB', [
                ['account_id' => $thb->id, 'direction' => 'DEBIT', 'amount' => '12.50'],
                ['account_id' => $usd->id, 'direction' => 'CREDIT', 'amount' => '12.50'],
            ]);
        } catch (\RuntimeException $caught) {
            $error = $caught;
        }
        self::assertInstanceOf(\RuntimeException::class, $error, 'A USD account was accepted into a THB transaction.');
        self::assertStringContainsString('currency', strtolower($error->getMessage()));
        self::assertSame($before, $this->counts());
        self::assertSame('0.00', $thb->fresh()->current_balance);
        self::assertSame('0.00', $usd->fresh()->current_balance);
    }

    public function test_fractional_minor_units_are_rejected_before_any_posting(): void
    {
        $ledger = app(DoubleEntryLedgerService::class);
        $asset = $ledger->getSystemAccount('ATOMIC-THB-ASSET', 'THB asset', 'asset', 'THB');
        $liability = $ledger->getSystemAccount('ATOMIC-THB-LIABILITY', 'THB liability', 'liability', 'THB');
        $before = $this->counts();

        try {
            $ledger->postTransaction('PRECISION_TEST', 'precision-1', 'THB', [
                ['account_id' => $asset->id, 'direction' => 'DEBIT', 'amount' => '1.0050'],
                ['account_id' => $liability->id, 'direction' => 'CREDIT', 'amount' => '1.0050'],
            ]);
            self::fail('A fractional minor unit was silently truncated.');
        } catch (FinancialException $error) {
            self::assertSame('money_precision_loss', $error->errorCode());
        }
        self::assertSame($before, $this->counts());
        self::assertSame('0.00', $asset->fresh()->current_balance);
        self::assertSame('0.00', $liability->fresh()->current_balance);
    }

    /** @return array{headers:int, transactions:int, entries:int} */
    private function counts(): array
    {
        return [
            'headers' => LedgerTransaction::query()->count(),
            'transactions' => DB::table('financial_transactions')->count(),
            'entries' => LedgerEntry::query()->count(),
        ];
    }
}
