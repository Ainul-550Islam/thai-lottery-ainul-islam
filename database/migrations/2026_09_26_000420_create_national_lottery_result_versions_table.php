<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * national_lottery_result_versions — provenance, idempotency and corrections.
 *
 * THIS TABLE IS THE ANSWER TO "WHERE DID THIS NUMBER COME FROM"
 * ---------------------------------------------------------------------------
 * Every row records, for one attempt to state a draw's result: which provider
 * produced it, what source state that provider earns, what the raw payload
 * hashed to, what the NORMALISED payload hashed to, which parser read it, when
 * it was retrieved, when it was imported and by whom.
 *
 * IDEMPOTENCY IS A DATABASE CONSTRAINT, NOT A CODE CONVENTION
 * ---------------------------------------------------------------------------
 * UNIQUE (draw_id, payload_fingerprint) is what makes "import the same payload
 * twice" produce exactly one version. Two concurrent workers racing on the
 * same payload both attempt the insert; the database lets one through and
 * rejects the other, and the loser reads the winner's row. No advisory lock,
 * no check-then-write window.
 *
 * WHY TWO FINGERPRINTS
 * payload_fingerprint hashes the bytes the provider sent, so a re-fetch that
 * differs only in whitespace or key order is still recognised as a NEW
 * delivery. normalized_fingerprint hashes the canonical result values only, so
 * two structurally different payloads that assert the SAME numbers are
 * recognised as agreeing. Conflict detection needs the second one: a new
 * payload whose normalised fingerprint differs from the verified version's is
 * a disagreement about the numbers, which is the case that must block
 * publication rather than overwrite.
 *
 * NO SECRETS EVER LAND HERE
 * source_endpoint_host stores a HOST, not a URL. A configured endpoint may
 * carry a token in its query string or headers; storing the full URL would put
 * that token in a table that a provenance page reads. The host is enough to
 * answer "which system said this" and carries nothing to steal.
 *
 * CORRECTIONS ARE APPENDS
 * A correction inserts a new version, points supersedes_version_id at the old
 * one, and marks the old one superseded. Nothing is deleted and no result
 * value is ever UPDATEd, which is what lets the detail page honestly show a
 * correction history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('national_lottery_result_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('draw_id')
                ->constrained('national_lottery_draws')
                ->cascadeOnDelete();

            // Monotonic per draw. version_number 1 is the first attempt.
            $table->unsignedInteger('version_number');

            // App\Enums\ResultVersionState
            $table->string('state', 24)->default('pending')->index();

            // --- Provenance ------------------------------------------------
            $table->string('provider', 32)->index();

            // App\Enums\GloSourceState value (shared platform vocabulary).
            $table->string('source_state', 40)->index();

            // The provider's own identifier for this delivery, when it has one.
            $table->string('source_identifier', 191)->nullable();

            // HOST ONLY. Never a full URL — see the class docblock.
            $table->string('source_endpoint_host', 191)->nullable();

            $table->char('payload_fingerprint', 64);
            $table->char('normalized_fingerprint', 64);

            $table->string('parser_version', 16);

            $table->timestamp('retrieved_at')->nullable();
            $table->timestamp('imported_at');
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();

            // --- Correction / conflict trail --------------------------------
            $table->unsignedBigInteger('supersedes_version_id')->nullable()->index();

            // The superseded row's version_number, denormalised so the public
            // provenance projection can say "replaces version 1" without a
            // join (and therefore without an N+1 on the history page).
            $table->unsignedInteger('supersedes_version_number')->nullable();
            $table->string('conflict_reason', 191)->nullable();
            $table->string('resolution_reason', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            // --- Validation --------------------------------------------------
            $table->string('validation_status', 24)->default('pending');
            $table->json('validation_errors')->nullable();

            // Who/what/why, for the audit requirement. Never carries a token.
            $table->json('audit')->nullable();

            $table->timestamps();

            // Version numbers are unique within a draw.
            $table->unique(['draw_id', 'version_number']);

            // THE idempotency constraint.
            $table->unique(['draw_id', 'payload_fingerprint']);

            // Conflict detection reads by normalised value.
            $table->index(['draw_id', 'normalized_fingerprint']);

            // "the verified version for this draw"
            $table->index(['draw_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('national_lottery_result_versions');
    }
};
