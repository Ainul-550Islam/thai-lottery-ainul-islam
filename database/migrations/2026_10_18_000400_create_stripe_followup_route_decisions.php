<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stripe_followup_route_decisions')) {
            Schema::create('stripe_followup_route_decisions', function (Blueprint $table): void {
                $table->id();
                $table->char('decision_key', 64)->unique();
                $table->foreignId('inbox_id')->constrained('stripe_deposit_followup_inbox')->restrictOnDelete();
                $table->foreignId('deposit_id')->nullable()->constrained('deposits')->restrictOnDelete();
                $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $table->string('state', 24);
                $table->uuid('case_uuid')->nullable();
                $table->unsignedInteger('case_version')->nullable();
                $table->char('evidence_hash', 64)->nullable();
                $table->char('source_hash', 64);
                $table->longText('response_cipher');
                $table->char('response_hash', 64);
                $table->timestamp('created_at');
                $table->index(['inbox_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_followup_route_decisions');
    }
};
