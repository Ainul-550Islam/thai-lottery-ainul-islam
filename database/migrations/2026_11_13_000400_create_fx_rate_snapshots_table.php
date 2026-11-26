<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FX rate snapshots — the ONLY place a display exchange rate may come from.
 *
 * WHY A TABLE AND NOT A LIVE CALL
 * Batch 3 forbids outbound network calls by default (NullFxRateProvider) and
 * forbids inventing a rate. An admin who has a contractual rate enters it here,
 * with a source and a capture timestamp; FxDisplayService refuses to use a row
 * older than config('player_experience.wallet.fx_max_age_minutes').
 *
 * `rate` is a DECIMAL(24,12) string. It is a DISPLAY number: nothing in the
 * money path reads this table. Wallet conversions continue to use the audited
 * WalletConversion quote service, which has its own rate source and its own
 * ledger proof. FxDisplayService says so in its own docblock and Batch 3 wires
 * no conversion through it.
 *
 * `source` distinguishes `manual_admin` from a future automated importer, so an
 * audit can tell a human-entered number from a machine-entered one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fx_rate_snapshots')) {
            return;
        }

        Schema::create('fx_rate_snapshots', function (Blueprint $table): void {
            $table->id();

            $table->string('base', 3);
            $table->string('quote', 3);

            // 12 decimal places: a THB->USD display rate needs 6 to be useful
            // and 12 to be honest about a small value.
            $table->decimal('rate', 24, 12);

            $table->string('source', 40)->default('manual_admin');
            $table->timestamp('captured_at')->index();
            $table->timestamp('expires_at')->nullable();

            $table->foreignId('entered_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('reference', 96)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            // The lookup FxDisplayService actually performs: newest valid row
            // for a pair.
            $table->index(['base', 'quote', 'captured_at'], 'fx_rate_pair_captured_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rate_snapshots');
    }
};
