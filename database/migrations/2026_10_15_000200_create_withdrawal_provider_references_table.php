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
        if (! Schema::hasTable('withdrawal_provider_references')) {
            Schema::create('withdrawal_provider_references', function (Blueprint $table): void {
                $table->id();
                $table->char('identity_key', 64)->unique();
                $table->foreignId('withdrawal_id')->unique()->constrained()->restrictOnDelete();
                $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
                $table->string('provider', 40);
                $table->string('account_scope', 80);
                $table->char('reference_hash', 64);
                $table->timestamp('created_at');
            });
        }
        // No invented historical owners, reference guessing, financial writes,
        // eager metadata rewrite or destructive deduplication. Runtime binding
        // checks existing (including soft-deleted) facts before inserting a key.
    }

    public function down(): void
    {
        if (Schema::hasTable('withdrawal_provider_references') && DB::table('withdrawal_provider_references')->exists()) {
            throw new RuntimeException('Refused: preserve shared withdrawal provider-reference ownership before rollback.');
        }
        Schema::dropIfExists('withdrawal_provider_references');
    }
};
