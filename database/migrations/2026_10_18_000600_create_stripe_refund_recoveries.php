<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stripe_refund_recovery_intents')) {
            Schema::create('stripe_refund_recovery_intents', function (Blueprint $t): void {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->foreignId('deposit_id')->unique()->constrained('deposits')->restrictOnDelete();
                $t->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
                $t->foreignId('inbox_id')->constrained('stripe_deposit_followup_inbox')->restrictOnDelete();
                $t->string('account_scope', 191);
                $t->char('configuration_hash', 64);
                $t->string('refund_reference', 255);
                $t->char('identity_key', 64)->unique();
                $t->longText('response_cipher');
                $t->char('response_hash', 64);
                $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $t->unsignedBigInteger('created_epoch');
            });
        }
        if (! Schema::hasTable('stripe_refund_recovery_receipts')) {
            Schema::create('stripe_refund_recovery_receipts', function (Blueprint $t): void {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->foreignId('intent_id')->constrained('stripe_refund_recovery_intents')->restrictOnDelete();
                $t->foreignId('inbox_id')->constrained('stripe_deposit_followup_inbox')->restrictOnDelete();
                $t->foreignId('previous_receipt_id')->nullable()->unique()->constrained('stripe_refund_recovery_receipts')->restrictOnDelete();
                $t->longText('response_cipher');
                $t->char('response_hash', 64);
                $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $t->unsignedBigInteger('observed_epoch');
                $t->index(['intent_id', 'id']);
            });
        }
        if (! Schema::hasTable('stripe_refund_recoveries')) {
            Schema::create('stripe_refund_recoveries', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('deposit_id')->unique()->constrained('deposits')->restrictOnDelete();
                $t->foreignId('intent_id')->unique()->constrained('stripe_refund_recovery_intents')->restrictOnDelete();
                $t->foreignId('receipt_id')->unique()->constrained('stripe_refund_recovery_receipts')->restrictOnDelete();
                $t->foreignId('financial_transaction_id')->unique()->constrained('financial_transactions')->restrictOnDelete();
                $t->longText('response_cipher');
                $t->char('response_hash', 64);
                $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $t->unsignedBigInteger('applied_epoch');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_refund_recoveries');
        Schema::dropIfExists('stripe_refund_recovery_receipts');
        Schema::dropIfExists('stripe_refund_recovery_intents');
    }
};
