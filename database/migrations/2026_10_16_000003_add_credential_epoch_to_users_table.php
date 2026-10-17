<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds users.credential_epoch, the account's credential generation counter.
 *
 * Every login stamps the current epoch onto the issued session
 * (`credential_epoch_{guard}`; see PasswordLifecycleSessionGuard), and the
 * auth.session middleware retires any session stamped with an older value.
 * Password changes and operator revocations advance the counter, which is
 * what makes "end every session this account has, everywhere, now" a single
 * atomic increment instead of a hunt through session stores.
 *
 * It is a generation counter, not a timestamp: it only moves forward, it
 * moves by exactly one per revocation, and comparing two values answers
 * "which credential era issued this session" without any clock assumptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('credential_epoch')->default(0)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('credential_epoch');
        });
    }
};
