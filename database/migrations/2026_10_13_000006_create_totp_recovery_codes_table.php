<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('totp_recovery_codes')) {
            Schema::create('totp_recovery_codes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('totp_credential_id')->constrained('totp_credentials')->cascadeOnDelete();
                $table->char('batch_reference', 32);
                $table->char('code_hash', 64);
                $table->unsignedBigInteger('issued_at_epoch');
                $table->unsignedBigInteger('consumed_at_epoch')->nullable();
                $table->timestamps();
                $table->unique(['totp_credential_id', 'code_hash']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('totp_recovery_codes');
    }
};
