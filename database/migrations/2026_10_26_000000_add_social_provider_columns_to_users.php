<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Social login provider columns.
 *
 * Links a local member account to a third-party OAuth provider
 * (Facebook, Twitter/X, Google+). provider + provider_id together
 * form a unique constraint so one social identity cannot claim two
 * local accounts, and one local account cannot bind two social
 * identities from the same provider.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('provider')->nullable()->after('password');
            $table->string('provider_id')->nullable()->after('provider');
            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'provider_id']);
            $table->dropColumn(['provider_id', 'provider']);
        });
    }
};
