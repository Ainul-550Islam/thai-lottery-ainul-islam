<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support conversation rating — ALREADY EXISTS AS A CONVERSATION TABLE.
 *
 * REPLACED BY REUSE (Phase 0 finding).
 * `chat_conversations` + `chat_messages` already exist with a richer shape
 * than the mockup's plan (2026_10_13_000300_create_chat_tables.php, reconciled
 * in 2026_10_21_000009_create_chat_tables_and_reconcile_ledger.php), together
 * with App\Models\Chat\ChatConversation, ChatMessage, the 13-class
 * App\Services\Chat\* stack and ChatConversationService::open()/close().
 *
 * Creating support_conversations/support_messages would therefore be a SECOND
 * inbox — exactly the duplication Rule 2 forbids. Batch 3 exposes the member
 * side of that existing stack as /me/support/conversations and adds only what
 * the schema genuinely lacks.
 *
 * THE ONE GAP: a rating.
 * There is no rating column anywhere in the chat schema, and a rating is not a
 * message (it must survive message retention/redaction and it must be one-per-
 * conversation). It is added here as additive nullable columns, so every
 * existing row keeps behaving exactly as before.
 *
 * `rated_at` is stamped by SupportMessageService and is what makes "rate once"
 * enforceable without a second table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_conversations')) {
            return;
        }

        if (Schema::hasColumn('chat_conversations', 'rating')) {
            return;
        }

        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->unsignedTinyInteger('rating')->nullable();
            $table->timestamp('rated_at')->nullable();
            $table->string('rating_comment', 500)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('chat_conversations')) {
            return;
        }

        if (! Schema::hasColumn('chat_conversations', 'rating')) {
            return;
        }

        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->dropColumn(['rating', 'rated_at', 'rating_comment']);
        });
    }
};
