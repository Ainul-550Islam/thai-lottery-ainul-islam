<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runtime operational control over money movement.
 *
 * WHY A TABLE AND NOT A CONFIG KEY
 * `real_money.enabled` is the deployment kill switch and it is configuration:
 * changing it means editing a file or an environment and redeploying, which is
 * the right weight for a launch decision and far too slow for an incident. An
 * emergency shutdown has to be reachable by an authorized administrator while
 * the release stays exactly as it is, and it has to leave evidence behind - who
 * changed it, when, from what, to what, and why.
 *
 * ONE ROW PER CONTROL, NOT ONE ROW PER CHANGE
 * The current state is a single row keyed by `control_key`, so reading it is one
 * indexed lookup on the hottest possible path (every money admission consults
 * it) and so two administrators cannot each insert their own contradictory
 * "current" state. The history lives in `audit_logs`, which is append-only and
 * is where a reviewer looks; a state table that accumulates rows stops being
 * able to answer "what is the state right now" without an ordering argument.
 *
 * `revision` is the optimistic-concurrency token a caller can present to prove
 * it read the state it is now changing. `previous_state` is denormalised on
 * purpose: the row itself must be able to describe the last transition even if
 * the audit log is being queried from a different lane.
 *
 * DEFAULT IS CLOSED, AND ABSENCE IS CLOSED
 * The `state` default is 'closed' and the reading service treats a missing row,
 * an unreadable row and an unreachable database identically: money does not
 * move. There is no value this table can hold that opens anything on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_operation_controls', function (Blueprint $table): void {
            $table->id();

            // The control's identity, e.g. 'real_money.live_operations'. Unique,
            // so "the current state" is a single well-defined row.
            $table->string('control_key', 64)->unique();

            // 'closed' (money halted) or 'open' (this control permits, subject to
            // every other gate). Closed by default: a freshly migrated deployment
            // has never authorized anything.
            $table->string('state', 16)->default('closed');

            // The state this row held before its last transition, kept so one row
            // can describe the change it just made.
            $table->string('previous_state', 16)->nullable();

            // Why the change was made. Bounded: this is an operator sentence, not
            // a payload dump, and it is copied into the audit trail.
            $table->string('reason', 190)->nullable();

            // Who made the change. Nullable and nullOnDelete because a control
            // must survive the deletion of the administrator who last touched it:
            // the state of money movement cannot become unknown because a user row
            // was removed, and the audit log keeps the actor permanently.
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('changed_at')->nullable();

            // Optimistic-concurrency token: incremented on every transition, so a
            // writer can be refused for acting on a state that no longer exists.
            $table->unsignedBigInteger('revision')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_operation_controls');
    }
};
