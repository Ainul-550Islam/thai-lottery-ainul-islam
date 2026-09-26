<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GLO-11/12/13 domain tables.
 *
 * glo_tickets                  — official GLO ticket identity (L6/N3), NOT the
 *                                operator digital Ticket row (user-bound receipt)
 * glo_ticket_freezes           — legal/admin freeze cases (append-only history)
 * glo_prize_claims             — GLO prize claim lifecycle
 * glo_prize_payment_holds      — immutable payment-hold records for frozen winners
 * glo_public_ticket_status     — public-safe denormalized status projection
 *
 * No duplicate operator tickets/claims/audit infrastructure: operator lanes
 * remain tickets, payouts.metadata.claim and audit_logs.
 */
return new class () extends Migration
{
    public function up(): void
    {
        Schema::create('glo_tickets', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();
            $table->string('product', 8)->index();
            $table->string('ticket_number', 16);
            $table->string('set_series', 32)->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Digit string identity: never integer-cast. NULL series sorts
            // separately per DB; uniqueness is enforced in the service under
            // lock for the exact tuple (draw, product, number, series).
            $table->string('ticket_reference', 64)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['draw_id', 'product', 'ticket_number', 'set_series'], 'glo_tickets_natural_unique');
            $table->index(['product', 'ticket_number']);
        });

        Schema::create('glo_ticket_freezes', function (Blueprint $table): void {
            $table->id();

            $table->string('freeze_case_id', 64)->unique();
            $table->foreignId('ticket_id')->constrained('glo_tickets')->restrictOnDelete();
            $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();
            $table->string('product', 8)->index();
            $table->string('ticket_number', 16)->nullable()->index();
            $table->string('set_series', 32)->nullable();

            $table->string('requesting_authority', 255);
            $table->string('jurisdiction', 128);
            $table->string('case_reference', 128);
            $table->string('legal_reference_number', 128)->nullable();

            // Secure document architecture: IDs and hashes only — never raw
            // document bytes or unrestricted public URLs in ordinary columns.
            $table->string('evidence_reference', 128);
            $table->string('evidence_type', 64);
            $table->string('evidence_document_id', 64)->nullable();
            $table->string('evidence_content_hash', 64)->nullable();
            $table->string('evidence_mime_type', 128)->nullable();
            $table->json('evidence_submission_metadata')->nullable();
            $table->timestamp('evidence_received_at')->nullable();

            $table->string('status', 32)->default('requested')->index();
            $table->string('review_status', 32)->default('pending')->index();
            $table->timestamp('requested_at');
            $table->timestamp('effective_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('expiry_at')->nullable()->index();
            $table->timestamp('expired_at')->nullable();

            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_reason', 500)->nullable();

            // Canonical request fingerprint: identical freeze requests are
            // idempotent; distinct case references stay separately auditable.
            $table->string('fingerprint', 64)->unique();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('audit_metadata')->nullable();

            $table->timestamps();

            $table->index(['draw_id', 'product', 'ticket_number']);
            $table->index(['ticket_id', 'status']);
            $table->index(['status', 'expiry_at']);
        });

        Schema::create('glo_prize_claims', function (Blueprint $table): void {
            $table->id();

            $table->string('claim_reference', 64)->unique();
            $table->foreignId('ticket_id')->constrained('glo_tickets')->restrictOnDelete();
            $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();
            $table->string('product', 8)->index();
            $table->string('prize_category', 32);
            $table->string('ticket_number', 16)->nullable();

            // Money: exact decimal strings only (BCMath domain), never float.
            $table->decimal('gross_prize', 20, 2);
            $table->decimal('stamp_duty', 20, 2);
            $table->decimal('net_prize', 20, 2);

            $table->foreignId('claimant_user_id')->constrained('users')->restrictOnDelete();
            $table->string('identity_reference', 64)->nullable();
            $table->string('age_verification_result', 32)->default('unverified');
            $table->timestamp('age_verified_at')->nullable();
            $table->integer('verified_age_years')->nullable();
            $table->boolean('original_ticket_evidenced')->default(false);
            $table->boolean('identity_document_evidenced')->default(false);

            $table->string('claim_channel', 32);
            $table->string('status', 32)->default('pending')->index();
            $table->string('payment_status', 32)->default('pending')->index();
            $table->string('hold_status', 32)->default('none')->index();

            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('payment_transaction_reference', 96)->nullable()->unique();
            $table->string('rejection_reason', 500)->nullable();
            $table->string('hold_reason', 64)->nullable();

            // Canonical (ticket, claimant) claim identity — duplicate submits replay.
            $table->string('fingerprint', 64)->unique();

            $table->json('audit_metadata')->nullable();

            $table->timestamps();

            $table->index(['draw_id', 'status']);
            $table->index(['ticket_id', 'status']);
            $table->index(['claimant_user_id', 'status']);
        });

        Schema::create('glo_prize_payment_holds', function (Blueprint $table): void {
            $table->id();

            $table->string('hold_reference', 64)->unique();
            $table->foreignId('claim_id')->nullable()->constrained('glo_prize_claims')->restrictOnDelete();
            $table->foreignId('freeze_id')->constrained('glo_ticket_freezes')->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('glo_tickets')->restrictOnDelete();
            $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();

            $table->string('winning_category', 32)->nullable();
            $table->decimal('gross_prize', 20, 2)->nullable();
            $table->decimal('stamp_duty', 20, 2)->nullable();

            $table->string('hold_reason', 64);
            $table->string('status', 32)->default('active')->index();

            $table->timestamp('created_at');
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason', 500)->nullable();

            $table->string('fingerprint', 64)->unique();

            $table->json('audit_metadata')->nullable();

            $table->index(['ticket_id', 'status']);
            $table->index(['draw_id', 'status']);
        });

        Schema::create('glo_public_ticket_status', function (Blueprint $table): void {
            $table->id();

            $table->string('announcement_id', 64)->unique();
            $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('glo_tickets')->restrictOnDelete();
            $table->foreignId('freeze_id')->nullable()->constrained('glo_ticket_freezes')->restrictOnDelete();

            // Public disclosure reference only (product + digit ticket number).
            $table->string('ticket_reference', 64)->index();
            $table->string('product', 8);
            $table->string('prize_category', 32)->nullable();

            $table->string('status', 48)->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('effective_hold_at')->nullable();
            $table->string('source_authority', 255)->nullable();

            $table->string('fingerprint', 64)->unique();

            $table->timestamps();

            $table->unique(['draw_id', 'ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glo_public_ticket_status');
        Schema::dropIfExists('glo_prize_payment_holds');
        Schema::dropIfExists('glo_prize_claims');
        Schema::dropIfExists('glo_ticket_freezes');
        Schema::dropIfExists('glo_tickets');
    }
};
