<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciles all enterprise 6D/ledger/payout/commission columns onto the
 * existing 2024 tables (`draws`, `agents`, `draw_results`, `payouts`,
 * `ledger_entries`) with full nullable/default safety so both the core 2024
 * lottery engine and the enterprise lifecycle suite run side by side without
 * schema drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('draws')) {
            Schema::table('draws', function (Blueprint $table): void {
                if (! Schema::hasColumn('draws', 'draw_code')) {
                    $table->string('draw_code', 64)->nullable()->unique();
                }
                if (! Schema::hasColumn('draws', 'game_type')) {
                    $table->string('game_type', 32)->nullable()->index();
                }
                if (! Schema::hasColumn('draws', 'draw_date')) {
                    $table->date('draw_date')->nullable()->index();
                }
                if (! Schema::hasColumn('draws', 'betting_starts_at')) {
                    $table->timestamp('betting_starts_at')->nullable();
                }
                if (! Schema::hasColumn('draws', 'betting_ends_at')) {
                    $table->timestamp('betting_ends_at')->nullable()->index();
                }
                if (! Schema::hasColumn('draws', 'total_entries_count')) {
                    $table->unsignedBigInteger('total_entries_count')->default(0);
                }
                if (! Schema::hasColumn('draws', 'total_pool_amount')) {
                    $table->decimal('total_pool_amount', 19, 4)->default(0);
                }
                if (! Schema::hasColumn('draws', 'total_payout_amount')) {
                    $table->decimal('total_payout_amount', 19, 4)->default(0);
                }
                if (! Schema::hasColumn('draws', 'version')) {
                    $table->unsignedBigInteger('version')->default(1);
                }
            });
        }

        if (Schema::hasTable('agents')) {
            Schema::table('agents', function (Blueprint $table): void {
                if (! Schema::hasColumn('agents', 'tier_level')) {
                    $table->unsignedTinyInteger('tier_level')->default(1)->index();
                }
                if (! Schema::hasColumn('agents', 'direct_commission_rate')) {
                    $table->decimal('direct_commission_rate', 8, 6)->default(0);
                }
            });
        }

        if (Schema::hasTable('draw_results')) {
            Schema::table('draw_results', function (Blueprint $table): void {
                if (! Schema::hasColumn('draw_results', 'two_digit_back')) {
                    $table->string('two_digit_back', 2)->nullable();
                }
                if (! Schema::hasColumn('draw_results', 'three_digit_front')) {
                    $table->json('three_digit_front')->nullable();
                }
                if (! Schema::hasColumn('draw_results', 'three_digit_back')) {
                    $table->json('three_digit_back')->nullable();
                }
                if (! Schema::hasColumn('draw_results', 'second_prizes')) {
                    $table->json('second_prizes')->nullable();
                }
                if (! Schema::hasColumn('draw_results', 'third_prizes')) {
                    $table->json('third_prizes')->nullable();
                }
                if (! Schema::hasColumn('draw_results', 'fourth_prizes')) {
                    $table->json('fourth_prizes')->nullable();
                }
                if (! Schema::hasColumn('draw_results', 'fifth_prizes')) {
                    $table->json('fifth_prizes')->nullable();
                }
                if (! Schema::hasColumn('draw_results', 'source_provider')) {
                    $table->string('source_provider', 64)->nullable();
                }
                if (! Schema::hasColumn('draw_results', 'payload_fingerprint')) {
                    $table->string('payload_fingerprint', 128)->nullable()->unique();
                }
                if (! Schema::hasColumn('draw_results', 'ingested_by_operator_id')) {
                    $table->unsignedBigInteger('ingested_by_operator_id')->nullable()->index();
                }
                if (! Schema::hasColumn('draw_results', 'confirmed_by_operator_id')) {
                    $table->unsignedBigInteger('confirmed_by_operator_id')->nullable()->index();
                }
                if (! Schema::hasColumn('draw_results', 'verification_status')) {
                    $table->string('verification_status', 32)->nullable()->index();
                }
                if (! Schema::hasColumn('draw_results', 'ingested_at')) {
                    $table->timestamp('ingested_at')->nullable();
                }
                if (! Schema::hasColumn('draw_results', 'confirmed_at')) {
                    $table->timestamp('confirmed_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('ledger_entries')) {
            Schema::table('ledger_entries', function (Blueprint $table): void {
                if (! Schema::hasColumn('ledger_entries', 'ledger_transaction_id')) {
                    $table->string('ledger_transaction_id', 64)->nullable()->index();
                }
                if (! Schema::hasColumn('ledger_entries', 'direction')) {
                    $table->string('direction', 16)->nullable()->index();
                }
                if (! Schema::hasColumn('ledger_entries', 'sequence')) {
                    $table->unsignedInteger('sequence')->default(1);
                }
            });
        }

        if (Schema::hasTable('ledger_entries')) {
            Schema::table('ledger_entries', function (Blueprint $table): void {
                if (! Schema::hasColumn('ledger_entries', 'ledger_transaction_id')) {
                    $table->unsignedBigInteger('ledger_transaction_id')->nullable()->index();
                }
                if (! Schema::hasColumn('ledger_entries', 'account_id')) {
                    $table->unsignedBigInteger('account_id')->nullable()->index();
                }
                if (! Schema::hasColumn('ledger_entries', 'direction')) {
                    $table->string('direction', 16)->nullable();
                }
                if (! Schema::hasColumn('ledger_entries', 'sequence')) {
                    $table->unsignedInteger('sequence')->nullable();
                }
            });
        }

        if (Schema::hasTable('payouts')) {
            Schema::table('payouts', function (Blueprint $table): void {
                if (Schema::hasColumn('payouts', 'bet_id')) {
                    $table->unsignedBigInteger('bet_id')->nullable()->change();
                }
                if (Schema::hasColumn('payouts', 'wallet_id')) {
                    $table->unsignedBigInteger('wallet_id')->nullable()->change();
                }
                if (! Schema::hasColumn('payouts', 'lottery_entry_id')) {
                    $table->string('lottery_entry_id', 64)->nullable()->index();
                }
                if (! Schema::hasColumn('payouts', 'payout_number')) {
                    $table->string('payout_number', 64)->nullable()->index();
                }
                if (! Schema::hasColumn('payouts', 'prize_tier')) {
                    $table->string('prize_tier', 64)->nullable();
                }
                if (! Schema::hasColumn('payouts', 'winning_tier')) {
                    $table->string('winning_tier', 64)->nullable();
                }
                if (! Schema::hasColumn('payouts', 'gross_amount')) {
                    $table->decimal('gross_amount', 18, 4)->nullable();
                }
                if (! Schema::hasColumn('payouts', 'gross_payout_amount')) {
                    $table->decimal('gross_payout_amount', 19, 4)->nullable();
                }
                if (! Schema::hasColumn('payouts', 'wht_tax_amount')) {
                    $table->decimal('wht_tax_amount', 18, 4)->nullable();
                }
                if (! Schema::hasColumn('payouts', 'tax_withheld')) {
                    $table->decimal('tax_withheld', 19, 4)->nullable();
                }
                if (! Schema::hasColumn('payouts', 'net_amount')) {
                    $table->decimal('net_amount', 18, 4)->nullable();
                }
                if (! Schema::hasColumn('payouts', 'net_payout_amount')) {
                    $table->decimal('net_payout_amount', 19, 4)->nullable();
                }
                if (! Schema::hasColumn('payouts', 'payout_idempotency_key')) {
                    $table->string('payout_idempotency_key', 128)->nullable()->unique();
                }
                if (! Schema::hasColumn('payouts', 'payout_status')) {
                    $table->string('payout_status', 32)->nullable()->index();
                }
                if (! Schema::hasColumn('payouts', 'paid_at')) {
                    $table->timestamp('paid_at')->nullable()->index();
                }
                if (! Schema::hasColumn('payouts', 'settled_at')) {
                    $table->timestamp('settled_at')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        // Additive reconciliation columns intentionally preserved.
    }
};
