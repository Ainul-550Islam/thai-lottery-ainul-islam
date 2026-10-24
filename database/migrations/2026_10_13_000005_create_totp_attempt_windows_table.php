<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('totp_attempt_windows')) {
            Schema::create('totp_attempt_windows', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('operation', 32);
                $table->unsignedBigInteger('window_started_at_epoch');
                $table->unsignedInteger('attempts');
                $table->timestamps();
                $table->unique(['user_id', 'operation']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('totp_attempt_windows');
    }
};
