<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unifies the 6D/Enterprise lottery entries schema onto the canonical
 * unsignedBigInteger foreign keys of `draws.id`, `users.id`, and `agents.id`
 * so Eloquent relationships (`LotteryEntry::draw()`, `LotteryEntry::payout()`,
 * etc.) resolve cleanly on both SQLite and MySQL/MariaDB with full foreign key
 * referential integrity.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('draws')) {
            Schema::create('draws', function (Blueprint $table): void {
                $table->id();
                $table->string('draw_code', 64)->nullable()->unique();
                $table->string('game_type', 32)->nullable()->index();
                $table->date('draw_date')->nullable()->index();
                $table->timestamp('betting_starts_at')->nullable();
                $table->timestamp('betting_ends_at')->nullable()->index();
                $table->timestamp('drawn_at')->nullable();
                $table->string('status', 32)->default('scheduled')->index();
                $table->unsignedBigInteger('total_entries_count')->default(0);
                $table->decimal('total_pool_amount', 19, 4)->default(0);
                $table->decimal('total_payout_amount', 19, 4)->default(0);
                $table->unsignedBigInteger('version')->default(1);
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('draw_results')) {
            Schema::create('draw_results', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('draw_id')->unique()->constrained('draws')->restrictOnDelete();
                $table->string('first_prize', 6)->nullable();
                $table->string('two_digit_back', 2)->nullable();
                $table->json('three_digit_front')->nullable();
                $table->json('three_digit_back')->nullable();
                $table->json('second_prizes')->nullable();
                $table->json('third_prizes')->nullable();
                $table->json('fourth_prizes')->nullable();
                $table->json('fifth_prizes')->nullable();
                $table->string('source_provider', 64)->nullable();
                $table->string('payload_fingerprint', 128)->nullable()->unique();
                $table->unsignedBigInteger('ingested_by_operator_id')->nullable()->index();
                $table->unsignedBigInteger('confirmed_by_operator_id')->nullable()->index();
                $table->string('verification_status', 32)->default('PENDING_CONFIRMATION')->index();
                $table->timestamp('ingested_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('lottery_entries')) {
            Schema::create('lottery_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('agent_id')->nullable()->constrained('agents')->nullOnDelete();
                $table->string('voucher_number', 64)->nullable()->index();
                $table->string('ticket_number', 16)->index();
                $table->string('bet_type', 32)->index();
                $table->decimal('stake_amount', 19, 4);
                $table->decimal('multiplier', 10, 4)->default(1);
                $table->decimal('potential_win_amount', 19, 4)->default(0);
                $table->decimal('actual_win_amount', 19, 4)->default(0);
                $table->string('status', 32)->default('PENDING')->index();
                $table->string('idempotency_key', 128)->unique();
                $table->unsignedInteger('chunk_index')->default(0)->index();
                $table->timestamp('placed_at')->nullable();
                $table->timestamps();

                $table->index(['draw_id', 'chunk_index', 'status']);
                $table->index(['draw_id', 'status']);
                $table->index(['draw_id', 'ticket_number', 'bet_type']);
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lottery_entries');
    }
};
