<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time verification tokens for a public privacy request.
 *
 * ONLY THE HASH IS STORED. The plain token exists in the mail that carries it
 * and nowhere else: not in this table, not in a log line, not in an audit
 * payload. A leaked database therefore does not let anyone verify a request
 * they did not receive the mail for.
 *
 * SINGLE USE IS A COLUMN, NOT A CONVENTION. consumed_at is written inside the
 * same transaction that moves the request to 'received', so a replayed link
 * finds a consumed token and is refused. The unique index on token_hash means
 * two tokens can never be the same string.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('privacy_verification_tokens')) {
            return;
        }

        Schema::create('privacy_verification_tokens', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('request_id')
                ->constrained('data_subject_requests')
                ->cascadeOnDelete();

            // sha256 of the plain token. Unique so a token cannot be issued twice.
            $table->char('token_hash', 64)->unique();

            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_verification_tokens');
    }
};
