<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part 57 — a monotonic credential generation counter on the account.
 *
 * WHY A NUMBER AND NOT ONLY A HASH
 * Every session-authenticated surface so far compares the session's stored
 * `password_hash_{guard}` with the account's current hash. That comparison
 * answers "does this session carry the current hash?" but it cannot answer
 * "which credential GENERATION does this session belong to?", and it cannot
 * be moved by anything other than a password write. Two consequences:
 *
 *  - Revocation without a password change is impossible. An operator who
 *    must end every live session — restriction, permission change, a
 *    suspected takeover where the password is still valid — has no lever
 *    that the session middleware can see.
 *  - A hash is a value, not an order. Anything that restores an old row
 *    (backup, manual fix, non-cooperating writer) can present a hash that
 *    matches what a live session already carries.
 *
 * The counter is monotonic by construction: it is only ever incremented,
 * inside the same transaction as the credential write, so a session can be
 * compared against the generation that was current when it was issued.
 *
 * ROLLOUT (deliberate, stated)
 * Existing rows start at 0 and existing sessions carry no epoch key, so the
 * first covered request after deployment retires them once — a re-login, not
 * a lockout, exactly as Part 52's legacy-session retirement behaved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'credential_epoch')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedBigInteger('credential_epoch')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'credential_epoch')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('credential_epoch');
            });
        }
    }
};
