<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Security, API Keys, Idempotency & Audit Logs.
     */
    public function up(): void
    {
        // 1. API Keys & HMAC Secrets
        if (! Schema::hasTable('api_clients')) {
            Schema::create('api_clients', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('client_name', 128);
                $table->string('api_key', 64)->unique();
                $table->string('secret_key_hash', 128); // Argon2id or bcrypt of secret key
                $table->json('allowed_ips')->nullable();
                $table->unsignedInteger('rate_limit_per_minute')->default(3000);
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
    
                $table->index(['api_key', 'is_active'], 'idx_api_clients_key_active');
            });
        }

        // 2. Idempotency Records Table (Persistent fallback for Redis TTL expiration)
        if (! Schema::hasTable('idempotency_records')) {
            Schema::create('idempotency_records', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('idempotency_key', 128)->unique();
                $table->uuid('user_id')->nullable()->index();
                $table->string('request_path', 255);
                $table->string('request_hash', 64); // SHA-256 of payload
                $table->unsignedSmallInteger('response_status')->default(200);
                $table->json('response_headers')->nullable();
                $table->json('response_body')->nullable();
                $table->string('status', 24)->default('IN_FLIGHT'); // IN_FLIGHT, COMPLETED, FAILED
                $table->timestamp('locked_at')->useCurrent();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
    
                $table->index(['user_id', 'created_at'], 'idx_idempotency_user_created');
            });
        }

        // 3. Security Audit Logs (Immutable audit trail for financial & admin mutations)
        if (! Schema::hasTable('security_audit_logs')) {
            Schema::create('security_audit_logs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->nullable()->index();
                $table->string('event_type', 64)->index(); // WALLET_DEBIT, WALLET_CREDIT, DRAW_INGEST, DRAW_CONFIRM, PAYOUT_EXEC
                $table->string('ip_address', 45);
                $table->text('user_agent')->nullable();
                $table->json('old_state')->nullable();
                $table->json('new_state')->nullable();
                $table->string('payload_hash', 64);
                $table->string('signature', 128)->nullable();
                $table->timestamp('occurred_at')->useCurrent();
    
                $table->index(['event_type', 'occurred_at'], 'idx_audit_event_time');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_audit_logs');
        Schema::dropIfExists('idempotency_records');
        Schema::dropIfExists('api_clients');
    }
};
