<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Five ADDITIVE, NULLABLE columns on the Batch-1 bets table.
 *
 * RULE §0.1 IN PRACTICE
 * Nothing is renamed, nothing is dropped, nothing is re-typed and no default is
 * added that would change what an existing row means. Every column below is
 * nullable with no default, so every Batch-1 row keeps exactly the values it had
 * (for these columns: none) and the GLO L6, PCSO 6D and Bingo/National paths
 * behave byte-for-byte as before. A dry-run purchase of a Batch-1 market leaves
 * all five NULL.
 *
 * WHY THESE FIVE AND NOT THE WHOLE ATTRIBUTION
 * These are the ones an operator queries or joins on: which game, which selection,
 * which market, which pot paid, which voucher. Everything descriptive — the
 * reserved payout, the settlement market key, the metadata — lives in
 * game_bet_attributions, because the Batch-1 ownership proof hashes
 * bets.metadata and Rule §0.2 forbids batch 2 redefining what that column means.
 *
 * WHY funding_source HAS NO DEFAULT
 * A default of 'real' would retroactively claim that every historical bet declared
 * itself as real money, when in truth the concept did not exist. NULL means "this
 * bet predates the concept"; App\Enums\BetFundingSource::of() maps NULL to Real,
 * and that single mapping is where the legacy meaning is written down.
 *
 * WHY game_number IS A STRING
 * "0042" is not 42. The column is varchar(16) and no cast anywhere may narrow it.
 *
 * NO FOREIGN KEY ON free_bet_voucher_id
 * The Batch-1 free-bet voucher lane does not exist in this repository (see
 * config('lottery_games.funding.free_bet_provider'), which is NULL for that
 * reason). A foreign key to a table that is not guaranteed to exist would make
 * this migration fail on a deployment without that lane, and §0.1 forbids making
 * the migration depend on a schema it does not own. The id is an indexable
 * nullable integer, resolved through the FreeBetVoucherProvider contract.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bets', function (Blueprint $table): void {
            // Which digit game this bet belongs to. NULL for every Batch-1 market.
            $table->string('game_code', 64)->nullable()->after('currency')->index();

            // The selection, leading zeros preserved. NULL for every Batch-1 market.
            $table->string('game_number', 16)->nullable()->after('game_code');

            // The digit-game market, e.g. 4d_direct. Distinct from `type`, which is
            // the Batch-1 BetType and is never written by this lane.
            $table->string('game_bet_type', 32)->nullable()->after('game_number')->index();

            // real | bonus | free_bet. NULL means the bet predates the concept.
            $table->string('funding_source', 16)->nullable()->after('game_bet_type')->index();

            // The voucher that funded a free bet, if any. No FK: see the docblock.
            $table->unsignedBigInteger('free_bet_voucher_id')->nullable()->after('funding_source');
        });

        Schema::table('bets', function (Blueprint $table): void {
            // One composite lookup answers the lane's own reporting question
            // ("all 4d_direct bets of speed_88 on draw X") without scanning.
            $table->index(['game_code', 'game_bet_type'], 'bets_game_code_game_bet_type_index');
            $table->index(['free_bet_voucher_id'], 'bets_free_bet_voucher_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table): void {
            $table->dropIndex('bets_game_code_game_bet_type_index');
            $table->dropIndex('bets_free_bet_voucher_id_index');
        });

        Schema::table('bets', function (Blueprint $table): void {
            $table->dropColumn([
                'game_code',
                'game_number',
                'game_bet_type',
                'funding_source',
                'free_bet_voucher_id',
            ]);
        });
    }
};
