<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ip_rule_create_requests')) {
            Schema::create('ip_rule_create_requests', function (Blueprint $table): void {
                $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
                $table->char('key_hash', 64);
                $table->char('fingerprint', 64);
                $table->foreignId('rule_id')->constrained('ip_access_rules')->restrictOnDelete();
                $table->timestamp('created_at');
                $table->primary(['actor_id', 'key_hash']);
            });
        }
    }

    public function down(): void
    {
        // Drops replay protection, not rules/audits. Coordinate rollback with callers.
        Schema::dropIfExists('ip_rule_create_requests');
    }
};
