<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_conversations')) {
            Schema::create('chat_conversations', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('public_reference', 24)->unique();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('guest_token_hash', 64)->nullable()->index();
                $table->string('guest_name', 80)->nullable();
                $table->string('guest_email', 190)->nullable();
                $table->string('status', 20)->default('waiting')->index();
                $table->string('priority', 20)->default('normal')->index();
                $table->string('close_reason', 32)->nullable();
                $table->foreignId('assigned_agent_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('queued_at')->nullable()->index();
                $table->timestamp('claimed_at')->nullable();
                $table->timestamp('first_response_at')->nullable();
                $table->timestamp('last_message_at')->nullable()->index();
                $table->timestamp('last_visitor_message_at')->nullable();
                $table->timestamp('last_agent_message_at')->nullable();
                $table->timestamp('idle_warned_at')->nullable();
                $table->timestamp('closed_at')->nullable()->index();
                $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('visitor_unread_count')->default(0);
                $table->unsignedInteger('agent_unread_count')->default(0);
                $table->unsignedInteger('message_count')->default(0);
                $table->json('meta')->nullable();
                $table->timestamp('transcript_sent_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'priority', 'queued_at'], 'chat_conversations_queue_idx');
                $table->index(['assigned_agent_id', 'status'], 'chat_conversations_agent_status_idx');
                $table->index(['user_id', 'status'], 'chat_conversations_user_status_idx');
            });
        }

        if (! Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('chat_conversation_id')
                    ->constrained('chat_conversations')
                    ->cascadeOnDelete();
                $table->string('sender_type', 20)->index();
                $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('sender_display_name', 80)->nullable();
                $table->string('kind', 20)->default('text')->index();
                $table->text('body');
                $table->json('attachments')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('read_at')->nullable();
                $table->timestamp('redacted_at')->nullable();
                $table->string('client_token', 64)->nullable();
                $table->timestamps();

                $table->index(['chat_conversation_id', 'id'], 'chat_messages_conv_id_idx');
                $table->unique(
                    ['chat_conversation_id', 'sender_type', 'client_token'],
                    'chat_messages_client_token_unique'
                );
            });
        }

        if (! Schema::hasTable('chat_events')) {
            Schema::create('chat_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('chat_conversation_id')
                    ->nullable()
                    ->constrained('chat_conversations')
                    ->cascadeOnDelete();
                $table->string('event', 64)->index();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_type', 20)->default('system');
                $table->json('context')->nullable();
                $table->string('correlation_id', 64)->nullable()->index();
                $table->timestamp('occurred_at')->nullable()->index();
                $table->timestamp('created_at')->nullable();

                $table->index(['chat_conversation_id', 'id'], 'chat_events_conv_id_idx');
            });
        }

        if (! Schema::hasTable('chat_agent_presences')) {
            Schema::create('chat_agent_presences', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->string('status', 20)->default('offline')->index();
                $table->timestamp('last_heartbeat_at')->nullable()->index();
                $table->timestamp('session_started_at')->nullable();
                $table->unsignedSmallInteger('max_conversations')->nullable();
                $table->boolean('accepts_new_conversations')->default(true);
                $table->string('current_page', 190)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('chat_canned_responses')) {
            Schema::create('chat_canned_responses', function (Blueprint $table): void {
                $table->id();
                $table->string('key', 80)->unique();
                $table->string('title', 120);
                $table->text('body');
                $table->string('category', 40)->nullable()->default('general')->index();
                $table->json('placeholders')->nullable();
                $table->string('locale', 10)->default('en')->index();
                $table->integer('sort_order')->default(0);
                $table->boolean('enabled')->default(true)->index();
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_canned_responses');
        Schema::dropIfExists('chat_agent_presences');
        Schema::dropIfExists('chat_events');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
