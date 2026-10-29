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
        // A single identity lock serializes both the primary financial reference
        // and provider aliases. Separate unique indexes alone do not prevent a
        // concurrent alias from colliding with an outbound reference.
        Schema::create('financial_provider_reference_locks', function (Blueprint $table): void {
            $table->char('identity_key', 64)->primary();
            $table->timestamp('created_at');
        });

        Schema::create('financial_provider_reference_aliases', function (Blueprint $table): void {
            $table->id();
            $table->char('identity_key', 64)->unique();
            $table->foreignId('deposit_id')->unique()->constrained('deposits')->restrictOnDelete();
            $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
            $table->foreignId('attempt_id')->unique()->constrained('deposit_checkout_attempts')->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('account_scope', 80);
            $table->char('reference_hash', 64);
            $table->char('facts_hash', 64);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if (DB::table('financial_provider_reference_aliases')->exists()
            || DB::table('financial_provider_reference_locks')->exists()) {
            throw new RuntimeException('Refused: preserve financial reference ownership before rollback.');
        }

        Schema::dropIfExists('financial_provider_reference_aliases');
        Schema::dropIfExists('financial_provider_reference_locks');
    }
};
