<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('totp_action_proofs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('totp_credential_id')->constrained('totp_credentials')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->char('session_hash', 64);
            $table->char('credential_binding', 64);
            $table->string('action', 64);
            $table->unsignedBigInteger('issued_at_epoch');
            $table->unsignedBigInteger('expires_at_epoch');
            $table->unsignedBigInteger('consumed_at_epoch')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('totp_action_proofs');
    }
};
