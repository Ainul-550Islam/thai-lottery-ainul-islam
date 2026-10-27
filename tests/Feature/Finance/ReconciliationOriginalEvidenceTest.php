<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Enums\DiscrepancyCategory;
use App\Enums\PaymentMethod;
use App\Enums\ReconciliationStatus;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Services\Finance\DepositApprovalService;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\DepositService;
use App\Services\Finance\ExactReconciliationReader;
use App\Services\Finance\FinancialReconciliationService;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\LedgerReconciliationService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use App\Services\Payment\DepositProviderAccountService;
use App\Services\Payment\FinancialProviderReferenceService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Payment\PaymentTestCase;
use Tests\Support\InjectsHistoricalAccountingFaults;

final class ReconciliationOriginalEvidenceTest extends PaymentTestCase
{
    use InjectsHistoricalAccountingFaults;

    private function paid(Currency $currency = Currency::THB): array
    {
        $f = $this->createPlayer('0.00', $currency);
        $f['row'] = app(DepositService::class)->request($f['wallet'], Money::of('400.00', $currency), PaymentMethod::BankTransfer);
        app(DepositApprovalService::class)->approve($f['row']);
        app(DepositCompletionService::class)->complete($f['row'], options: ['provider_reference' => 'SYNTHETIC-PRIVATE-'.bin2hex(random_bytes(8))]);
        $f['row']->refresh();

        return $f;
    }

    public static function currencies(): array
    {
        return [[Currency::THB], [Currency::USD], [Currency::BDT]];
    }

    #[DataProvider('currencies')]
    public function test_real_original_deposit_and_currency_specific_wallet_lane_are_proved(Currency $currency): void
    {
        $f = $this->paid($currency);
        $report = app(FinancialReconciliationService::class)->reconcile(currency: $currency);
        $this->assertSame(ReconciliationStatus::Pass, $report->status);
        $this->assertSame('400.00', $report->totalDeposits);
        $this->assertSame('400.00', $report->totalLedgerCredits);
        $conversation = app(LedgerReconciliationService::class)->reconcileWallet($f['wallet']->id);
        $this->assertSame('400.00', $conversation->ledger_aggregate);
        $this->assertSame('matched', $conversation->status->value);
        $this->assertSame('400.00', $f['wallet']->fresh()->balance);
    }

    public static function proofCorruptions(): array
    {
        return array_map(fn ($s) => [$s], ['foreign_fk', 'missing_owner', 'wrong_account', 'deleted_journal', 'changed_terms', 'reversed_tx']);
    }

    #[DataProvider('proofCorruptions')]
    public function test_balanced_or_marked_history_never_substitutes_for_original_proof_and_audit_repairs_nothing(string $kind): void
    {
        $f = $this->paid();
        $row = $f['row'];
        if ($kind === 'foreign_fk') {
            $other = $this->paid();
            $row->financial_transaction_id = $other['row']->financial_transaction_id;
            $row->save();
        } elseif ($kind === 'missing_owner') {
            DB::table(FinancialProviderReferenceService::TABLE)->delete();
        } elseif ($kind === 'wrong_account') {
            $account = LedgerAccount::query()->where('code', WalletService::ACCOUNT_BET_REVENUE)->firstOrFail();
            LedgerEntry::query()->where('financial_transaction_id', $row->financial_transaction_id)->where('type', 'debit')->update(['ledger_account_id' => $account->id]);
        } elseif ($kind === 'deleted_journal') {
            LedgerEntry::query()->where('financial_transaction_id', $row->financial_transaction_id)->delete();
        } elseif ($kind === 'changed_terms') {
            $row->amount = '500.00';
            $row->fee = '100.00';
            $row->save();
        } else {
            DB::table('financial_transactions')->where('id', $row->financial_transaction_id)->update(['status' => 'reversed']);
        }
        $before = $row->fresh()->getRawOriginal();
        $owners = DB::table(FinancialProviderReferenceService::TABLE)->count();
        $transactions = FinancialTransaction::withTrashed()->count();
        $report = app(FinancialReconciliationService::class)->reconcile();
        $this->assertSame(ReconciliationStatus::Critical, $report->status);
        $this->assertNotNull(collect($report->discrepancies)->first(fn ($d) => $d->category === DiscrepancyCategory::DepositAccountingMissing && $d->entityId === $row->id));
        $this->assertSame($before, $row->fresh()->getRawOriginal());
        $this->assertSame($owners, DB::table(FinancialProviderReferenceService::TABLE)->count());
        $this->assertSame($transactions, FinancialTransaction::withTrashed()->count());
        $this->assertSame('400.00', $f['wallet']->fresh()->balance);
        $this->assertStringNotContainsString($row->provider_reference, json_encode($report->toArray()));
    }

