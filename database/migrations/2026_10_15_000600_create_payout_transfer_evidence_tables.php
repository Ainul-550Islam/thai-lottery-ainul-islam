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
        if (! Schema::hasTable('payout_transfer_evidence')) {
            Schema::create('payout_transfer_evidence', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('payout_id')->constrained()->restrictOnDelete();
                $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
                $table->char('facts_hash', 64);
                $table->char('blob_key', 64)->unique();
                $table->char('sha256', 64);
                $table->unsignedInteger('bytes');
                $table->string('mime', 40);
                $table->boolean('legal_hold')->default(true);
                $table->timestamp('retention_until')->nullable();
                $table->foreignId('consumed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->string('outcome', 16)->nullable();
                $table->char('provider_reference_hash', 64)->nullable();
                $table->timestamp('consumed_at')->nullable();
                $table->timestamp('created_at');
                $table->index(['payout_id', 'id']);
            });
        }
        if (! Schema::hasTable('payout_transfer_evidence_reads')) {
            Schema::create('payout_transfer_evidence_reads', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('evidence_id')->constrained('payout_transfer_evidence')->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->timestamp('created_at');
                $table->index(['evidence_id', 'user_id', 'created_at'], 'payout_evidence_read_lookup');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payout_transfer_evidence') && DB::table('payout_transfer_evidence')->exists()) {
            throw new RuntimeException('Refused: preserve private payout evidence, read audits and financial provenance before rollback.');
        }
        Schema::dropIfExists('payout_transfer_evidence_reads');
        Schema::dropIfExists('payout_transfer_evidence');
    }
};
