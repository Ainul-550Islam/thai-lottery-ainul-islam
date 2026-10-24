<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deposit_checkout_attempts')) {
            Schema::create('deposit_checkout_attempts', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('deposit_id')->unique()->constrained('deposits')->restrictOnDelete();
                $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
                $table->string('provider', 32);
                $table->string('account_scope', 80);
                $table->char('terms_hash', 64);
                $table->char('configuration_hash', 64);
                $table->char('request_hash', 64);
                $table->string('dispatch_key', 80)->unique();
                $table->string('state', 24)->index();
                $table->unsignedTinyInteger('attempt_count')->default(0);
                $table->char('fence', 64)->nullable();
                $table->timestamp('dispatched_at')->nullable();
                $table->longText('response_cipher')->nullable();
                $table->char('response_hash', 64)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_checkout_attempts');
    }
};
