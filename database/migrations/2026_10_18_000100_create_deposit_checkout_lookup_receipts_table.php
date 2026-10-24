<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deposit_checkout_lookup_receipts')) {
            Schema::create('deposit_checkout_lookup_receipts', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('attempt_id')->unique()->constrained('deposit_checkout_attempts')->restrictOnDelete();
                $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $table->char('terms_hash', 64);
                $table->char('request_hash', 64);
                $table->char('configuration_hash', 64);
                $table->char('fence', 64);
                $table->char('dispatch_key_hash', 64);
                $table->string('account_scope', 80);
                $table->string('provider_reference', 255);
                $table->longText('response_cipher');
                $table->char('response_hash', 64);
                $table->timestamp('created_at');
            });
        }
        Schema::table('deposit_checkout_attempts', function (Blueprint $table): void {
            $table->uuid('lookup_receipt_uuid')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deposit_checkout_attempts', function (Blueprint $table): void {
            $table->dropColumn('lookup_receipt_uuid');
        });
        Schema::dropIfExists('deposit_checkout_lookup_receipts');
    }
};
