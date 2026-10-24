<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IP access rules and their idempotent create receipts.
 *
 * WHY EPOCH COLUMNS AND NOT TIMESTAMPS
 * The reader publishes an EXACT policy snapshot: "the rules in force at this
 * instant". Unix-epoch integers make the active-window predicate
 * (`starts_at <= now` AND (`expires_at` IS NULL OR `expires_at > now`) AND
 * `revoked_at` IS NULL) a plain integer comparison on every database, with no
 * timezone, precision or string-collation surprise between the application
 * clock and the query planner. A rule that silently gains or loses validity
 * because of a DST fold is a lockout — or a bypass.
 *
 * WHY THE CREATE-RECEIPT TABLE
 * Creating a rule is a privileged, audited write against a capacity-guarded
 * namespace. A retried request (client timeout, operator double-click, a proxy
 * that replays a POST) must not create a second rule: the (actor_id, key_hash)
 * unique index is the final conflict backstop, and the stored `fingerprint`
 * distinguishes "the same request again" (replay the FIRST rule) from "the
 * same key with different input" (refuse — an idempotency conflict).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ip_access_rules')) {
            Schema::create('ip_access_rules', function (Blueprint $table): void {
                $table->id();
    
                // Normalised CIDR or single address, validated by IpRangeMatcher
                // and re-validated by IpAccessRule::validatedRange() on save.
                $table->string('ip_range', 64);
    
                // Machine-stable refusal/justification code: ^[A-Z][A-Z0-9_]{0,63}$.
                $table->string('reason_code', 64);
    
                // Validity window as Unix epoch seconds (see the note above).
                $table->unsignedBigInteger('starts_at_epoch');
                $table->unsignedBigInteger('expires_at_epoch')->nullable();
                $table->unsignedBigInteger('revoked_at_epoch')->nullable();
    
                // Who created the rule; revocation is recorded as an epoch on the
                // row itself so a revoked rule stays visible for audit.
                $table->unsignedBigInteger('created_by');
    
                // "Which rules are in force right now" is the hot read; the
                // predicate the reader issues is exactly this composite.
                $table->index(['starts_at_epoch', 'expires_at_epoch', 'revoked_at_epoch'], 'ip_access_rules_active_index');
    
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ip_rule_create_requests')) {
            Schema::create('ip_rule_create_requests', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('actor_id');
    
                // sha256 hex of the idempotency key; the raw key is never stored.
                $table->string('key_hash', 64);
    
                // sha256 hex of the normalised create input; equal fingerprint
                // means "same request", different means "key reuse with new input".
                $table->string('fingerprint', 64);
    
                $table->unsignedBigInteger('rule_id');
                $table->timestamp('created_at')->useCurrent();
    
                // The final conflict backstop: one create per actor per key.
                $table->unique(['actor_id', 'key_hash'], 'ip_rule_create_requests_actor_key_unique');
                $table->index('rule_id');
            });
        }
    }

    public function down(): void
    {
        // Child (receipt references rules) before parent.
        Schema::dropIfExists('ip_rule_create_requests');
        Schema::dropIfExists('ip_access_rules');
    }
};
