<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger transaction headers (the tamper-evident journal group).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS FILE NO LONGER CREATES wallets, ledger_accounts OR ledger_entries
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * This file previously created FOUR tables in one `up()`: wallets,
 * ledger_accounts, ledger_transactions and ledger_entries. The first, second
 * and fourth of those ALREADY EXIST from the 2024 schema:
 *
 *   2024_01_02_000100_create_wallets_table.php          → wallets
 *   2024_01_02_000200_create_ledger_accounts_table.php  → ledger_accounts
 *   2024_01_02_000400_create_ledger_entries_table.php   → ledger_entries
 *
 * Because Laravel runs migrations in filename order and "2024_…" sorts before
 * "2026_…", those three tables were always created FIRST. This migration then
 * issued `Schema::create('wallets')` for a second time, which MySQL/SQLite/
 * PostgreSQL all refuse with SQLSTATE[42S01] "table already exists". The whole
 * migration run aborted here — on a fresh checkout `php artisan migrate` could
 * never complete, and because tests/TestCase::prepareTestSchema() runs
 * `migrate --force` before any test boots, no test could run either.
 *
 * The two schemas are also mutually incompatible and were never reconcilable:
 *
 *   ┌──────────────────┬────────────────────────┬───────────────────────────┐
 *   │                  │ 2024 schema (LIVE)     │ this file's version       │
 *   ├──────────────────┼────────────────────────┼───────────────────────────┤
 *   │ primary key      │ bigint autoincrement   │ uuid                      │
 *   │ money precision  │ decimal(20,2)          │ decimal(20,4)             │
 *   │ status casing    │ lowercase ('posted')   │ UPPERCASE ('POSTED')      │
 *   │ used by models   │ Wallet, LedgerAccount, │ (no model — the only      │
 *   │                  │ LedgerEntry            │  UUID ledger model is     │
 *   │                  │ (all bigint, no        │  LedgerTransaction, and   │
 *   │                  │  HasUuids)             │  it targets THIS table)   │
 *   └──────────────────┴────────────────────────┴───────────────────────────┘
 *
 * The 2024 schema is the live one: App\Models\Wallet, App\Models\LedgerAccount
 * and App\Models\LedgerEntry all use bigint keys and none of them declares
 * HasUuids, and every service, job, command and test in the project reads and
 * writes them through those models. Those three `Schema::create()` calls are
 * therefore removed outright.
 *
 * `ledger_transactions` is the exception and is KEPT: it is created nowhere
 * else in the repository, and App\Models\LedgerTransaction (which DOES use
 * HasUuids, matching this uuid/uppercase/decimal-20-4 shape) is depended on by
 * live code — App\Services\Ledger\DoubleEntryLedgerService,
 * App\Services\Ledger\LedgerReconciliationService,
 * App\Services\Monitoring\MetricsCollectorService and
 * App\Console\Commands\ArchiveHistoricalLedgerTransactionsCommand. Before this
 * fix the table simply did not exist in any database, so every one of those
 * call sites would have failed at runtime.
 *
 * The unguarded `ALTER TABLE … ADD CONSTRAINT … CHECK (…)` statements that
 * followed the old wallets/ledger_entries creations are also gone. They were
 * MySQL/MariaDB-only syntax with no driver guard (unlike the equivalent
 * statements in 2024_01_02_000100, which correctly check
 * `DB::getDriverName()`), so they would have been a second, separate fatal
 * error on the SQLite database the test suite uses — and on PostgreSQL.
 *
 * NOTE ON THE FILENAME: it still reads "…financial_ledger_and_wallets…"
 * although it now only creates ledger_transactions. It is kept deliberately.
 * This migration has never completed successfully anywhere (it always aborted
 * on the wallets collision), so no `migrations` row exists for it; renaming it
 * would only create the risk of an orphan row for anyone whose history differs,
 * for no functional gain. The docblock is the record of what it now does.
 */
return new class extends Migration
{
    /**
     * Create the tamper-evident ledger transaction header table.
     *
     * Each row groups a balanced set of double-entry postings and carries a
     * SHA-256 chain: `previous_hash` links to the preceding header and `hash`
     * seals this one, so a row edited in place breaks the chain from that point
     * forward. `hash` is UNIQUE, which also makes a duplicated header
     * impossible to insert silently.
     */
    public function up(): void
    {
        if (! Schema::hasTable('ledger_transactions')) {
            Schema::create('ledger_transactions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
    
                $table->string('transaction_number', 64)->unique();
    
                // What this journal group is FOR: order, payout, deposit,
                // commission, withdrawal. Indexed together with reference_id
                // because the hot read is "the transaction for this reference".
                $table->string('reference_type', 64)->index();
                $table->string('reference_id', 64)->index();
    
                // A caller-supplied idempotency key. UNIQUE, so a retried posting
                // is refused by the database rather than by application timing.
                $table->string('idempotency_key', 128)->nullable()->unique();
    
                $table->string('currency', 3)->default('THB');
    
                // Sum of the debit (and equally credit) side of this group.
                $table->decimal('total_amount', 20, 4);
    
                $table->string('description', 255)->nullable();
    
                $table->string('status', 32)->default('POSTED');
    
                // Hash chain: previous_hash → this row's hash. Both nullable-safe
                // (the first row has no predecessor); `hash` is unique so a forged
                // or duplicated header cannot be inserted.
                $table->string('previous_hash', 64)->nullable();
                $table->string('hash', 64)->unique();
    
                $table->timestamp('posted_at')->useCurrent();
                $table->timestamps();
    
                $table->index(['reference_type', 'reference_id'], 'idx_ledger_tx_ref');
                $table->index('posted_at', 'idx_ledger_tx_posted_at');
            });
        }
    }

    /**
     * Reverse the migration.
     *
     * Only ledger_transactions is dropped. The 2024 wallets, ledger_accounts
     * and ledger_entries tables are owned by their own migrations and must
     * never be dropped from here.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_transactions');
    }
};