    public function test_missing_currency_account_is_not_a_clean_zero_balance_or_a_stale_matched_replay(): void
    {
        $f = $this->createPlayer('0.00', Currency::USD);
        $service = app(LedgerReconciliationService::class);
        $this->assertSame('matched', $service->reconcileWallet($f['wallet']->id)->status->value);
        LedgerAccount::query()->where('code', LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_PLAYER_LIABILITY, Currency::USD))->delete();
        $report = app(FinancialReconciliationService::class)->reconcile(currency: Currency::USD);
        $this->assertSame(ReconciliationStatus::Critical, $report->status);
        $held = $service->reconcileWallet($f['wallet']->id);
        $this->assertSame('drift_detected', $held->status->value);
        $this->assertContains('missing-currency-liability-account', $held->drift_lines);
    }

    public function test_same_reference_in_distinct_provider_or_account_namespaces_is_not_a_duplicate(): void
    {
        $f = $this->createPlayer('0.00');
        foreach ([['bank_transfer', 'A'], ['bank_transfer', 'B'], ['stripe', 'A']] as [$method, $scope]) {
            $row = $this->createPendingDeposit($f['wallet'], '100.00', PaymentMethod::from($method));
            $row->provider_reference = 'SYNTHETIC-REUSED-LOCAL-NUMBER';
            $row->metadata = [DepositProviderAccountService::KEY => $scope];
            $row->save();
        }
        $report = app(FinancialReconciliationService::class)->reconcile();
        $this->assertSame(ReconciliationStatus::Pass, $report->status);
        $this->assertNull(collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::DuplicateDeposit));
    }

    public function test_duplicate_identity_includes_older_soft_deleted_other_currency_owner_without_exposing_reference(): void
    {
        $a = $this->createPlayer('0.00');
        $b = $this->createPlayer('0.00', Currency::USD);
        $rows = [];
        foreach ([$a, $b] as $f) {
            $row = $this->createPendingDeposit($f['wallet'], '100.00', PaymentMethod::BankTransfer);
            $row->provider_reference = 'SYNTHETIC-PRIVATE-HISTORICAL-IDENTITY';
            $row->save();
            $rows[] = $row;
        }
        $rows[1]->delete();
        DB::table('deposits')->where('id', $rows[1]->id)->update(['created_at' => now()->subYears(3)]);
        $report = app(FinancialReconciliationService::class)->reconcile(from: now()->subDay(), currency: Currency::THB);
        $duplicate = collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::DuplicateDeposit);
        $this->assertNotNull($duplicate);
        $this->assertSame([$rows[0]->id, $rows[1]->id], $duplicate->details['record_ids']);
        $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-HISTORICAL-IDENTITY', json_encode($report->toArray()));
    }

    public function test_many_balanced_entries_and_report_totals_do_not_depend_on_sql_concat_limits(): void
    {
        $f = $this->createPlayer('0.00');
        $tx = FinancialTransaction::factory()->completed()->create(['user_id' => $f['user']->id, 'wallet_id' => $f['wallet']->id,
            'type' => TransactionType::Adjustment, 'amount' => '70.00', 'currency' => Currency::THB]);
        $cash = LedgerAccount::query()->where('code', WalletService::ACCOUNT_SYSTEM_CASH)->sole();
        $liability = LedgerAccount::query()->where('code', WalletService::ACCOUNT_PLAYER_LIABILITY)->sole();
        for ($i = 0; $i < 140; $i++) {
            $debitAccount = $cash->replicate();
            $debitAccount->code = 'SYNTHETIC-'.(string) $i;
            $debitAccount->save();
            LedgerEntry::query()->create(['financial_transaction_id' => $tx->id, 'ledger_account_id' => $debitAccount->id,
                'wallet_id' => $f['wallet']->id, 'type' => 'debit', 'amount' => '0.50', 'currency' => Currency::THB]);
        }
        LedgerEntry::query()->create(['financial_transaction_id' => $tx->id, 'ledger_account_id' => $liability->id,
            'wallet_id' => $f['wallet']->id, 'type' => 'credit', 'amount' => '70.00', 'currency' => Currency::THB]);
        DB::table('wallets')->where('id', $f['wallet']->id)->update(['balance' => '70.00']);
        $mysql = DB::getDriverName() !== 'sqlite';
        $previous = $mysql ? (int) DB::selectOne('SELECT @@SESSION.group_concat_max_len AS v')->v : null;
        if ($mysql) {
            DB::statement('SET SESSION group_concat_max_len = 64');
        }
        try {
            $report = app(FinancialReconciliationService::class)->reconcile();
            $this->assertSame(ReconciliationStatus::Pass, $report->status);
            $this->assertSame('70.00', $report->totalLedgerDebits);
            $this->assertSame('70.00', $report->totalLedgerCredits);
            $this->assertSame('0.00', $report->ledgerDifference);
        } finally {
            if ($mysql) {
                DB::statement('SET SESSION group_concat_max_len = '.$previous);
            }
        }
    }

    public function test_historical_fault_session_settings_restore_even_when_the_fixture_throws(): void
    {
        try {
            $this->withoutHistoricalForeignKeys(fn () => throw new \LogicException('SYNTHETIC FAULT'));
        } catch (\LogicException) {
            $value = DB::getDriverName() === 'sqlite' ? DB::selectOne('PRAGMA foreign_keys')->foreign_keys : DB::selectOne('SELECT @@SESSION.foreign_key_checks AS v')->v;
            $this->assertSame(1, (int) $value);
        }
        try {
            $this->withoutHistoricalWalletChecks(fn () => throw new \LogicException('SYNTHETIC FAULT'));
        } catch (\LogicException) {
            $value = DB::getDriverName() === 'sqlite' ? DB::selectOne('PRAGMA ignore_check_constraints')->ignore_check_constraints : DB::selectOne('SELECT @@SESSION.check_constraint_checks AS v')->v;
            $this->assertSame(DB::getDriverName() === 'sqlite' ? 0 : 1, (int) $value);
        }
        DB::beginTransaction();
        try {
            $this->withoutHistoricalForeignKeys(fn () => $this->fail('No nested corruption fixture is allowed.'));
            $this->fail('Outer test transaction must be refused.');
        } catch (\RuntimeException) {
            $this->assertSame(1, DB::transactionLevel());
        } finally {
            DB::rollBack();
        }
    }

    public function test_native_decimal_projection_never_routes_accounting_amounts_through_php_floats(): void
    {
        $f = $this->paid();
        $amount = DB::getDriverName() === 'sqlite' ? '9999999.17' : '9007199254740993.17';
        $entry = LedgerEntry::query()->where('type', 'debit')->sole();
        DB::table('ledger_entries')->where('id', $entry->id)->update(['amount' => $amount]);
        $reader = app(ExactReconciliationReader::class);
        $this->assertSame($amount, $reader->sum(DB::table('ledger_entries')->where('id', $entry->id), 'amount'));
        $this->assertSame($amount, $reader->sides(DB::table('ledger_entries')->where('id', $entry->id))['debit']);
    }

    public static function invalidHolds(): array
    {
        return [['0.00', '-20.00'], ['-10.00', '-20.00']];
    }

    #[DataProvider('invalidHolds')]
    public function test_positive_available_arithmetic_cannot_hide_negative_original_balance_or_hold(string $balance, string $locked): void
    {
        $f = $this->createPlayer('0.00');
        $this->withoutHistoricalWalletChecks(fn () => DB::table('wallets')->where('id', $f['wallet']->id)
            ->update(['balance' => $balance, 'locked_balance' => $locked]));
        $report = app(FinancialReconciliationService::class)->reconcile();
        $this->assertSame(ReconciliationStatus::Critical, $report->status);
        $this->assertNotNull(collect($report->discrepancies)->firstWhere('category', DiscrepancyCategory::NegativeAvailableBalance));
        $this->assertSame($locked, $f['wallet']->fresh()->locked_balance);
    }
}
