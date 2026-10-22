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
        Schema::create('withdrawal_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('withdrawal_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('maker_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('checker_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->char('request_key', 64)->unique();
            $table->char('snapshot_hash', 64);
            $table->char('evidence_sha256', 64);
            $table->unsignedInteger('evidence_bytes');
            $table->text('facts');
            $table->string('status', 24)->default('pending');
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['withdrawal_id', 'status']);
        });
        Schema::create('withdrawal_reconciliation_references', function (Blueprint $table): void {
            $table->char('provider_key', 64)->primary();
            $table->foreignId('withdrawal_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('reconciliation_id')->constrained('withdrawal_reconciliations')->restrictOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        // Financial evidence is deliberately not silently dropped by rollback.
        if (Schema::hasTable('withdrawal_reconciliations') && DB::table('withdrawal_reconciliations')->exists()) {
            throw new RuntimeException('Refused: preserve withdrawal reconciliation evidence before schema rollback.');
        }
        Schema::dropIfExists('withdrawal_reconciliation_references');
        Schema::dropIfExists('withdrawal_reconciliations');
    }
};
