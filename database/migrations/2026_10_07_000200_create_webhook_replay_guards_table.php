<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable accepted-delivery observation for inbound provider webhooks.
 *
 * The middleware records a successful controller response and atomically
 * upserts one telemetry row per gateway/signature digest. A repeat increments
 * `deliveries`; a new signature over a known body can be observed as a possible
 * provider retry or signature rotation. This table does not authenticate a
 * request, reject an HTTP replay, or replace provider-specific signature checks,
 * transactional payment state transitions, or event idempotency.
 *
 * The nonce is sha256(gateway + signature-header-value). The raw signature and
 * raw request body are not stored. A unique (gateway, nonce) index prevents
 * duplicate observation rows and makes the upsert atomic across workers; the
 * request still reaches the controller, where the provider verifier and payment
 * idempotency logic decide what happens.
 *
 * Retention is for operational history and cleanup. Pruning observations does
 * not reopen a financial replay window because this table is not the financial
 * replay control. `expires_at` supports indexed retention cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_replay_guards', function (Blueprint $table): void {
            $table->id();

            // The gateway segment of the URL. Scoped per gateway because two
            // providers can legitimately produce the same bytes; separate nonce
            // spaces mean one provider's signature cannot shadow another's.
            $table->string('gateway', 32);

            // sha256(gateway|signature-header-value) — 64 hex chars.
            $table->string('nonce', 64);

            // SHA-256 of the provider signature header. The middleware never
            // stores the raw proof; this digest supports investigation without
            // retaining a temporarily replayable authorization value.
            $table->string('signature', 255)->nullable();

            /*
             * sha256 of the raw request body, indexed but NOT unique.
             *
             * Why not unique: excluding byte-identical bodies would refuse a
             * legitimate second delivery whose signature changed. Stripe retries
             * with a FRESH timestamp, which produces a fresh signature over the
             * SAME body - a different nonce, so the unique index does not catch
             * it. Making the body unique instead would refuse that retry too,
             * which is harmless for Stripe (the first delivery did the work) but
             * NOT harmless for a provider whose protocol legitimately allows two
             * identical bodies, e.g. a bank-transfer notification with no
             * per-event identifier of its own.
             *
             * This column does not refuse anything. It lets the middleware
             * DETECT signature rotation — a known body arriving under a new
             * nonce — and log it for an operator. Provider verification and the
             * transactional payment state machine determine acceptance and
             * financial idempotency; the unique observation index is not a
             * request-rejection control.
             */
            $table->string('payload_hash', 64)->nullable();

            // How many times this exact signed payload has been delivered.
            // >1 is normal (provider retries) and is not by itself an incident;
            // a large number in a short window is.
            $table->unsignedInteger('deliveries')->default(1);

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('expires_at')->nullable();

            $table->string('first_ip', 45)->nullable();
            $table->string('last_ip', 45)->nullable();

            // Atomic identity constraint for the observation row; it does not
            // reject the HTTP request or replace payment idempotency.
            $table->unique(['gateway', 'nonce'], 'webhook_replay_guards_identity_unique');

            // Operator triage: "what is being replayed at me right now".
            $table->index(['gateway', 'last_seen_at'], 'webhook_replay_guards_triage_index');

            // Signature-rotation detection: "has this exact body arrived before,
            // under a different signature". Indexed rather than unique - see the
            // column comment.
            $table->index(['gateway', 'payload_hash'], 'webhook_replay_guards_payload_index');

            // Pruning.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_replay_guards');
    }
};
