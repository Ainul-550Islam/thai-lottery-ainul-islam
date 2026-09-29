<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROMPT 2 — the append-only per-game discount snapshot table.
 *
 * One row per recorded application of a discount to a game quote:
 * the grade that applied, the game rule percentage (or its explicit
 * absence), the eligibility STATE, the final money figures and both
 * rule versions. Rows are never updated — history is evidence.
 *
 * The nullable `subject` morph lets a snapshot attach to whatever it
 * was taken for (a bet, a ticket, a quote) or stand alone. The user
 * FK is RESTRICT on delete: snapshots of a person's pricing history
 * must not silently vanish when the person is deleted — they are
 * financial evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('grade_discount_snapshots')) {
            return;
        }

        Schema::create('grade_discount_snapshots', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->nullableMorphs('subject');

            $table->string('lottery');
            $table->string('game');
            $table->string('mode')->nullable();

            $table->string('grade_key');
            $table->decimal('grade_discount_rate', 12, 4)->default('0.0000');
            $table->decimal('game_discount_percent', 12, 2)->nullable();

            $table->string('eligibility_state');

            $table->decimal('final_applied_discount', 12, 2)->default('0.00');
            $table->decimal('gross_stake', 12, 2)->default('0.00');
            $table->decimal('net_stake', 12, 2)->default('0.00');

            $table->string('game_rule_version');
            $table->string('grade_rule_version');
            $table->dateTime('effective_at');
            $table->string('rule_version');

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'effective_at']);
            $table->index(['user_id', 'game']);
            $table->index(['game', 'rule_version']);
            $table->index('effective_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_discount_snapshots');
    }
};
