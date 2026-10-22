<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Deposit Ingestion and Withdrawal Disbursement.
     */
    public function up(): void
    {
        // 1. Deposits Table (Inbound Gateways & Crypto/PromptPay/Bank Transfers)
        Schema::create('deposit_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('deposit_number', 64)->unique();
            $table->string('gateway_provider', 64); // PROMPTPAY_QR, TRUE_MONEY, SCB_EASY, USDT_TRC20
            $table->string('gateway_reference_id', 128)->nullable()->index();
            $table->decimal('amount', 20, 4);
            $table->decimal('fee_amount', 16, 4)->default('0.0000');
            $table->decimal('net_amount', 20, 4);
            $table->string('currency', 3)->default('THB');
            $table->string('status', 24)->default('PENDING'); // PENDING, PROCESSING, COMPLETED, FAILED, EXPIRED
            $table->string('idempotency_key', 128)->unique();
            $table->uuid('ledger_transaction_id')->nullable()->index();
            $table->json('gateway_raw_payload')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'idx_deposits_user_status');
            $table->index(['gateway_provider', 'gateway_reference_id'], 'idx_deposits_gateway_ref');
        });

        // 2. Withdrawals Table (Strict 4-Eyes Manual/Automated Threshold Disbursements)
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('withdrawal_number', 64)->unique();
            $table->string('payout_channel', 64); // BANK_TRANSFER, PROMPTPAY, USDT
            $table->string('account_name', 128);
            $table->string('account_number', 64);
            $table->string('bank_code', 32)->nullable();
            $table->decimal('amount', 20, 4);
            $table->decimal('fee_amount', 16, 4)->default('0.0000');
            $table->decimal('net_disbursement_amount', 20, 4);
            $table->string('currency', 3)->default('THB');
            $table->string('status', 24)->default('PENDING_APPROVAL'); // PENDING_APPROVAL, APPROVED, REJECTED, PROCESSING, COMPLETED, FAILED
            $table->uuid('approved_by_operator_id')->nullable();
            $table->uuid('disbursed_by_operator_id')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->string('idempotency_key', 128)->unique();
            $table->uuid('ledger_transaction_id')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status'], 'idx_withdrawals_user_status');
            $table->index(['status', 'created_at'], 'idx_withdrawals_status_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
        Schema::dropIfExists('deposit_transactions');
    }
};
