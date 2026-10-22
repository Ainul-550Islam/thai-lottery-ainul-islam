<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responsible_gaming_limit_ownerships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('version_id')->unique()->constrained('responsible_gaming_limit_versions')->restrictOnDelete();
            $table->string('request_identity', 64)->unique();
            $table->string('payload_hash', 64);
            $table->string('terms_hash', 64);
            $table->string('basis_hash', 64);
            $table->string('timezone', 64);
            $table->bigInteger('activation_epoch');
            $table->bigInteger('expiry_epoch')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responsible_gaming_limit_ownerships');
    }
};
