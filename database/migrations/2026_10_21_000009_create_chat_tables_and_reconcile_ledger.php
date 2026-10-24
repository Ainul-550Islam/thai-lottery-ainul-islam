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
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('guest_token_hash', 128)->nullable()->index();
                $table->unsignedBigInteger('assigned_operator_id')->nullable()->index();
                $table->string('status', 32)->default('open')->index();
                $table->string('subject', 255)->nullable();
                $table->string('priority', 32)->default('normal');
                $table->string('locale', 16)->default('en');
                $table->timestamp('last_message_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->unsignedBigInteger('conversation_id')->index();
                $table->string('sender_type', 32)->default('player');
                $table->unsignedBigInteger('sender_user_id')->nullable()->index();
                $table->text('body')->nullable();
                $table->text('body_ciphertext')->nullable();
                $table->string('attachment_path', 512)->nullable();
                $table->string('attachment_mime', 128)->nullable();
                $table->unsignedInteger('attachment_size')->nullable();
                $table->boolean('is_internal_note')->default(false);
                $table->timestamp('read_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('chat_events')) {
            Schema::create('chat_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conversation_id')->index();
                $table->string('event_type', 64)->index();
                $table->unsignedBigInteger('actor_user_id')->nullable()->index();
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('chat_agent_presences')) {
            Schema::create('chat_agent_presences', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                $table->string('status', 32)->default('online')->index();
                $table->unsignedInteger('active_conversations')->default(0);
                $table->unsignedInteger('max_conversations')->default(10);
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('chat_canned_responses')) {
            Schema::create('chat_canned_responses', function (Blueprint $table): void {
                $table->id();
                $table->string('shortcut', 64)->unique();
                $table->string('title', 191);
                $table->text('body');
                $table->string('locale', 16)->default('en')->index();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
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
