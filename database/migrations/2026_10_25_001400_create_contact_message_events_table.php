<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only timeline for a stored contact message.
 *
 * WHY THE INBOX NEEDS ONE. contact_messages has a current status and a current
 * delivery state, which answers "where is it now". A support lead investigating
 * a complaint needs "who moved it, when, and what was written in the note" -
 * none of which a status column can hold.
 *
 * INTERNAL NOTES ARE ENCRYPTED and never leave this table. They are not mailed,
 * not exposed through the public status lookup, and not part of a data export
 * (they belong to the platform's record, not to the requester's copy).
 *
 * Append-only: only created_at exists, and App\Models\ContactMessageEvent
 * refuses updates and deletes on a persisted row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contact_message_events')) {
            return;
        }

        Schema::create('contact_message_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('contact_message_id')
                ->constrained('contact_messages')
                ->cascadeOnDelete();

            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // 'status_change' | 'note' | 'reply'
            $table->string('type', 24)->index();

            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();

            // Encrypted: a note or a reply routinely quotes the visitor's own
            // message, which is personal data.
            $table->text('body_encrypted')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['contact_message_id', 'created_at'], 'contact_message_events_message_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_message_events');
    }
};
