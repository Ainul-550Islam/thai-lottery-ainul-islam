<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * PROMPT 3 — account_verifications (the submission-event aggregate)
 * plus the additive personal-detail columns the benchmark registration
 * collects.
 *
 * MIGRATION SAFETY (inspected first):
 * - users.id is a bigint (foreignId matches; verified against the
 *   live sqlite schema before writing).
 * - kyc_documents.id is a bigint; the document references below use
 *   the same type. Both FK targets already exist.
 * - users already has date_of_birth; only gender/city/country/
 *   nationality are added, all NULLABLE and additive — no existing
 *   column is touched, no data is rewritten, rollback drops only
 *   what this migration added.
 * - No duplicate table is created: account_verifications is a NEW
 *   aggregate (submission events), not a second KYC identity table.
 *
 * INDEXES: (user_id, status) for the open-request guard;
 * (status, submitted_at) for reviewer queues; processed_at and
 * rule_version for audit sweeps; verification_reference and
 * fingerprint UNIQUE for idempotency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_verifications', function (Blueprint $table): void {
            $table->id();

            // Owner — always the authenticated member, never client-supplied.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // Immutable public-safe reference (never the storage path).
            $table->string('verification_reference', 64)->unique();

            // AccountVerificationStatus storage value.
            $table->string('status', 32)->default('pending');

            // Structured country-code + mobile pair (normalized at submit).
            $table->string('country_code', 8)->nullable();
            $table->string('mobile', 32)->nullable();

            // VerificationDocumentType storage value.
            $table->string('document_type', 48);

            // The canonical KYC document rows (front / back).
            $table->foreignId('front_document_id')->nullable()->constrained('kyc_documents')->nullOnDelete();
            $table->foreignId('back_document_id')->nullable()->constrained('kyc_documents')->nullOnDelete();

            $table->timestamp('submitted_at')->index();
            $table->timestamp('processed_at')->nullable()->index();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_reason', 500)->nullable();

            $table->string('rule_version', 16)->default('1')->index();

            // sha256 idempotency fingerprint — UNIQUE by constraint.
            $table->string('fingerprint', 64)->unique();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'submitted_at']);
        });

        // Additive registration detail columns (benchmark personal
        // details). All nullable; existing rows and flows untouched.
        Schema::table('users', function (Blueprint $table): void {
            $table->string('gender', 24)->nullable()->after('date_of_birth');
            $table->string('city', 100)->nullable()->after('gender');
            $table->string('country', 100)->nullable()->after('city');
            $table->string('nationality', 100)->nullable()->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['nationality', 'country', 'city', 'gender']);
        });

        Schema::dropIfExists('account_verifications');
    }
};
