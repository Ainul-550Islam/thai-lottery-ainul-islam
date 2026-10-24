<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Cash <-> Win conversion lane's storage: quotes, movements, audit trail.
 *
 * THREE TABLES, ONE LIFECYCLE
 * A quote is a priced promise; a conversion is a movement; an audit row is an
 * event that happened to either. Splitting them means a consumed quote can be
 * bound to the conversion it produced without either row's history being
 * rewritten, and a refusal can be recorded with no movement row at all.
 *
 * MONEY COLUMNS ARE DECIMAL(18,2), NEVER FLOATS
 * The lane's whole claim is exact arithmetic — a fee of exactly 1.00 on 100.00
 * and a ledger that balances to the satang. A float column would round at
 * storage time and the books would stop balancing in ways no test could
 * reproduce. Rates keep four decimal places because a whole percent of
 * '5.00' must survive as '0.0500' exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wallet_conversions')) {
            Schema::create('wallet_conversions', function (Blueprint $table): void {
                $table->id();
                $table->string('reference', 64)->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('direction', 32);
                $table->string('status', 16);
                $table->string('currency', 8);
                $table->decimal('amount_in', 18, 2);
                $table->decimal('fee_amount', 18, 2);
                $table->decimal('amount_out', 18, 2);
                $table->decimal('fee_rate', 12, 4);
                $table->string('fee_rule_key', 191);
                $table->string('fee_rule_version', 32);
                $table->foreignId('from_wallet_id')->constrained('wallets');
                $table->foreignId('to_wallet_id')->constrained('wallets');
                $table->string('from_wallet_type', 32);
                $table->string('to_wallet_type', 32);
                $table->decimal('from_balance_after', 18, 2)->nullable();
                $table->decimal('to_balance_after', 18, 2)->nullable();
                $table->string('channel', 16)->nullable();
                $table->string('quote_reference', 64)->nullable();
                $table->string('idempotency_key', 128)->nullable();
                $table->unsignedBigInteger('financial_transaction_id')->nullable();
                $table->foreignId('reversal_of_id')->nullable()->constrained('wallet_conversions')->nullOnDelete();
                $table->foreignId('reversed_by_id')->nullable()->constrained('wallet_conversions')->nullOnDelete();
                $table->string('reversal_reason', 500)->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->string('failure_reason', 500)->nullable();
                $table->string('note', 500)->nullable();
                $table->timestamps();

                // One movement per key, per member, per channel: a retry must
                // replay the first row, never create a second one.
                $table->unique(['user_id', 'channel', 'idempotency_key'], 'wc_idempotency_scope');
                $table->index(['user_id', 'status', 'completed_at'], 'wc_user_settled_window');
                $table->index(['user_id', 'created_at'], 'wc_user_history');
            });
        }

        if (! Schema::hasTable('wallet_conversion_quotes')) {
            Schema::create('wallet_conversion_quotes', function (Blueprint $table): void {
                $table->id();
                $table->string('reference', 64)->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('direction', 32);
                $table->string('currency', 8);
                $table->decimal('amount_in', 18, 2);
                $table->decimal('fee_amount', 18, 2);
                $table->decimal('amount_out', 18, 2);
                $table->decimal('rate', 12, 4);
                $table->string('fee_bearer', 16);
                $table->string('fee_rule_key', 191);
                $table->string('fee_rule_version', 32);
                $table->string('status', 16)->default('open');
                $table->string('idempotency_key', 128)->nullable();
                $table->foreignId('converted_id')->nullable()->constrained('wallet_conversions')->nullOnDelete();
                $table->timestamp('consumed_at')->nullable();
                $table->timestamp('expires_at');
                $table->timestamps();

                $table->index(['user_id', 'status', 'expires_at'], 'wc_quotes_open_window');
            });
        }

        if (! Schema::hasTable('wallet_conversion_audits')) {
            Schema::create('wallet_conversion_audits', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('wallet_conversion_id')->nullable()->constrained('wallet_conversions')->nullOnDelete();
                $table->string('reference', 64);
                $table->string('event', 64);
                $table->string('error_code', 64)->nullable();
                $table->string('channel', 16)->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->string('request_id', 64)->nullable();
                $table->json('context')->nullable();
                $table->timestamp('recorded_at');
                $table->timestamps();

                $table->index(['reference', 'recorded_at'], 'wc_audit_reference_time');
                $table->index(['recorded_at', 'error_code'], 'wc_audit_refusals');
            });
        }
    }

    public function down(): void
    {
        // Children drop before parents: wallet_conversion_quotes carries the
        // converted_id foreign key into wallet_conversions, and audits carry
        // wallet_conversion_id, so the parents come down last.
        Schema::dropIfExists('wallet_conversion_audits');
        Schema::dropIfExists('wallet_conversion_quotes');
        Schema::dropIfExists('wallet_conversions');
    }
};
