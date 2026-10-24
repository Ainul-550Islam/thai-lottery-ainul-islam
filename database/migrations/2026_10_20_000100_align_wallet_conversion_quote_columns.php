<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconcile the two shapes the quote table was created with.
 *
 * `wallet_conversion_quotes` exists in two declarations in this repository — an
 * earlier one and the lane's current one — and both are guarded by hasTable(),
 * so whichever ran first decided the columns and the other silently did nothing.
 * The result was a table the quoting service could not write to: it stores the
 * tariff it priced with (`fee_rate`), the channel the member asked from
 * (`channel`), the hash of the request that produced the quote
 * (`request_fingerprint`), the limits that applied (`limits`) and when it was
 * issued (`issued_at`), and none of those columns existed.
 *
 * This migration is additive and guarded, so it is safe on a database that was
 * created by either declaration: it only adds what is genuinely absent and never
 * drops, renames or rewrites anything. Existing rows keep every value they had;
 * the new columns are nullable or zero-defaulted precisely because old quotes
 * cannot be back-filled with facts nobody recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->alignConversionMovements();
        $this->alignQuotes();
    }

    /**
     * The SAME drift, one table over: `wallet_conversions` is also declared twice
     * behind `Schema::hasTable()`, and the winning declaration lacks six columns the
     * lane writes — the fee bearer, the operator who acted, the request fingerprint,
     * the context, and the two "before" balance snapshots.
     *
     * They are added here rather than by editing either declaration, because on a
     * database that has already migrated, editing history changes nothing: the table
     * was built by whichever declaration ran first and no migration would ever run
     * again to fix it.
     */
    private function alignConversionMovements(): void
    {
        if (! Schema::hasTable('wallet_conversions')) {
            return;
        }

        Schema::table('wallet_conversions', function (Blueprint $table): void {
            if (! Schema::hasColumn('wallet_conversions', 'fee_bearer')) {
                // Who paid the fee. Nullable: rows written before this column existed
                // were charged under the old bearer rule and cannot be re-attributed.
                $table->string('fee_bearer', 16)->nullable();
            }

            if (! Schema::hasColumn('wallet_conversions', 'actor_user_id')) {
                // The operator who reversed this conversion, when one did. Null on
                // member-originated rows, which is a fact, not a missing value.
                $table->foreignId('actor_user_id')->nullable();
            }

            if (! Schema::hasColumn('wallet_conversions', 'request_fingerprint')) {
                // The canonical identity of the request that produced this row. The
                // accounting proof re-derives it and refuses when it does not match,
                // so it must exist on every row the engine writes.
                $table->char('request_fingerprint', 64)->nullable();
            }

            if (! Schema::hasColumn('wallet_conversions', 'metadata')) {
                $table->json('metadata')->nullable();
            }

            if (! Schema::hasColumn('wallet_conversions', 'from_balance_before')) {
                // The snapshots that make the movement arithmetically checkable: the
                // "after" balances alone cannot show that money moved by the amount
                // claimed, only where it ended up.
                $table->decimal('from_balance_before', 18, 2)->nullable();
            }

            if (! Schema::hasColumn('wallet_conversions', 'to_balance_before')) {
                $table->decimal('to_balance_before', 18, 2)->nullable();
            }
        });
    }

    private function alignQuotes(): void
    {
        if (! Schema::hasTable('wallet_conversion_quotes')) {
            return;
        }

        Schema::table('wallet_conversion_quotes', function (Blueprint $table): void {
            if (! Schema::hasColumn('wallet_conversion_quotes', 'fee_rate')) {
                $table->decimal('fee_rate', 12, 8)->default(0);
            }

            if (! Schema::hasColumn('wallet_conversion_quotes', 'channel')) {
                $table->string('channel', 32)->nullable();
            }

            if (! Schema::hasColumn('wallet_conversion_quotes', 'request_fingerprint')) {
                $table->char('request_fingerprint', 64)->nullable();
            }

            if (! Schema::hasColumn('wallet_conversion_quotes', 'limits')) {
                $table->json('limits')->nullable();
            }

            if (! Schema::hasColumn('wallet_conversion_quotes', 'issued_at')) {
                $table->timestamp('issued_at')->nullable();
            }

            // `rate` is the conversion rate the quote was priced at, and the older
            // declaration made it NOT NULL. A default of zero would be a lie about a
            // price, so the column is made nullable here instead: a null rate means
            // "this row was written before rates were recorded", which is the truth.
            if (Schema::hasColumn('wallet_conversion_quotes', 'rate')) {
                $table->decimal('rate', 12, 8)->nullable()->change();
            }

            if (! Schema::hasColumn('wallet_conversion_quotes', 'description')) {
                $table->text('description')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo: these columns are shared with the lane's declaration and
        // dropping them would break whichever schema created the table.
    }
};
