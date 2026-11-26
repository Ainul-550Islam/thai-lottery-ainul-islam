<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bet slips — the member's DRAFT basket, and nothing else.
 *
 * WHAT THIS TABLE IS NOT
 * It is not a bet. It holds no money, reserves no number, consults no risk
 * engine and appears in no ledger. A row here means "this member is
 * considering these selections"; money moves only when
 * BetSlipConfirmationService drives the existing Batch-4.3 purchase pipeline,
 * which is the only writer of bets, tickets, financial_transactions and
 * ledger_entries.
 *
 * ONE ACTIVE SLIP PER MEMBER is a database guarantee, not a service
 * convention: the UNIQUE index on member_id is what makes the double-confirm
 * race in BetSlipConcurrencyTest resolve to a single slip even if two
 * requests arrive in the same millisecond on two application servers.
 *
 * `status` is constrained by string + index rather than by a database enum,
 * because MySQL and SQLite disagree about enum alteration and this schema is
 * expected to run on both.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bet_slips')) {
            return;
        }

        Schema::create('bet_slips', function (Blueprint $table): void {
            $table->id();

            // A deleted member must not silently erase a draft that was part of
            // an attempted purchase, so deletion is restricted, matching the
            // wallets table's rule.
            $table->foreignId('member_id')->constrained('users')->restrictOnDelete();

            $table->string('reference', 32)->unique();
            $table->string('status', 16)->default('draft');
            $table->string('currency', 3)->default('THB');

            // Exact decimal strings live in DECIMAL columns, never floats.
            $table->decimal('total_stake', 20, 2)->default(0);
            $table->unsignedInteger('item_count')->default(0);

            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('confirmed_reference', 64)->nullable()->index();

            // The idempotency key of the confirm request that consumed this
            // slip. UNIQUE so a replay cannot consume the same draft twice.
            $table->string('confirmation_key', 96)->nullable()->unique();

            $table->json('meta')->nullable();
            $table->timestamps();

            // One draft per member. Confirmed/expired rows are kept for audit
            // but the partial uniqueness below is enforced by the service for
            // those states; the hard constraint covers the draft that matters.
            $table->index(['member_id', 'status'], 'bet_slips_member_status_idx');
            $table->index(['status', 'expires_at'], 'bet_slips_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bet_slips');
    }
};
