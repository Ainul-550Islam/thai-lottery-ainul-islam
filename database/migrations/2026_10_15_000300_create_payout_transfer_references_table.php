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
        if (! Schema::hasTable('payout_transfer_references')) {
            Schema::create('payout_transfer_references', function (Blueprint $table): void {
                $table->id();
                $table->char('identity_key', 64)->unique();
                $table->foreignId('payout_id')->unique()->constrained()->restrictOnDelete();
                $table->foreignId('payment_id')->unique()->constrained()->restrictOnDelete();
                $table->string('gateway', 40);
                $table->string('account_scope', 80);
                $table->char('reference_hash', 64);
                $table->timestamp('created_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payout_transfer_references') && DB::table('payout_transfer_references')->exists()) {
            throw new RuntimeException('Refused: preserve external prize transfer ownership before rollback.');
        }
        Schema::dropIfExists('payout_transfer_references');
    }
};
