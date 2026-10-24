<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Lifecycle campaign send ledger (D4).
 *
 * WHY THIS TABLE IS THE MOST IMPORTANT FILE IN D4
 * -----------------------------------------------
 * A lifecycle campaign is a standing query: "every registered player who has
 * not deposited in 24 hours". It is evaluated on a schedule against live data,
 * which means a player can match the query on run after run after run.
 *
 * Without a durable record of who has already been sent a given campaign, a
 * scheduler that runs twice — a retried cron, a second worker, a manual run —
 * emails the same people again. On a real-money product that is not a minor
 * annoyance: it is the single fastest way to get a sending domain blocked and
 * to turn a retention programme into a churn programme.
 *
 * So the guarantee is enforced by a UNIQUE constraint on (user_id, campaign),
 * not by application logic. The application asks the database; the database
 * refuses. An application-level "have I sent this?" check can be raced, and a
 * cache-backed one can be evicted. A unique index cannot be either.
 *
 * WHY (user_id, campaign) AND NOT (user_id, campaign, context)
 * A player gets ONE welcome, ONE first-deposit nudge and ONE win-back per
 * dormancy. Sending a second win-back six months later is a new campaign with
 * a new name, not a retry of this one — that keeps the rule simple enough to
 * reason about at 3am, which is when it will matter.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lifecycle_campaign_sends')) {
            Schema::create('lifecycle_campaign_sends', function (Blueprint $table): void {
                $table->id();
    
                $table->foreignId('user_id')
                    ->constrained('users')
                    ->cascadeOnDelete();
    
                $table->string('campaign', 32);
    
                // Nullable and set only on success. A row with sent_at = null is a
                // claim that the campaign was attempted and must not be retried
                // automatically; a failure is investigated, not re-run, for the
                // reason in the class docblock.
                $table->timestamp('sent_at')->nullable()->index();
    
                // Why the attempt did not send, when it did not. Kept as a short
                // machine code so this table stays narrow — it is read on every
                // campaign run for every candidate.
                $table->string('skip_reason', 32)->nullable();
    
                $table->timestamp('created_at')->useCurrent();
    
                // THE IDEMPOTENCY GUARANTEE ITSELF.
                $table->unique(['user_id', 'campaign'], 'lifecycle_campaign_sends_user_campaign_unique');
    
                // Supports "how did yesterday's run do?" without a table scan.
                $table->index(['campaign', 'created_at'], 'lifecycle_campaign_sends_campaign_created_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_campaign_sends');
    }
};
