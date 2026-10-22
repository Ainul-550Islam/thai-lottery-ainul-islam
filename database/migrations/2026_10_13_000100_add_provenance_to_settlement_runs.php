<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pins a chunked settlement run to the provenance it was opened against.
 *
 * result_fingerprint — hash of the published result (numbers, first prize,
 *   bottom two, four-eyes ingestion record) at open. Every chunk recomputes it
 *   and refuses to pay if it has changed, so a result edited mid-run cannot
 *   quietly change the prizes the rest of the run pays.
 *
 * policy_fingerprint — hash of the payout approval thresholds at open. A
 *   threshold changed mid-run would change which winners need approval; the run
 *   refuses to continue under a different policy rather than mixing the two.
 *
 * Both are copied onto every payout the run creates, so reconciliation can
 * check each payout against the run that produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlement_runs', function (Blueprint $table): void {
            $table->string('result_fingerprint', 64)->nullable();
            $table->string('policy_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settlement_runs', function (Blueprint $table): void {
            $table->dropColumn(['result_fingerprint', 'policy_fingerprint']);
        });
    }
};
