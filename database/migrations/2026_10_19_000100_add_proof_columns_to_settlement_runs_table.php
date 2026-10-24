<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The proof a chunked settlement run has to carry.
 *
 * A cursor and a counter are PROGRESS, not EVIDENCE. A row with
 * cursor_bet_id = max(id) and bets_settled = bets_total proves only that two
 * integers were written; it cannot distinguish a finished run from a forged
 * one, and it cannot tell a finalizer that the outcomes it is about to seal are
 * the outcomes the chunks actually produced.
 *
 * These columns are that evidence, frozen at open and re-proved at close:
 *
 *   max_bet_id              The highest bet id that belonged to the draw when
 *                           the run was opened. A bet added later is outside the
 *                           frozen set and must be refused, not quietly settled
 *                           by a later chunk.
 *   participants_fingerprint  Hash over the frozen participant set AND their
 *                           selection INPUTS (number, stake, multiplier). A bet
 *                           removed mid-run, a slip whose items were deleted, or
 *                           a multiplier edited between chunks all change it.
 *   approval_threshold      The payout approval threshold in force when the run
 *                           opened, as a decimal string. A threshold raised
 *                           mid-run must not turn a payout that needed approval
 *                           into an automatic one; a lowered threshold still
 *                           applies, because stricter is always allowed.
 *   outcomes_fingerprint    Hash over the OUTCOMES written so far (per bet:
 *                           winner flag, actual payout, settled status). This is
 *                           what makes a tampered `payouts` or `bet_items` row
 *                           visible at finalization instead of invisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('settlement_runs', 'max_bet_id')) {
                $table->unsignedBigInteger('max_bet_id')->default(0);
            }

            if (! Schema::hasColumn('settlement_runs', 'participants_fingerprint')) {
                $table->string('participants_fingerprint', 64)->nullable();
            }

            if (! Schema::hasColumn('settlement_runs', 'approval_threshold')) {
                $table->decimal('approval_threshold', 20, 2)->nullable();
            }

            if (! Schema::hasColumn('settlement_runs', 'outcomes_fingerprint')) {
                $table->string('outcomes_fingerprint', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settlement_runs', function (Blueprint $table): void {
            $table->dropColumn(['max_bet_id', 'participants_fingerprint', 'approval_threshold', 'outcomes_fingerprint']);
        });
    }
};
