<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily wallet balance snapshots — the baseline a trend percentage is measured
 * against.
 *
 * WHY A SNAPSHOT AND NOT A QUERY OVER HISTORY
 * "Your balance is up 5.2% this week" must be reproducible and cheap. Replaying
 * a week of ledger entries for every wallet-overview request would be neither.
 * One row per (member, wallet_type, day) makes the trend an O(1) comparison and
 * makes the number auditable: anyone can look at the row the percentage came
 * from.
 *
 * IDEMPOTENT BY CONSTRAINT
 * UNIQUE(member_id, wallet_type, snapshot_date) means a re-run on the same day
 * updates rather than duplicates. SnapshotWalletBalancesJob relies on that
 * instead of a cache flag, so a crashed run is simply re-run.
 *
 * THIS TABLE NEVER DRIVES MONEY. It is a read-model side-car: nothing in the
 * wallet or ledger path reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wallet_balance_snapshots')) {
            return;
        }

        Schema::create('wallet_balance_snapshots', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('member_id')->constrained('users')->cascadeOnDelete();
            $table->string('wallet_type', 32);
            $table->string('currency', 3)->default('THB');

            // The wallet's balance AS READ from the wallets table at capture
            // time. DECIMAL, exact.
            $table->decimal('balance', 20, 2)->default(0);
            $table->decimal('locked_balance', 20, 2)->default(0);

            $table->date('snapshot_date');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(
                ['member_id', 'wallet_type', 'snapshot_date'],
                'wallet_balance_snapshots_unique_day'
            );

            $table->index(['member_id', 'snapshot_date'], 'wallet_balance_snapshots_member_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_balance_snapshots');
    }
};
