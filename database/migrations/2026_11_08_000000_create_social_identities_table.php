<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verified third-party identities.
 *
 * Deliberately NOT users.provider / users.provider_id (migration 2026_10_26_000000):
 * those columns were filled by the retired implementation that linked accounts from an
 * email address alone, so a row there is not evidence of ownership. This table is only
 * ever written by (a) the Google sign-up flow after a verified id_token and consent, or
 * (b) a future session-bound "link Google" action. Legacy columns are left untouched and
 * are never read for authentication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_identities', function (Blueprint $table): void {
            $table->id();
            // RESTRICT, like the financial tables: an identity row must never vanish with a user.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_subject', 191);   // Google `sub` — the ONLY stable key
            $table->string('email_at_link', 255);       // evidence only; never used to look users up
            $table->boolean('email_verified')->default(false);
            $table->string('linked_via', 32);            // signup | session_link
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_subject']);  // one Google account -> one member
            $table->unique(['user_id', 'provider']);           // one member -> one Google account
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_identities');
    }
};
