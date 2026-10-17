<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds users.last_seen_at, the member's last authenticated activity time.
 *
 * Written by TrackMemberPresence (at most one write per member per minute,
 * cache-locked) and read by GloPublicStatsService::activeMembersNow() for
 * the public "members active" counter. Nullable: a member who has never
 * made an authenticated request has no presence fact, and absence must
 * never be read as "active now".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('last_seen_at')->nullable()->after('last_login_at');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn('last_seen_at');
        });
    }
};
