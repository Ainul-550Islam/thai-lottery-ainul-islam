<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('totp_proof_epoch')->default(0);
            $table->unsignedBigInteger('totp_proof_invalidated_at')->nullable();
            $table->string('totp_proof_invalidation_reason', 64)->nullable();
        });
    }

    public function down(): void
    {
        // Resetting a revocation generation can revive old external proof
        // assumptions. Require an audited recovery, not an automatic rollback.
        if (\Illuminate\Support\Facades\DB::table('users')->where('totp_proof_epoch', '>', 0)->exists()) {
            throw new RuntimeException('Refused: preserve durable TOTP proof revocation history before rollback.');
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['totp_proof_epoch', 'totp_proof_invalidated_at', 'totp_proof_invalidation_reason']);
        });
    }
};
