<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ip_access_rules')) {
            Schema::create('ip_access_rules', function (Blueprint $table): void {
                $table->id();
                $table->string('ip_range', 64);
                $table->string('reason_code', 64);
                // UTC instants, independent of DB/app wall-clock timezone settings.
                $table->unsignedBigInteger('starts_at_epoch');
                $table->unsignedBigInteger('expires_at_epoch')->nullable();
                $table->unsignedBigInteger('revoked_at_epoch')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['revoked_at_epoch', 'starts_at_epoch'], 'ip_access_rules_active_index');
                $table->index('expires_at_epoch');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_access_rules');
    }
};
