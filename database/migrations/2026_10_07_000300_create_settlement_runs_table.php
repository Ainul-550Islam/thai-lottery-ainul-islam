<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progress ledger for a chunked real-money settlement run.
 *
 * ============================================================================
 * THE PROBLEM THIS SOLVES
 * ============================================================================
 * RealPrizeSettlementService::settle() settles one draw inside ONE database
 * transaction, and that transaction does the following before it commits:
 *
 *   - locks EVERY bet row of the draw          (Bet::where('draw_id', …)->lockForUpdate()->get())
 *   - issues one items query PER bet            (lockedItems($betId) — an N+1)
 *   - accumulates one SettlementSelectionResult per SELECTION in memory, and
 *     hands the whole list back inside SettlementResult
 *   - creates one payouts row and one wallet credit per winning bet
 *
 * At a few thousand slips that is merely inefficient. At the target — 100,000+
 * slips in a single draw — it is three separate failures at once:
 *
 *   1. LOCK DURATION. The draw's bet range is write-locked for the entire run.
 *      Nothing else can settle, cancel or amend a bet in that draw, and the
 *      transaction holds until the LAST winner on the last slip is paid.
 *   2. MEMORY. 100,000 slips × several selections each is a six-figure list of
 *      DTOs, every one of them retained until the transaction commits.
 *   3. NO RESUMPTION. A failure at slip 99,000 rolls back the whole run. There
 *      is no partial progress to restart from; the next attempt starts at zero
 *      and re-locks everything.
 *
 * ============================================================================
 * WHAT IS RECORDED
 * ============================================================================
 * One row per settlement run, holding the cursor and the running totals. The
 * cursor is `cursor_bet_id`, and the next chunk is
 *
 *     where draw_id = ? and id > cursor_bet_id order by id limit chunk_size
 *
 * `id >` rather than an OFFSET is deliberate: offset pagination re-scans and
 * re-locks everything before the offset, so chunk 100 would lock as much as
 * chunk 1 plus 99 empty scans — the opposite of what chunking is for.
 *
 * ============================================================================
 * WHY draw_id IS UNIQUE
 * ============================================================================
 * A draw may have at most ONE run row. That is what stops two schedulers, two
 * queue workers, or an operator pressing the button twice from opening two runs
 * over the same draw and interleaving their chunks. The second claimant does not
 * "also settle" — it fails to insert, and the code reads back the run that
 * exists. It is the same INSERT-first discipline as the webhook replay guard,
 * for the same reason: a read-then-write check has a window, a unique index does
 * not.
 *
 * The row is NOT deleted on completion. It is the record of how the draw was
 * settled — how many slips, how much stake, how much prize, which mode, and who
 * started it — and `status = completed` plus `completed_at` is what makes a
 * second run idempotent rather than merely unlikely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlement_runs', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('draw_id')->unique('settlement_runs_draw_unique')
                ->constrained('draws')->restrictOnDelete();

            // open    — chunks in flight; the draw is NOT settled yet
            // completed — every bet processed and the draw transitioned
            // aborted — stopped deliberately, with a reason; resumable
            $table->string('status', 16)->default('open')->index();

            // Bets processed per chunk. Recorded on the row rather than read from
            // config at each chunk, so a config change mid-run cannot change the
            // chunk size under a run that is already part-way through.
            $table->unsignedInteger('chunk_size')->default(500);

            // The resume point. The next chunk selects bets with id GREATER than
            // this, so a crash resumes exactly where it stopped.
            $table->unsignedBigInteger('cursor_bet_id')->default(0);

            // Denominators and progress.
            $table->unsignedBigInteger('bets_total')->default(0);
            $table->unsignedBigInteger('bets_settled')->default(0);

            $table->unsignedBigInteger('selections_evaluated')->default(0);
            $table->unsignedBigInteger('selections_written')->default(0);
            $table->unsignedBigInteger('winning_selections')->default(0);
            $table->unsignedBigInteger('payouts_created')->default(0);

            // Running money totals, as decimal strings. BCMath domain, never
            // float — a settlement total computed in binary floating point is a
            // total nobody can reconcile.
            $table->decimal('total_stake', 20, 2)->default('0');
            $table->decimal('total_prize', 20, 2)->default('0');

            // The currency the run has seen so far. A draw mixes currencies only
            // if something is badly wrong; the service refuses rather than sums.
            $table->string('currency', 8)->nullable();

            // monetary | simulation — which service drove the run.
            $table->string('mode', 16)->default('monetary');

            // DISABLED | DRY_RUN | LIVE at the time the run was opened. Recorded,
            // not re-read, for the same reason as chunk_size: a run that started
            // paying under LIVE must not change behaviour half-way through
            // because a config value was edited.
            $table->string('safety_mode', 16)->nullable();

            $table->unsignedInteger('chunks_processed')->default(0);

            $table->timestamp('started_at');
            $table->timestamp('last_chunk_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('failure_reason', 500)->nullable();

            $table->timestamps();

            // Operator triage: which runs are still in flight.
            $table->index(['status', 'last_chunk_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlement_runs');
    }
};
