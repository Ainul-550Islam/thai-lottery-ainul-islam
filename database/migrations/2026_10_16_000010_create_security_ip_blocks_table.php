<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Blocked IPs, Fraud Alerts, Fraud Cases, and Upload Scans.
     */
    public function up(): void
    {
        // 1. Blocked IPs Table
        if (! Schema::hasTable('blocked_ips')) {
            Schema::create('blocked_ips', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('ip_or_cidr', 64)->unique();
                $table->unsignedTinyInteger('ip_version')->default(4); // 4 or 6
                $table->boolean('is_cidr')->default(false);
                $table->string('reason_code', 64); // REPEATED_AUTH_FAIL, BRUTE_FORCE, FRAUD, MANUAL, THREAT_FEED
                $table->string('source', 32)->default('MANUAL');
                $table->boolean('is_active')->default(true);
                $table->uuid('created_by_user_id')->nullable()->index();
                $table->uuid('unblocked_by_user_id')->nullable()->index();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamp('unblocked_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
    
                $table->index(['is_active', 'expires_at'], 'idx_blocked_ips_active_expires');
            });
        }

        // 2. Fraud Alerts Table
        if (! Schema::hasTable('fraud_alerts')) {
            Schema::create('fraud_alerts', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('idempotency_event_key', 128)->unique();
                $table->string('event_type', 64)->index(); // DEPOSIT, WITHDRAWAL, BET_PURCHASE, PAYOUT_CLAIM
                $table->uuid('user_id')->index();
                $table->decimal('amount', 20, 4);
                $table->string('currency', 3)->default('THB');
                $table->unsignedTinyInteger('risk_score')->default(0);
                $table->string('decision', 32)->default('allow'); // allow, step_up, hold_for_review, deny
                $table->json('reason_codes')->nullable();
                $table->json('triggered_rules')->nullable();
                $table->string('status', 32)->default('OPEN'); // OPEN, UNDER_REVIEW, RESOLVED_ALLOW, RESOLVED_DENY
                $table->uuid('assigned_to_user_id')->nullable()->index();
                $table->uuid('resolved_by_user_id')->nullable();
                $table->text('resolution_notes')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
    
                $table->index(['status', 'risk_score'], 'idx_fraud_alerts_status_score');
            });
        }

        // 3. Fraud Cases (Maker-Checker Review Evidence)
        if (! Schema::hasTable('fraud_cases')) {
            Schema::create('fraud_cases', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('fraud_alert_id')->index();
                $table->uuid('reviewer_user_id')->index();
                $table->uuid('maker_user_id')->nullable();
                $table->uuid('checker_user_id')->nullable();
                $table->string('action_taken', 64); // HOLD, RELEASE, FREEZE_WALLET, TERMINATE
                $table->string('decision', 32);
                $table->text('decision_reason');
                $table->json('metadata')->nullable();
                $table->timestamp('decided_at')->useCurrent();
                $table->timestamps();
    
                $table->foreign('fraud_alert_id')->references('id')->on('fraud_alerts')->onDelete('cascade');
            });
        }

        // 4. Upload Scan Records Table
        if (! Schema::hasTable('upload_scan_records')) {
            Schema::create('upload_scan_records', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('private_storage_key', 255)->unique();
                $table->string('owning_model_type', 128)->nullable()->index();
                $table->uuid('owning_model_id')->nullable()->index();
                $table->string('original_filename', 255);
                $table->string('file_mime_type', 128);
                $table->unsignedBigInteger('file_size_bytes');
                $table->string('file_sha256', 64)->index();
                $table->string('scan_status', 32)->default('pending_scan'); // pending_scan, clean, infected, rejected, error, expired
                $table->string('threat_name', 128)->nullable();
                $table->string('scanner_version', 64)->nullable();
                $table->decimal('scan_duration_ms', 10, 2)->default(0.00);
                $table->unsignedSmallInteger('retries_count')->default(0);
                $table->timestamp('scanned_at')->nullable();
                $table->timestamps();
    
                $table->index(['scan_status', 'created_at'], 'idx_upload_scans_status_created');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('upload_scan_records');
        Schema::dropIfExists('fraud_cases');
        Schema::dropIfExists('fraud_alerts');
        Schema::dropIfExists('blocked_ips');
    }
};
