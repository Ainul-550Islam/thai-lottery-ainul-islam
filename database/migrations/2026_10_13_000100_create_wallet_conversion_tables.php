<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Additive schema only. No wallet balance, fee or existing ledger row is changed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_conversions', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('direction', 32);
            $table->string('status', 32)->default('pending');
            $table->string('currency', 3);
            $table->foreignId('from_wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('to_wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->string('from_wallet_type', 32);
            $table->string('to_wallet_type', 32);
            $table->decimal('amount_in', 20, 2);
            // Signed to support an explicit negative fee refund on a reversal row.
            $table->decimal('fee_amount', 20, 2);
            $table->decimal('amount_out', 20, 2);
            $table->decimal('fee_rate', 12, 8);
            $table->string('fee_bearer', 32);
            $table->string('fee_rule_key', 128);
            $table->string('fee_rule_version', 64);
            $table->string('channel', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('quote_reference', 64)->nullable()->index();
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64)->nullable();
            $table->foreignId('financial_transaction_id')->nullable()->constrained('financial_transactions')->restrictOnDelete();
            $table->decimal('from_balance_before', 20, 2)->nullable();
            $table->decimal('to_balance_before', 20, 2)->nullable();
            $table->decimal('from_balance_after', 20, 2)->nullable();
            $table->decimal('to_balance_after', 20, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_reason', 128)->nullable();
            // One reversal for each original; database uniqueness backs the service lock.
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('wallet_conversions')->restrictOnDelete();
            $table->foreignId('reversed_by_id')->nullable()->unique()->constrained('wallet_conversions')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key'], 'wconv_owner_key_unique');
            $table->index(['user_id', 'status', 'completed_at'], 'wconv_owner_status_time');
            $table->index(['status', 'created_at'], 'wconv_status_created');
            $table->index(['user_id', 'direction', 'created_at'], 'wconv_owner_direction_time');
        });

        Schema::create('wallet_conversion_quotes', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('direction', 32);
            $table->string('currency', 3);
            $table->decimal('amount_in', 20, 2);
            $table->decimal('fee_amount', 20, 2);
            $table->decimal('amount_out', 20, 2);
            $table->decimal('rate', 12, 8);
            $table->string('fee_bearer', 32);
            $table->string('fee_rule_key', 128);
            $table->string('fee_rule_version', 64);
            $table->json('limits');
            $table->string('status', 32)->default('open');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->foreignId('converted_id')->nullable()->unique()->constrained('wallet_conversions')->restrictOnDelete();
            $table->timestamp('consumed_at')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status', 'expires_at'], 'wquote_owner_status_expiry');
            $table->index(['status', 'expires_at'], 'wquote_status_expiry');
        });

        Schema::create('wallet_conversion_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_conversion_id')->nullable()->constrained('wallet_conversions')->restrictOnDelete();
            $table->string('reference', 64)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('event', 64);
            $table->string('direction', 32)->nullable();
            $table->string('channel', 32);
            $table->string('error_code', 128)->nullable();
            $table->text('message')->nullable();
            $table->json('checks')->nullable();
            $table->json('context')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 128)->nullable();
            $table->timestamp('recorded_at');
            // The model is a plain Eloquent Model and does not opt out of
            // timestamps, so every insert carries created_at/updated_at. Without
            // these two columns every audit write dies with
            // "table wallet_conversion_audits has no column named updated_at" -
            // which took out the whole wallet-conversion lane at once.
            //
            // This migration is the one that actually builds the table: the two
            // later create_wallet_conversion_tables migrations both guard on
            // `if (! Schema::hasTable(...))`, so on a fresh database they are
            // skipped and never reshape anything. Declaring the columns here is
            // therefore the only fix that reaches a real build.
            $table->timestamps();
            $table->index(['reference', 'recorded_at'], 'waudit_reference_time');
            $table->index(['user_id', 'recorded_at'], 'waudit_owner_time');
            $table->index(['error_code', 'recorded_at'], 'waudit_error_time');
        });
    }

    public function down(): void
    {
        // Refuse routine rollback once financial evidence exists. Never silently erase it.
        foreach (['wallet_conversion_audits', 'wallet_conversion_quotes', 'wallet_conversions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Refusing to drop populated conversion tables. Archive and review financial history first.');
            }
        }
        // Drop dependent tables first; self references disappear with the parent table.
        Schema::dropIfExists('wallet_conversion_audits');
        Schema::dropIfExists('wallet_conversion_quotes');
        Schema::dropIfExists('wallet_conversions');
    }
};
