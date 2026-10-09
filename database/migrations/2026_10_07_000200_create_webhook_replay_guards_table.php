<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable replay guard for inbound provider webhooks.
 *
 * ============================================================================
 * WHY THIS TABLE EXISTS
 * ============================================================================
 * Replay protection on the primary webhook lane
 * (POST /api/v1/payments/webhook/{gateway}) was implemented ONLY as a cache
 * entry:
 *
 *     PaymentWebhookService::processWebhookPayload()
 *       $cacheKey = 'payment:webhook:seen:'.$gateway.':'.$eventId;
 *       if (! $this->cache->add($cacheKey, true, $cacheTtl)) { ...duplicate... }
 *
 * That is a correct *algorithm* and the wrong *substrate*. With the cache on
 * the file driver — which is what .env.example ships (`CACHE_STORE=file`) —
 * `Cache::add` is atomic on ONE machine and meaningless across two. Two app
 * containers behind a load balancer each have their own file cache, so the same
 * signed webhook delivered to both is "first seen" on both. A cache restart or
 * an eviction between two deliveries re-opens the window entirely.
 *
 * The application already knows this in principle: ProductionSafetyServiceProvider
 * refuses to boot production with a file session driver because "file sessions
 * cannot be shared across instances". The same argument applies to a file cache
 * standing in as a security control, and production does not currently refuse it.
 *
 * ============================================================================
 * WHY THE SIGNATURE IS THE REPLAY IDENTITY
 * ============================================================================
 * The nonce is sha256(gateway + signature-header-value) — NOT the event id and
 * NOT a clock reading.
 *
 *   - The signature is already a deterministic function of the exact bytes the
 *     provider sent, signed with the shared secret. Two identical deliveries
 *     therefore produce one identical nonce, and an attacker cannot mint a new
 *     nonce for the same payload without the secret.
 *   - It requires NO cooperation from any provider. Four of the five gateway
 *     drivers (bKash, Nagad, Crypto, Bank Transfer) sign the raw body and carry
 *     no timestamp at all. Keying on a provider-supplied timestamp would mean
 *     the control silently does nothing for four providers out of five. Keying
 *     on the signature works for every one of them, unchanged.
 *   - Keying on the event id — as the cache guard did — is weaker: the event id
 *     comes from the payload, so it is only as trustworthy as the signature over
 *     it, and it is absent entirely when a provider omits the field.
 *
 * ============================================================================
 * UNIQUENESS IS THE CONTROL
 * ============================================================================
 * (gateway, nonce) is UNIQUE. The guard is therefore enforced by the database
 * itself: a second delivery cannot insert, and the refusal is atomic even under
 * concurrent workers on different hosts. There is no read-then-write window to
 * lose, which is the flaw every application-level "have I seen this?" check has.
 *
 * ============================================================================
 * RETENTION
 * ============================================================================
 * The row is kept for replay_guard_retention_days (default 30). Deleting sooner
 * reopens the window; keeping longer costs a few dozen bytes per delivery.
 * `expires_at` is indexed so the pruning command is an indexed range delete
 * rather than a full scan on a table that grows with traffic.
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

            // The raw header value, truncated. Kept so an operator
            // investigating a replay storm can see WHAT was replayed without
            // having to reconstruct it. Never a secret: it is the signature the
            // provider sent us, not the key that produced it.
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
             * So this column does not refuse anything on its own. It is how the
             * middleware DETECTS signature rotation - a known body arriving under
             * a new nonce - and logs it for an operator. Refusal stays where it
             * can be justified per delivery, which is the unique index above.
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

            // THE CONTROL.
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
