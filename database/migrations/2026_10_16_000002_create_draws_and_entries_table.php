<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations for Draws, Entries, and Settlement Payouts.
     */
    public function up(): void
    {
        // 1. Draws Table
        Schema::create('draws', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('draw_code', 32)->unique();
            $table->string('game_type', 32)->default('THAI_6D'); // THAI_2D, THAI_3D, THAI_6D
            $table->timestamp('draw_date');
            $table->timestamp('betting_starts_at');
            $table->timestamp('betting_ends_at');
            $table->string('status', 24)->default('UPCOMING'); // UPCOMING, OPEN, CLOSED, SETTLING, SETTLED, CANCELLED
            $table->unsignedBigInteger('total_entries_count')->default(0);
            $table->decimal('total_pool_amount', 20, 4)->default('0.0000');
            $table->decimal('total_payout_amount', 20, 4)->default('0.0000');
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();

            $table->index(['status', 'draw_date'], 'idx_draws_status_date');
            $table->index(['game_type', 'status'], 'idx_draws_game_status');
        });

        // 2. Draw Results Table (Strict 4-Eyes verification)
        Schema::create('draw_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('draw_id')->unique();
            $table->string('first_prize', 16);
            $table->json('three_digit_front')->nullable();
            $table->json('three_digit_back')->nullable();
            $table->string('two_digit_back', 8);
            $table->json('adjacent_first_prizes')->nullable();
            $table->json('second_prizes')->nullable();
            $table->json('third_prizes')->nullable();
            $table->json('fourth_prizes')->nullable();
            $table->json('fifth_prizes')->nullable();
            $table->string('source_provider', 64)->default('GLO_OFFICIAL');
            $table->string('payload_fingerprint', 64)->unique();
            $table->uuid('ingested_by_operator_id');
            $table->uuid('confirmed_by_operator_id')->nullable();
            $table->string('verification_status', 24)->default('PENDING_CONFIRMATION'); // PENDING_CONFIRMATION, CONFIRMED, REJECTED
            $table->timestamp('ingested_at')->useCurrent();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign('draw_id')->references('id')->on('draws')->onDelete('restrict');
            $table->index('verification_status', 'idx_draw_results_verification');
        });

        // 3. Lottery Entries / Vouchers Table
        Schema::create('lottery_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('draw_id')->index();
            $table->uuid('user_id')->index();
            $table->uuid('agent_id')->nullable()->index();
            $table->string('voucher_number', 64)->unique();
            $table->string('ticket_number', 16);
            $table->string('bet_type', 32); // DIRECT_6D, FRONT_3D, BACK_3D, BACK_2D, RUNNING_NUMBER
            $table->decimal('stake_amount', 16, 4);
            $table->decimal('multiplier', 10, 2)->default(1.00);
            $table->decimal('potential_win_amount', 20, 4)->default('0.0000');
            $table->decimal('actual_win_amount', 20, 4)->default('0.0000');
            $table->string('status', 24)->default('PENDING'); // PENDING, WON, LOST, REFUNDED, SETTLED
            $table->string('idempotency_key', 128)->unique();
            $table->unsignedBigInteger('chunk_index')->default(0)->index();
            $table->timestamp('placed_at')->useCurrent();
            $table->timestamps();

            $table->foreign('draw_id')->references('id')->on('draws')->onDelete('restrict');
            $table->index(['draw_id', 'status', 'chunk_index'], 'idx_entries_draw_status_chunk');
            $table->index(['user_id', 'placed_at'], 'idx_entries_user_placed');
            $table->index(['ticket_number', 'bet_type'], 'idx_entries_ticket_bet_type');
        });

        // 4. Payouts Table
        Schema::create('payouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('draw_id')->index();
            $table->uuid('lottery_entry_id')->index();
            $table->uuid('user_id')->index();
            $table->string('payout_number', 64)->unique();
            $table->string('winning_tier', 32); // FIRST_PRIZE, THREE_FRONT, THREE_BACK, TWO_BACK, etc.
            $table->decimal('gross_payout_amount', 20, 4);
            $table->decimal('tax_withheld', 16, 4)->default('0.0000');
            $table->decimal('net_payout_amount', 20, 4);
            $table->string('status', 24)->default('PROCESSING'); // PROCESSING, COMPLETED, FAILED, REVERSED
            $table->string('payout_idempotency_key', 128)->unique();
            $table->uuid('ledger_transaction_id')->nullable()->index();
            $table->timestamp('settled_at')->useCurrent();
            $table->timestamps();

            $table->unique(['draw_id', 'lottery_entry_id'], 'uq_payout_draw_entry');
            $table->foreign('draw_id')->references('id')->on('draws')->onDelete('restrict');
            $table->foreign('lottery_entry_id')->references('id')->on('lottery_entries')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payouts');
        Schema::dropIfExists('lottery_entries');
        Schema::dropIfExists('draw_results');
        Schema::dropIfExists('draws');
    }
};
