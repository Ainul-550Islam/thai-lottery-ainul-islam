<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable evidence of public prize/ticket verification requests (PROMPT 4).
 *
 * WHY A TABLE AT ALL
 * A public verification surface is the part of the system an attacker probes
 * first. Without a durable trail there is no way to show, later, how often a
 * reference was checked or what the system answered at the time. This table
 * is that trail.
 *
 * WHAT IT MUST NEVER CONTAIN
 * The query itself. A row records a KEYED HASH of the normalised input
 * (hash_hmac with the evidence key), never the ticket number, never the
 * barcode payload. Otherwise the audit trail would become a searchable list
 * of the numbers the public checked - a privacy leak created by the very
 * feature meant to protect privacy. For the same reason IP and user agent are
 * off by default (config('ticket_verification.evidence.store_ip')).
 *
 * IMMUTABILITY
 * There is no updated_at and the model refuses updates. A verification is an
 * observation at a point in time; correcting one would destroy the evidence.
 *
 * DEDUPLICATION / CONCURRENCY
 * The unique index on (query_fingerprint, correlation_id) means two
 * simultaneous requests carrying the same correlation id and the same query
 * collapse into one row instead of racing to write two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottery_ticket_verifications', function (Blueprint $table): void {
            $table->id();

            $table->uuid('uuid')->unique();

            // 'number' | 'reference' | 'barcode'
            $table->string('verification_kind', 16)->index();

            // 'glo_l6' | 'glo_n3' | 'operator' | 'unknown'
            $table->string('product', 24)->index();

            // hash_hmac(sha256) of the normalised input. Never the input.
            $table->string('query_fingerprint', 128);

            // Public vocabulary only - see config('ticket_verification.public_statuses').
            $table->string('public_status', 32)->index();

            // DIGITAL_RECORD_VERIFIED | PUBLIC_RECORD_FOUND | NOT_VERIFIED | REVOKED
            $table->string('authenticity_state', 32)->nullable();

            // SUPPORTED | NOT_CONFIGURED | UNSUPPORTED_FORMAT | INVALID
            $table->string('barcode_state', 24)->nullable();

            // True when the answer came from a synthetic fixture decode, so a
            // fixture can never be mistaken for an official read afterwards.
            $table->boolean('fixture_used')->default(false);

            // Policy in force when the answer was given, so evidence can be
            // replayed against the rules of the day.
            $table->string('policy_version', 16);

            // Request correlation id set by CorrelationIdMiddleware.
            $table->string('correlation_id', 64)->nullable()->index();

            // Non-identifying diagnostics only (reason codes, durations).
            $table->json('metadata')->nullable();

            $table->timestamp('verified_at')->index();

            // created_at only: evidence is written once and never updated.
            $table->timestamp('created_at')->nullable();

            $table->unique(['query_fingerprint', 'correlation_id'], 'ltv_fingerprint_correlation_unique');
            $table->index(['product', 'public_status']);
            $table->index(['verified_at', 'public_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottery_ticket_verifications');
    }
};
