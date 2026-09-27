<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * weekly_lottery_result_versions — provenance, idempotency and corrections.
 *
 * THIS TABLE IS THE ANSWER TO "WHERE DID THIS NUMBER COME FROM"
 * ---------------------------------------------------------------------------
 * Every row records, for one attempt to state a draw's result: which provider
 * produced it, what source state that provider earns, what the raw payload
 * hashed to, what the CANONICAL payload hashed to, which parser read it, what
 * the independent Rust verifier said about it, when it was retrieved, when it
 * was imported and by whom.
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
 * delivery. normalized_fingerprint hashes the canonical result values only
 * (the WKLY1 encoding the Rust crate also implements), so two structurally
 * different payloads asserting the SAME numbers are recognised as agreeing.
 * Conflict detection needs the second: a payload whose normalized fingerprint
 * differs from the verified version's is a disagreement about the numbers,
 * which must block publication rather than overwrite.
 *
 * NO SECRETS EVER LAND HERE
 * source_endpoint_host stores a HOST, not a URL. A configured endpoint may
 * carry a token in its query string; storing the full URL would put that token
 * in a table a provenance page reads. The host answers "which system said
 * this" and carries nothing to steal. There is no column for a token, a
 * header, a credential or the raw payload.
 *
 * CORRECTIONS ARE APPENDS
 * A correction inserts a new version, points supersedes_version_id at the old
 * one, and marks the old one superseded. Nothing is deleted and no result
 * value is ever UPDATEd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_lottery_result_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('draw_id')
                ->constrained('weekly_lottery_draws')
                ->cascadeOnDelete();

            $table->unsignedInteger('version_number');

            // App\Enums\ResultVersionState
            $table->string('state', 24)->default('pending')->index();

            // --- Provenance ------------------------------------------------
            $table->string('provider', 32)->index();

            // App\Enums\GloSourceState value (shared platform vocabulary).
            $table->string('source_state', 40)->index();

            $table->string('source_identifier', 191)->nullable();

            // HOST ONLY. Never a full URL — see the class docblock.
            $table->string('source_endpoint_host', 191)->nullable();

            $table->char('payload_fingerprint', 64);
            $table->char('normalized_fingerprint', 64);

            $table->string('parser_version', 16);

            // --- Independent integrity verification -------------------------
            // What the Rust crate said, and which canonical encoding produced
            // the normalized fingerprint. Stored so a later audit can tell a
            // hash-only record from a cryptographically signed one without
            // re-running anything.
            $table->string('integrity_status', 32)->default('NOT_VERIFIED');
            $table->string('canonical_version', 16)->nullable();
            $table->boolean('integrity_native_verified')->default(false);

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

            $table->string('validation_status', 24)->default('pending');
            $table->json('validation_errors')->nullable();

            // Who/what/why. Never carries a token.
            $table->json('audit')->nullable();

            $table->timestamps();

            $table->unique(['draw_id', 'version_number']);

            // THE idempotency constraint.
            $table->unique(['draw_id', 'payload_fingerprint']);

            // Conflict detection reads by canonical value.
            $table->index(['draw_id', 'normalized_fingerprint']);

            // "the verified version for this draw"
            $table->index(['draw_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_lottery_result_versions');
    }
};
