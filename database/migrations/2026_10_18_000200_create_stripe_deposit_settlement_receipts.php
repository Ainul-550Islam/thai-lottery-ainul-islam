<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stripe_deposit_capture_owners')) {
            Schema::create('stripe_deposit_capture_owners', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('attempt_id')->unique()->constrained('deposit_checkout_attempts')->restrictOnDelete();
                $table->char('intent_key', 64)->unique();
                $table->char('charge_key', 64)->unique();
                $table->timestamp('created_at');
            });
        }
        if (! Schema::hasTable('stripe_deposit_settlement_receipts')) {
            Schema::create('stripe_deposit_settlement_receipts', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('attempt_id')->constrained('deposit_checkout_attempts')->restrictOnDelete();
                $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $table->char('binding_hash', 64);
                $table->string('session_reference', 255);
                $table->string('intent_reference', 255);
                $table->string('charge_reference', 255);
                $table->longText('response_cipher');
                $table->char('response_hash', 64);
                $table->unsignedBigInteger('observed_epoch');
                $table->timestamp('applied_at')->nullable();
                $table->timestamp('created_at');
                $table->index(['attempt_id', 'id']);
            });
        }
        Schema::table('deposit_checkout_attempts', function (Blueprint $table): void {
            $table->uuid('settlement_receipt_uuid')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deposit_checkout_attempts', function (Blueprint $table): void {
            $table->dropColumn('settlement_receipt_uuid');
        });
        Schema::dropIfExists('stripe_deposit_settlement_receipts');
        Schema::dropIfExists('stripe_deposit_capture_owners');
    }
};
