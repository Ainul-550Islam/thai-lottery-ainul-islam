<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations for Enterprise Double-Entry Ledger and Wallets.
     */
    public function up(): void
    {
        // 1. Wallets Table (State Cache for fast reads & balance locks)
        Schema::create('wallets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('type', 32)->default('main'); // main, commission, bonus, escrow
            $table->string('currency', 3)->default('THB');
            $table->decimal('balance', 20, 4)->default('0.0000');
            $table->decimal('locked_balance', 20, 4)->default('0.0000');
            $table->unsignedBigInteger('version')->default(1);
            $table->string('status', 24)->default('active'); // active, frozen, suspended
            $table->timestamps();

            $table->unique(['user_id', 'type', 'currency'], 'idx_wallets_user_type_currency');
            $table->index(['status', 'currency'], 'idx_wallets_status_currency');
        });

        // Add DB-Level CHECK constraints for zero negative balance guarantee
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_wallets_balance_non_negative CHECK (balance >= 0.0000)');
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_wallets_locked_balance_non_negative CHECK (locked_balance >= 0.0000)');
        DB::statement('ALTER TABLE wallets ADD CONSTRAINT chk_wallets_locked_lte_balance CHECK (locked_balance <= balance)');

        // 2. Ledger Accounts (Chart of Accounts for General Ledger)
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('account_number', 64)->unique();
            $table->string('name', 128);
            $table->enum('type', ['ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE']);
            $table->string('currency', 3)->default('THB');
            $table->decimal('current_balance', 24, 4)->default('0.0000');
            $table->uuid('user_id')->nullable()->index(); // Null for system internal accounts
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['type', 'currency'], 'idx_ledger_accounts_type_currency');
        });

        // 3. Ledger Transactions (Group of balanced entries with cryptographic verification)
        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('transaction_number', 64)->unique();
            $table->string('reference_type', 64)->index(); // order, payout, deposit, commission, withdrawal
            $table->string('reference_id', 64)->index();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->string('currency', 3)->default('THB');
            $table->decimal('total_amount', 20, 4);
            $table->string('description', 255)->nullable();
            $table->string('status', 32)->default('POSTED'); // POSTED, REVERSED
            $table->string('previous_hash', 64)->nullable(); // SHA-256 blockchain-like tamper proofing
            $table->string('hash', 64)->unique(); // SHA-256 of current record state
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id'], 'idx_ledger_tx_ref');
            $table->index('posted_at', 'idx_ledger_tx_posted_at');
        });

        // 4. Ledger Entries (Immutable double-entry debits and credits)
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ledger_transaction_id')->index();
            $table->uuid('ledger_account_id')->index();
            $table->enum('direction', ['DEBIT', 'CREDIT']);
            $table->decimal('amount', 20, 4);
            $table->string('currency', 3)->default('THB');
            $table->decimal('balance_after', 24, 4);
            $table->unsignedInteger('sequence')->default(1);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('ledger_transaction_id')
                ->references('id')
                ->on('ledger_transactions')
                ->onDelete('restrict');

            $table->foreign('ledger_account_id')
                ->references('id')
                ->on('ledger_accounts')
                ->onDelete('restrict');

            $table->index(['ledger_account_id', 'created_at'], 'idx_entries_account_created');
        });

        // DB check constraint on entry amount
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT chk_entries_amount_positive CHECK (amount > 0.0000)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
        Schema::dropIfExists('wallets');
    }
};
