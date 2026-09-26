<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative date of birth for server-side age calculation.
 *
 * GLO prize payment requires claimants aged 20+. Age must be derived from a
 * verified date of birth against the claim/payment date — never from a
 * client-supplied integer. This column is nullable: absence means age cannot
 * be proven and payment fails closed. It is populated only through verified
 * identity evidence flows, never mass-assigned from claim request bodies.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->date('date_of_birth')->nullable()->after('avatar_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('date_of_birth');
        });
    }
};
