<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('totp_pending_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('reference', 32);
            // Laravel authenticated encryption; never a plaintext Base32 column.
            $table->text('secret');
            $table->unsignedBigInteger('issued_at_epoch');
            $table->unsignedBigInteger('expires_at_epoch')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('totp_pending_enrollments');
    }
};
