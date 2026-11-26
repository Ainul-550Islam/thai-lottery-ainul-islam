<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a fraud alert a real link to the player it is about.
 *
 * THE DEFECT THIS CLOSES
 *
 * `fraud_alerts.user_id` is a `uuid` column while `users.id` is a `bigint`. No relation can be
 * declared across that gap, so the panel could only print the identifier the alert happened to
 * carry — which meant an investigator looking at a suspicion could not open the account, its
 * wallet or its bet history without copying a string into a search box, and no widget could
 * aggregate per player.
 *
 * WHY A NEW COLUMN AND NOT A RETYPE
 *
 * Retyping `user_id` would rewrite evidence: the column holds whatever the detection lane stamped,
 * which is not guaranteed to be an id at all (event payloads may carry an external subject
 * reference). A retype would either destroy those values or fail on them. So the original column
 * stays exactly as it is, and a NULLABLE `player_user_id` is added beside it: the alert keeps its
 * historical subject reference, and gains a resolvable pointer when one exists.
 *
 * NO DATA IS INVENTED: the backfill only fills rows whose `user_id` is a numeric string that
 * actually matches a `users.id`. Everything else stays NULL — an alert with an unresolvable
 * subject is a fact, not something to paper over.
 *
 * THE FOREIGN KEY IS DELIBERATELY `nullOnDelete`, and only added where the driver supports adding
 * one in place (SQLite cannot). Deleting a player must not delete the record of the suspicion
 * raised about them; it must only break the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fraud_alerts')) {
            return;
        }

        if (! Schema::hasColumn('fraud_alerts', 'player_user_id')) {
            Schema::table('fraud_alerts', function (Blueprint $table): void {
                $table->unsignedBigInteger('player_user_id')->nullable()->after('user_id');
                $table->index('player_user_id', 'idx_fraud_alerts_player');
            });
        }

        // Backfill: only numeric strings that resolve to a real account.
        //
        // The numeric test is done in PHP, not in SQL, for two reasons. `GLOB` — which the first
        // draft used — is a SQLite extension: on MariaDB/MySQL this migration aborted with a
        // syntax error, i.e. the rollout broke on the engine production actually runs. And the
        // rule here must be the SAME rule the writer uses (`FraudEventRecorder::resolvePlayerId()`),
        // so that a value can never be linked by the backfill and refused by the recorder.
        if (Schema::hasTable('users')) {
            DB::table('fraud_alerts')
                ->whereNull('player_user_id')
                ->orderBy('id')
                ->chunkById(500, function ($rows): void {
                    foreach ($rows as $row) {
                        $subject = (string) ($row->user_id ?? '');

                        if (preg_match('/^[0-9]{1,18}$/', $subject) !== 1) {
                            continue;
                        }

                        $candidate = (int) $subject;

                        if ($candidate > 0 && DB::table('users')->where('id', $candidate)->exists()) {
                            DB::table('fraud_alerts')
                                ->where('id', $row->id)
                                ->update(['player_user_id' => $candidate]);
                        }
                    }
                }, 'id');
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('fraud_alerts', function (Blueprint $table): void {
                $table->foreign('player_user_id', 'fk_fraud_alerts_player')
                    ->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('fraud_alerts') || ! Schema::hasColumn('fraud_alerts', 'player_user_id')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::table('fraud_alerts', function (Blueprint $table): void {
                $table->dropForeign('fk_fraud_alerts_player');
            });
        }

        Schema::table('fraud_alerts', function (Blueprint $table): void {
            $table->dropIndex('idx_fraud_alerts_player');
            $table->dropColumn('player_user_id');
        });
    }
};
