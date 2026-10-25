<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only timeline of a data-subject request.
 *
 * WHY A TABLE AND NOT A status COLUMN. A privacy request is a legal process,
 * so the platform has to be able to prove not only where the request is now
 * but how it got there, who moved it, when, and with what note. A status
 * column records the present; this table records the history.
 *
 * THERE IS NO updated_at AND NO SOFT DELETE. Rows are written once and never
 * changed; App\Models\DataSubjectRequestEvent throws on any attempt to update
 * or delete a persisted row, and this schema removes the columns such an
 * attempt would need.
 *
 * A note lives encrypted: an internal note about an erasure request routinely
 * names the very data the requester asked about.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('data_subject_request_events')) {
            return;
        }

        Schema::create('data_subject_request_events', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('request_id')
                ->constrained('data_subject_requests')
                ->cascadeOnDelete();

            // 'requester' | 'system' | 'staff'
            $table->string('actor_type', 16)->default('system');

            // The staff user who acted, when there was one. nullOnDelete keeps
            // the event (the history matters) while dropping the identity of a
            // deleted operator account.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            // 'submitted' | 'verified' | 'status_change' | 'note' |
            // 'export_built' | 'export_released' | 'sla_reminder' | 'overdue'
            $table->string('event', 48)->index();

            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();

            $table->text('note_encrypted')->nullable();

            // Append-only: only created_at exists.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['request_id', 'created_at'], 'data_subject_request_events_request_time_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_request_events');
    }
};
