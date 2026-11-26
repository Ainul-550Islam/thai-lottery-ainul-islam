<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The digit lane's own append-only audit trail.
 *
 * WHY THE LANE KEEPS ONE
 * Every decision this lane takes that moves or promises money — a bet placed, a rule set
 * verified, a game suspended or switched on, a result published, a liability reservation
 * given back — must be attributable afterwards to a person or a process, a time, and the
 * facts it was decided on. Batch 1 records its own decisions through event listeners; none
 * of those events describes a game decision, so without this table the lane would decide
 * in silence and an auditor would have only application logs to reconstruct it from.
 *
 * APPEND-ONLY BY CONSTRUCTION
 * The model refuses update and delete (see GameAuditEvent), the application never writes
 * here except through GameAuditRecorder (the architecture suite enforces that), and the
 * table has no `updated_at`: a row that cannot change needs no change timestamp.
 *
 * `context` IS JSON, NEVER MONEY ARITHMETIC
 * Amounts are stored inside it as decimal strings, exactly as the lane handled them.
 * Nothing sums or compares them in SQL.
 *
 * NO FOREIGN KEYS
 * An audit row must outlive the thing it describes and must be writable when the thing it
 * describes failed to be created (a placement that was refused has no bet). Subjects are
 * therefore named by (subject_type, subject_id) strings, not constrained references.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_audit_events', function (Blueprint $table): void {
            $table->id();

            // A stable, dotted event name, e.g. bet.placed or rules.verified.
            $table->string('event', 64);

            // Null for events that belong to no single game (a rules import covering both).
            $table->string('game_code', 64)->nullable();

            // The operator or player who caused it; null for a scheduled or CLI process.
            $table->unsignedBigInteger('actor_id')->nullable();

            // What the event is about, as strings: bet / draw / import / game.
            $table->string('subject_type', 32)->nullable();
            $table->string('subject_id', 64)->nullable();

            // The facts the decision was taken on.
            $table->json('context')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['game_code', 'event'], 'game_audit_events_game_code_event_index');
            $table->index(['subject_type', 'subject_id'], 'game_audit_events_subject_index');
            $table->index('occurred_at', 'game_audit_events_occurred_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_audit_events');
    }
};
