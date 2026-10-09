<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook replay receipts.
 *
 * WHY A TABLE AND NOT JUST A CACHE KEY
 * The cache key is what REFUSES a replay. This table is what makes replays
 * VISIBLE. A cache that silently absorbs a thousand replayed `deposit.completed`
 * deliveries looks exactly like healthy traffic; a table with a `replayed` count
 * by gateway does not. On a money path, "we refused it" and "we know we are
 * being attacked" are different requirements.
 *
 * The nonce is UNIQUE, so the database itself is a second, independent replay
 * guard beneath the cache: if the cache is flushed, evicted, or shared
 * incorrectly across instances, the unique index still refuses the duplicate
 * within the signature window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('gateway', 64);

            // sha256 hex of gateway|timestamp|signature — the replay identity.
            $table->string('nonce', 64)->unique();

            $table->unsignedBigInteger('signature_timestamp');
            $table->string('status', 16)->default('accepted');
            $table->string('ip_address', 45)->nullable();
            $table->string('path', 255)->nullable();
            $table->timestamp('received_at')->useCurrent();

            // Replay triage: "everything refused on this gateway, newest first".
            $table->index(['gateway', 'status', 'received_at'], 'webhook_receipts_triage_index');
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_receipts');
    }
};
