<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draw_cancellation_ownerships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draw_id')->unique()->constrained('draws')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('previous_status', 32);
            $table->string('reason', 500);
            $table->json('participants');
            $table->string('terms_hash', 64);
            $table->json('outcomes')->nullable();
            $table->string('outcome_hash', 64)->nullable();
            $table->string('state', 16);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('draw_cancellation_ownerships') && DB::table('draw_cancellation_ownerships')->exists()) {
            throw new RuntimeException('Refusing to erase durable draw cancellation ownership. Controlled retention review is required.');
        }
        Schema::dropIfExists('draw_cancellation_ownerships');
    }
};
