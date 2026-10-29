<?php

declare(strict_types=1);

namespace Tests\Unit\Ledger;

use App\Models\LedgerEntry;
use App\Services\Ledger\DoubleEntryLedgerService;
use App\Services\Ledger\LedgerReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CurrencySeparatedIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_opposite_currency_deltas_cannot_hide_as_a_globally_balanced_ledger(): void
    {
        [$thb, $usd] = $this->postBalancedCurrencies();
        $credit = LedgerEntry::query()->where('ledger_transaction_id', $thb->id)->where('direction', 'CREDIT')->sole();
        // Model a corrupt persisted row; the two-currency combined debit/credit
        // sums still agree, so a single global total would incorrectly go green.
        DB::table('ledger_entries')->where('id', $credit->id)->update(['currency' => 'USD']);

        $report = app(LedgerReconciliationService::class)->auditSystemIntegrity();
        self::assertSame('CORRUPTED', $report['status']);
        self::assertSame('100.0000', $report['by_currency']['THB']['delta']);
        self::assertSame('-100.0000', $report['by_currency']['USD']['delta']);
        self::assertNotEmpty($report['currency_identity_issues']);
        self::assertNull($report['total_debits'], 'Unrelated currencies cannot be shown as one monetary total.');
        self::assertNull($report['ledger_balance_delta']);
        self::assertNotSame($thb->id, $usd->id);
    }

    public function test_opposite_unbalanced_transactions_cannot_cancel_within_one_currency(): void
    {
        $ledger = app(DoubleEntryLedgerService::class);
        $asset = $ledger->getSystemAccount('AUDIT-ASSET-THB', 'Asset THB', 'asset', 'THB');
        $liability = $ledger->getSystemAccount('AUDIT-LIABILITY-THB', 'Liability THB', 'liability', 'THB');
        $transactions = [];
        foreach ([1, 2] as $index) {
            $transactions[] = $ledger->postTransaction('AUDIT_TRANSACTION_TEST', 'ref-'.$index, 'THB', [
                ['account_id' => $asset->id, 'direction' => 'DEBIT', 'amount' => '100.00'],
                ['account_id' => $liability->id, 'direction' => 'CREDIT', 'amount' => '100.00'],
            ], 'audit-transaction-'.$index);
        }
        DB::table('ledger_entries')->where('ledger_transaction_id', $transactions[0]->id)
            ->where('direction', 'CREDIT')->update(['amount' => '50.00']);
        DB::table('ledger_entries')->where('ledger_transaction_id', $transactions[1]->id)
            ->where('direction', 'DEBIT')->update(['amount' => '50.00']);

        $report = app(LedgerReconciliationService::class)->auditSystemIntegrity();
        self::assertSame('0.0000', $report['by_currency']['THB']['delta']);
        self::assertSame('CORRUPTED', $report['status']);
        self::assertCount(2, $report['transaction_balance_issues']);
    }

    public function test_balanced_relabelled_pair_still_fails_account_and_transaction_currency_identity(): void
    {
        [$thb] = $this->postBalancedCurrencies();
        DB::table('ledger_entries')->where('ledger_transaction_id', $thb->id)->update(['currency' => 'USD']);
        $report = app(LedgerReconciliationService::class)->auditSystemIntegrity();
        self::assertSame('CORRUPTED', $report['status']);
        self::assertSame('0.0000', $report['by_currency']['USD']['delta']);
        self::assertCount(2, $report['currency_identity_issues']);
    }

    public function test_liability_with_no_matching_wallet_is_not_counted_as_reconciled(): void
    {
        app(DoubleEntryLedgerService::class)->getOrCreateUserAccount('no-such-wallet-owner', 'BDT');
        $report = app(LedgerReconciliationService::class)->auditSystemIntegrity();
        self::assertSame('CORRUPTED', $report['status']);
        self::assertSame(0, $report['reconciled_accounts_count']);
        self::assertCount(1, $report['unmatched_user_accounts']);
        self::assertSame('BDT', $report['unmatched_user_accounts'][0]['currency']);
    }

    public function test_balanced_multiple_currencies_are_reported_separately(): void
    {
        $this->postBalancedCurrencies();
        $report = app(LedgerReconciliationService::class)->auditSystemIntegrity();
        self::assertSame('HEALTHY', $report['status']);
        self::assertSame('0.0000', $report['by_currency']['THB']['delta']);
        self::assertSame('0.0000', $report['by_currency']['USD']['delta']);
        self::assertSame('100.0000', $report['by_currency']['THB']['debits']);
        self::assertSame('100.0000', $report['by_currency']['USD']['credits']);
        self::assertNull($report['total_debits']);
        self::assertEmpty($report['currency_identity_issues']);
    }

    private function postBalancedCurrencies(): array
    {
        $ledger = app(DoubleEntryLedgerService::class);
        $result = [];
        foreach (['THB', 'USD'] as $code) {
            $asset = $ledger->getSystemAccount('AUDIT-ASSET-'.$code, 'Asset '.$code, 'asset', $code);
            $liability = $ledger->getSystemAccount('AUDIT-LIABILITY-'.$code, 'Liability '.$code, 'liability', $code);
            $result[] = $ledger->postTransaction('AUDIT_CURRENCY_TEST', 'ref-'.$code, $code, [
                ['account_id' => $asset->id, 'direction' => 'DEBIT', 'amount' => '100.00'],
                ['account_id' => $liability->id, 'direction' => 'CREDIT', 'amount' => '100.00'],
            ], 'audit-currency-'.$code);
        }

        return $result;
    }
}
