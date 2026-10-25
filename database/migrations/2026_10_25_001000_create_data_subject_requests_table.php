<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data-subject requests (PDPA access / portability / erasure / ...).
 *
 * WHAT IS STORED, AND WHAT IS NOT
 * ---------------------------------------------------------------------------
 * A request needs to be attributable to an address so a verification link can
 * be sent, and it needs to survive a review weeks later. So the address is
 * stored TWICE, on purpose:
 *
 *   email_hash       sha256 of the normalised address. A LOOKUP KEY: it lets
 *                    the platform count open requests per address and find an
 *                    account match without scanning decrypted rows.
 *   email_encrypted  the address itself, encrypted with the application key,
 *                    because an operator answering a rectification request
 *                    needs to know who it is about.
 *
 * There is no plaintext address column, no free-text identity document, no
 * IP address (only ip_hash, a one-way fingerprint used for abuse ceilings), and
 * no account password material of any kind.
 *
 * details_encrypted holds what the requester wrote. It is encrypted at rest
 * because it routinely contains the very data the request is about.
 *
 * reference IS THE ONLY IDENTIFIER A REQUESTER SEES. It is derived from the
 * row id after insert (PRV-YYYY-000123) so it is unique without a race, and it
 * carries no meaning beyond "this request".
 *
 * The table is additive and safe on both SQLite and MySQL/MariaDB: no
 * engine-specific types, no partial indexes, no generated columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('data_subject_requests')) {
            return;
        }

        Schema::create('data_subject_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // PRV-2026-000123. Unique, quotable, and not an auto-increment a
            // visitor could use to count the queue.
            $table->string('reference', 32)->unique();

            // Nullable: a public intake arrives before any account is proven to
            // be involved. It is linked only when the export resolves exactly
            // one account for the verified address.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // sha256 of the normalised address — the lookup key, never a secret.
            $table->char('email_hash', 64)->index();

            // The address itself, encrypted with the application key.
            $table->text('email_encrypted');

            $table->string('locale', 12)->default('en');

            // One of App\Enums\PrivacyRequestType. Closed vocabulary; the enum
            // and the config list are the only writers.
            $table->string('type', 24)->index();

            // One of App\Enums\PrivacyRequestStatus.
            $table->string('status', 24)->default('pending_verification')->index();

            // What the requester wrote, encrypted at rest.
            $table->text('details_encrypted')->nullable();

            // When the address was proven to belong to whoever submitted it.
            $table->timestamp('verified_at')->nullable();

            // When the request was legally received; the SLA clock starts here.
            $table->timestamp('received_at')->nullable();

            // received_at + sla_days, written once so it can be indexed, sorted
            // and swept without recomputing a policy that may have changed.
            $table->timestamp('due_at')->nullable()->index();

            $table->timestamp('completed_at')->nullable();

            // A closed reason vocabulary (config), not prose: reportable counts
            // need codes, and a reviewer's sentence belongs in the timeline.
            $table->string('rejected_reason_code', 64)->nullable();

            // The operator who currently owns the request, if any.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            // Set when a reviewer decides records must be preserved. Never
            // written by the requester: a legal hold is the platform's answer,
            // not a request.
            $table->string('legal_hold_reason')->nullable();

            // One-way fingerprint of the submitting connection, used only for
            // abuse ceilings. Not an address, not reversible.
            $table->char('ip_hash', 64)->nullable()->index();

            $table->timestamps();

            $table->index(['status', 'due_at'], 'data_subject_requests_status_due_index');
            $table->index(['user_id', 'created_at'], 'data_subject_requests_user_created_index');
            $table->index(['type', 'status'], 'data_subject_requests_type_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_subject_requests');
    }
};
