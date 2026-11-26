<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('trusted_device_id')->nullable()->constrained('trusted_devices')->nullOnDelete();
            $table->string('identifier_hash', 64);
            $table->string('ip', 45)->nullable();
            $table->string('ip_country', 2)->nullable();
            $table->string('device', 64)->nullable();
            $table->string('os', 64)->nullable();
            $table->string('browser', 64)->nullable();
            $table->string('device_fingerprint', 64)->nullable();
            $table->boolean('is_new_device')->default(false);
            $table->string('outcome', 32);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['member_id', 'occurred_at'], 'login_events_member_occurred_idx');
            $table->index(['member_id', 'device_fingerprint'], 'login_events_member_fingerprint_idx');
            $table->index('occurred_at', 'login_events_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
