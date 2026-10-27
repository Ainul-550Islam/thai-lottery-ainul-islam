<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\FinancialReversalService;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Payment\PaymentTestCase;
use Tests\Support\InjectsHistoricalAccountingFaults;

final class ReversalIntegrityTest extends PaymentTestCase
{
    use InjectsHistoricalAccountingFaults;

    private function fixture(Currency $currency = Currency::THB, bool $credit = true): array
    {
        $f = $this->createPlayer($credit ? '0.00' : '100.00', $currency);
        $wallets = app(WalletService::class);
        $amount = Money::of('100.00', $currency);
        $tx = $credit
            ? $wallets->credit($f['wallet'], $amount, FinancialTransactionType::Deposit, 'synthetic-original-credit')
            : $wallets->debit($f['wallet'], $amount, FinancialTransactionType::Withdrawal, 'synthetic-original-debit');

        return $f + ['tx' => $tx];
    }

    private function snapshot(): array
    {
        $out = [];
        foreach (['wallets', 'financial_transactions', 'ledger_entries', 'ledger_accounts'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $out;
    }

    private function refused(callable $work, ?string $code = 'reversal_accounting_conflict'): void
    {
        $before = $this->snapshot();
        try {
            $work();
            $this->fail('Unproved or foreign compensation was accepted.');
        } catch (FinancialException $error) {
            if ($code !== null) {
                $this->assertSame($code, $error->errorCode());
            }
        }
        $this->assertSame($before, $this->snapshot(), 'Refusal changed financial state.');
    }

    public static function directions(): array
    {
        $cases = [];
        foreach ([Currency::THB, Currency::BDT, Currency::USD] as $currency) {
            foreach ([true, false] as $credit) {
                $cases[$currency->value.($credit ? '-credit' : '-debit')] = [$currency, $credit];
            }
        }

        return $cases;
    }

    #[DataProvider('directions')]
    public function test_full_compensation_has_exact_mirror_and_verified_read_only_replay(Currency $currency, bool $credit): void
    {
        $f = $this->fixture($currency, $credit);
        $service = app(FinancialReversalService::class);
        $entries = DB::table('ledger_entries')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $original = $f['tx']->getRawOriginal();
        $this->assertTrue($service->canReverse($f['tx']));
        $this->assertNull($service->existingReversalFor($f['tx']));
        $reversal = $service->reverse($f['tx'], $f['user']->id, $service->reversalKeyFor($f['tx']), 'Synthetic local correction');
        $this->assertSame($credit ? '0.00' : '100.00', $f['wallet']->fresh()->balance);
        $this->assertSame(TransactionStatus::Reversed, $f['tx']->fresh()->status);
        $this->assertSame(TransactionStatus::Completed, $reversal->status);
        $this->assertSame($f['tx']->id, $reversal->reference_id);
        $this->assertSame($entries, DB::table('ledger_entries')->where('financial_transaction_id', $f['tx']->id)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
        foreach (['amount', 'fee', 'wallet_id', 'user_id', 'type', 'currency', 'idempotency_key', 'reference_number', 'uuid', 'metadata'] as $field) {
            $this->assertSame($original[$field], $f['tx']->fresh()->getRawOriginal($field));
        }
        foreach (LedgerEntry::query()->where('financial_transaction_id', $f['tx']->id)->get() as $source) {
            $mirror = LedgerEntry::query()->where('financial_transaction_id', $reversal->id)->get()->first(fn ($entry): bool => $entry->metadata['reversal_of_ledger_entry_id'] === $source->id);
            $this->assertNotNull($mirror);
            $this->assertSame($source->type->opposite(), $mirror->type);
            $this->assertSame($source->amount, $mirror->amount);
            $this->assertSame($source->ledger_account_id, $mirror->ledger_account_id);
            $this->assertSame($source->wallet_id, $mirror->wallet_id);
        }
        // Subsequent activity and changed caller fields cannot authorize another debit/credit.
        app(WalletService::class)->credit($f['wallet'], Money::of('1.00', $currency), FinancialTransactionType::Adjustment, 'synthetic-later-credit');
        $f['tx']->wallet_id = Wallet::factory()->create()->id;
        $f['tx']->status = TransactionStatus::Pending;
        $f['tx']->amount = '999.00';
        $before = $this->snapshot();
        $this->assertSame($reversal->id, $service->reverse($f['tx'])->id);
        $this->assertSame($reversal->id, $service->existingReversalFor($f['tx'])->id);
        $this->assertFalse($service->canReverse($f['tx']));
        $this->assertSame($before, $this->snapshot());
    }

    public static function originalFaults(): array
    {
        return array_combine($names = ['no-entries', 'missing-leg', 'deleted-leg', 'entry-amount', 'header-amount', 'wrong-wallet', 'missing-wallet', 'wrong-user', 'currency', 'direction', 'wrong-account', 'fee', 'engine-type', 'unprocessed', 'foreign-counterparty', 'entry-currency', 'zero-amount', 'deleted-header', 'stripped-wallet'], array_map(fn ($name): array => [$name], $names));
    }

    #[DataProvider('originalFaults')]
    public function test_balanced_or_completed_labels_never_replace_original_accounting(string $fault): void
    {
        $f = $this->fixture();
        $tx = $f['tx'];
        $rows = DB::table('ledger_entries')->where('financial_transaction_id', $tx->id);
        $leg = (clone $rows)->where('type', 'credit')->sole();
        $other = (clone $rows)->where('type', 'debit')->sole();
        match ($fault) {
            'no-entries' => $rows->delete(),
            'missing-leg' => DB::table('ledger_entries')->where('id', $leg->id)->delete(),
            'deleted-leg' => DB::table('ledger_entries')->where('id', $leg->id)->update(['deleted_at' => now()]),
            'entry-amount' => $rows->update(['amount' => '99.00']),
            'header-amount' => DB::table('financial_transactions')->where('id', $tx->id)->update(['amount' => '99.00']),
            'wrong-wallet' => DB::table('ledger_entries')->where('id', $leg->id)->update(['wallet_id' => Wallet::factory()->create()->id]),
            'missing-wallet' => DB::table('ledger_entries')->where('id', $leg->id)->update(['wallet_id' => null]),
            'wrong-user' => DB::table('financial_transactions')->where('id', $tx->id)->update(['user_id' => User::factory()->create()->id]),
            'currency' => DB::table('financial_transactions')->where('id', $tx->id)->update(['currency' => 'USD']),
            'direction' => $rows->update(['type' => DB::raw("CASE WHEN type = 'debit' THEN 'credit' ELSE 'debit' END")]),
            'wrong-account' => DB::table('ledger_entries')->where('id', $other->id)->update(['ledger_account_id' => LedgerAccount::query()->where('code', WalletService::ACCOUNT_ADJUSTMENT_EQUITY)->sole()->id]),
            'fee' => DB::table('financial_transactions')->where('id', $tx->id)->update(['fee' => '1.00']),
            'engine-type' => DB::table('financial_transactions')->where('id', $tx->id)->update(['metadata' => json_encode(['engine_type' => 'withdrawal'])]),
            'unprocessed' => DB::table('financial_transactions')->where('id', $tx->id)->update(['processed_at' => null]),
            'foreign-counterparty' => DB::table('ledger_entries')->where('id', $other->id)->update(['wallet_id' => Wallet::factory()->create()->id]),
            'entry-currency' => $rows->update(['currency' => 'USD']),
            'zero-amount' => $this->withoutHistoricalWalletChecks(fn () => DB::table('financial_transactions')->where('id', $tx->id)->update(['amount' => '0.00'])),
            'deleted-header' => DB::table('financial_transactions')->where('id', $tx->id)->update(['deleted_at' => now()]),
            'stripped-wallet' => [DB::table('financial_transactions')->where('id', $tx->id)->update(['wallet_id' => null]), $rows->update(['wallet_id' => null])],
        };
        $this->assertFalse(app(FinancialReversalService::class)->canReverse($tx));
        $this->refused(fn () => app(FinancialReversalService::class)->reverse($tx));
    }

    public static function replayFaults(): array
    {
        return array_map(fn ($fault): array => [$fault], ['reference', 'metadata-origin', 'source-hash', 'header-amount', 'pending', 'deleted', 'missing-mirror', 'mirror-amount', 'mirror-origin', 'mirror-wallet', 'mirror-direction', 'original-changed', 'original-label', 'duplicate-candidate']);
    }

    #[DataProvider('replayFaults')]
    public function test_terminal_replay_requires_complete_original_bound_compensation(string $fault): void
    {
        $f = $this->fixture();
        $service = app(FinancialReversalService::class);
        $reversal = $service->reverse($f['tx']);
        $header = DB::table('financial_transactions')->where('id', $reversal->id);
        $mirrors = DB::table('ledger_entries')->where('financial_transaction_id', $reversal->id);
        $leg = (clone $mirrors)->where('type', 'debit')->sole();
        $metadata = $reversal->metadata;
        match ($fault) {
            'reference' => $header->update(['reference_id' => $reversal->id]),
            'metadata-origin' => $header->update(['metadata' => json_encode(array_replace($metadata, ['reversal_of_transaction_id' => $reversal->id]))]),
            'source-hash' => $header->update(['metadata' => json_encode(array_replace($metadata, ['reversal_source_hash' => str_repeat('0', 64)]))]),
            'header-amount' => $header->update(['amount' => '99.00']),
            'pending' => $header->update(['status' => 'pending']),
            'deleted' => $header->update(['deleted_at' => now()]),
            'missing-mirror' => DB::table('ledger_entries')->where('id', $leg->id)->delete(),
            'mirror-amount' => $mirrors->update(['amount' => '99.00']),
            'mirror-origin' => DB::table('ledger_entries')->where('id', $leg->id)->update(['metadata' => json_encode(['reversal_of_ledger_entry_id' => 0])]),
            'mirror-wallet' => DB::table('ledger_entries')->where('id', $leg->id)->update(['wallet_id' => Wallet::factory()->create()->id]),
            'mirror-direction' => $mirrors->update(['type' => DB::raw("CASE WHEN type = 'debit' THEN 'credit' ELSE 'debit' END")]),
            'original-changed' => DB::table('financial_transactions')->where('id', $f['tx']->id)->update(['reference_number' => 'changed-original']),
            'original-label' => DB::table('financial_transactions')->where('id', $f['tx']->id)->update(['status' => 'completed']),
            'duplicate-candidate' => FinancialTransaction::factory()->completed()->ofType(TransactionType::Reversal)->create(['reference_type' => FinancialTransaction::class, 'reference_id' => $f['tx']->id]),
        };
        $this->refused(fn () => $service->reverse($f['tx']));
        $this->refused(fn () => $service->existingReversalFor($f['tx']));
    }

    public function test_custom_or_another_originals_key_cannot_redirect_even_identical_economics(): void
    {
        $f = $this->fixture();
        $service = app(FinancialReversalService::class);
        $other = app(WalletService::class)->credit($f['wallet'], Money::of('100.00', Currency::THB), FinancialTransactionType::Deposit, 'synthetic-other-original');
        $this->refused(fn () => $service->reverse($f['tx'], idempotencyKey: 'caller-selected'), 'reversal_noncanonical_key');
        $first = $service->reverse($f['tx']);
        $this->refused(fn () => $service->reverse($other, idempotencyKey: $first->idempotency_key), 'reversal_noncanonical_key');
        $this->assertSame('100.00', $f['wallet']->fresh()->balance);
        $this->assertSame(TransactionStatus::Completed, $other->fresh()->status);
        $this->assertSame(3, FinancialTransaction::query()->count());
    }

    public function test_late_ledger_failure_rolls_back_wallet_header_and_original_inside_caught_outer_transaction(): void
    {
        $f = $this->fixture();
        $account = LedgerAccount::query()->where('code', WalletService::ACCOUNT_SYSTEM_CASH)->sole();
        $account->is_active = false;
        $account->save();
        DB::transaction(function () use ($f): void {
            $this->refused(fn () => app(FinancialReversalService::class)->reverse($f['tx']), null);
        });
        $this->assertSame(TransactionStatus::Completed, $f['tx']->fresh()->status);
        $this->assertDatabaseCount('financial_transactions', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_insufficient_available_balance_and_reversal_of_reversal_fail_without_partial_money(): void
    {
        $f = $this->fixture();
        app(WalletService::class)->lockFunds($f['wallet'], Money::of('100.00', Currency::THB));
        $this->refused(fn () => app(FinancialReversalService::class)->reverse($f['tx']), null);
        app(WalletService::class)->unlockFunds($f['wallet'], Money::of('100.00', Currency::THB));
        $reversal = app(FinancialReversalService::class)->reverse($f['tx']);
        $this->refused(fn () => app(FinancialReversalService::class)->reverse($reversal));
    }

    public function test_balanced_walletless_journal_can_be_mirrored_without_touching_a_wallet(): void
    {
        $f = $this->createPlayer('0.00');
        $tx = DB::transaction(function (): FinancialTransaction {
            $tx = FinancialTransaction::factory()->ofType(TransactionType::Settlement)->create([
                'wallet_id' => null, 'user_id' => null, 'amount' => '100.00', 'metadata' => ['engine_type' => 'settlement'],
            ]);
            app(LedgerPostingService::class)->post($tx, [
                ['ledger_account_id' => LedgerAccount::query()->where('code', WalletService::ACCOUNT_SYSTEM_CASH)->sole()->id,
                    'type' => LedgerEntryType::Debit, 'amount' => Money::of('100.00', Currency::THB)],
                ['ledger_account_id' => LedgerAccount::query()->where('code', WalletService::ACCOUNT_ADJUSTMENT_EQUITY)->sole()->id,
                    'type' => LedgerEntryType::Credit, 'amount' => Money::of('100.00', Currency::THB)],
            ]);
            $tx->status = TransactionStatus::Completed;
            $tx->processed_at = now();
            $tx->save();

            return $tx;
        });
        $wallet = $f['wallet']->fresh()->getRawOriginal();
        $reversal = app(FinancialReversalService::class)->reverse($tx);
        $this->assertNull($reversal->wallet_id);
        $this->assertSame($wallet, $f['wallet']->fresh()->getRawOriginal());
        $this->assertDatabaseCount('ledger_entries', 4);
    }

    public function test_failure_after_complete_journal_posting_rolls_back_even_when_caught_by_outer_caller(): void
    {
        $f = $this->fixture();
        $before = $this->snapshot();
        $event = 'eloquent.updating: '.FinancialTransaction::class;
        $dispatcher = FinancialTransaction::getEventDispatcher();
        $dispatcher->listen($event, function (FinancialTransaction $tx): void {
            if ($tx->type === FinancialTransactionType::Reversal->toTransactionType() && $tx->status === TransactionStatus::Completed) {
                $this->assertDatabaseCount('ledger_entries', 4);
                throw new \RuntimeException('Synthetic post-journal finality failure');
            }
        });
        try {
            DB::transaction(function () use ($f): void {
                try {
                    app(FinancialReversalService::class)->reverse($f['tx']);
                    $this->fail('Post-journal failure was not propagated.');
                } catch (\RuntimeException $error) {
                    $this->assertSame('Synthetic post-journal finality failure', $error->getMessage());
                }
            });
        } finally {
            $dispatcher->forget($event);
        }
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('directions')]
    public function test_frozen_wallet_refuses_new_compensation_but_can_replay_a_completed_one(Currency $currency, bool $credit): void
    {
        $f = $this->fixture($currency, $credit);
        $service = app(FinancialReversalService::class);
        DB::table('wallets')->where('id', $f['wallet']->id)->update(['status' => 'frozen']);
        $this->refused(fn () => $service->reverse($f['tx']), 'reversal_wallet_not_eligible');
        DB::table('wallets')->where('id', $f['wallet']->id)->update(['status' => 'active']);
        $reversal = $service->reverse($f['tx']);
        DB::table('wallets')->where('id', $f['wallet']->id)->update(['status' => 'frozen']);
        $before = $this->snapshot();
        $this->assertSame($reversal->id, $service->reverse($f['tx'])->id);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_counterparty_first_with_inherited_wallet_context_never_selects_the_wrong_wallet_direction(): void
    {
        $f = $this->fixture();
        $other = $this->createPlayer('0.00');
        app(WalletService::class)->credit($other['wallet'], Money::of('50.00', Currency::THB), FinancialTransactionType::Deposit, 'synthetic-other-player');
        // Reorder a controlled fixture's identical original posting, without changing wallet/account amounts.
        $rows = DB::table('ledger_entries')->where('financial_transaction_id', $f['tx']->id)->orderByDesc('id')->get();
        DB::table('ledger_entries')->where('financial_transaction_id', $f['tx']->id)->delete();
        foreach ($rows as $row) {
            $data = (array) $row;
            unset($data['id']);
            DB::table('ledger_entries')->insert($data);
        }
        $reversal = app(FinancialReversalService::class)->reverse($f['tx']);
        $liability = LedgerAccount::query()->where('code', WalletService::ACCOUNT_PLAYER_LIABILITY)->sole();
        $leg = LedgerEntry::query()->where('financial_transaction_id', $reversal->id)->where('ledger_account_id', $liability->id)->sole();
        $this->assertSame(LedgerEntryType::Debit, $leg->type);
        $this->assertSame('0.00', $leg->balance_after);
        $this->assertSame('0.00', $f['wallet']->fresh()->balance);
        $this->assertSame('50.00', $other['wallet']->fresh()->balance);
    }
}
