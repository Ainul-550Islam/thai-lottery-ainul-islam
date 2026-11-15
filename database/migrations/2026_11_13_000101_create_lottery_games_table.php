<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator record of each digit game: the decision half of a game's identity.
 *
 * WHAT IS DELIBERATELY ABSENT
 * No digit count, no multiplier, no bet type list, no draw cron, no cutoff, no
 * stake bound, no liability cap. Every one of those is an official rule value and
 * lives in config/lottery_games.php, read only through GameRegistry. Copying them
 * into a table would create a second truth that drifts the first time an operator
 * edits the config, and a stale multiplier in a database is a silent liability.
 *
 * `status` — WHY THE SPEC'S TWO COLUMNS BECAME ONE
 * The batch-2 tree asks for both `status` and `rules_status`. They would hold the
 * same three values (unverified|verified|suspended) and nothing would keep them
 * in step except discipline, so there is exactly one column, `status`, cast to
 * App\Enums\GameRulesStatus. This is the column the Filament verify/suspend
 * actions and the test helper write; a second column would let the two disagree
 * about whether a game may sell.
 *
 * `rules_checksum` — WHAT MAKES VERIFICATION MEANINGFUL
 * An operator verifies a specific rule set, not a game in the abstract. The
 * checksum of the configuration block the operator saw is stamped here at
 * verification time, and GameRegistry refuses to treat the game as verified once
 * the live configuration hashes to something else. Without it, editing a
 * multiplier would silently inherit yesterday's sign-off.
 *
 * `verified_by` / `verified_at` are kept when a game is suspended: an auditor
 * must be able to see who verified which rule set while it was selling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottery_games', function (Blueprint $table): void {
            $table->id();

            // The stable machine identity. It is stored on draws.game_type and on
            // bets.game_code, so it must never be reused or renamed.
            $table->string('code', 64)->unique();

            $table->string('name', 128);

            // unverified | verified | suspended — App\Enums\GameRulesStatus.
            $table->string('status', 16)->default('unverified')->index();

            // SHA-256 of the configuration block the verification was made
            // against. NULL until an operator verifies.
            $table->string('rules_checksum', 64)->nullable();

            // The operator's on/off switch. Default false: a game ships closed and
            // only an explicit, permission-gated action opens it.
            $table->boolean('enabled')->default(false)->index();

            // Which operator seat made the decision. Null on delete keeps the row
            // (and the audit) when a staff account is removed.
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('verified_at')->nullable();

            // Operational annotations only — never a rule value: suspension reason,
            // which operator suspended, when. See LotteryGame::markSuspended().
            $table->json('metadata')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottery_games');
    }
};
