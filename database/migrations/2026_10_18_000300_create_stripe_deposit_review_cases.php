<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stripe_deposit_followup_inbox')) {
            Schema::create('stripe_deposit_followup_inbox', function (Blueprint $table): void {
                $table->id();
                $table->char('event_key', 64)->unique();
                $table->string('account_scope', 80);
                $table->char('configuration_hash', 64);
                $table->string('event_id', 255);
                $table->string('event_type', 100);
                $table->longText('response_cipher');
                $table->char('response_hash', 64);
                $table->unsignedBigInteger('received_epoch');
                $table->timestamp('created_at');
                $table->index(['account_scope', 'configuration_hash', 'id'], 'stripe_followup_scan');
            });
        }
        if (! Schema::hasTable('stripe_deposit_review_cases')) {
            Schema::create('stripe_deposit_review_cases', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('deposit_id')->unique()->constrained('deposits')->restrictOnDelete();
                $table->foreignId('payment_id')->unique()->constrained('payments')->restrictOnDelete();
                $table->uuid('settlement_receipt_uuid');
                $table->unsignedInteger('version')->default(0);
                $table->unsignedInteger('observation_count')->default(0);
                $table->char('evidence_hash', 64);
                $table->string('state', 24)->default('open');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('stripe_deposit_review_observations')) {
            Schema::create('stripe_deposit_review_observations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('case_id')->constrained('stripe_deposit_review_cases')->restrictOnDelete();
                $table->foreignId('inbox_id')->constrained('stripe_deposit_followup_inbox')->restrictOnDelete();
                $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $table->unsignedInteger('case_version');
                $table->char('source_hash', 64);
                $table->longText('response_cipher');
                $table->char('response_hash', 64);
                $table->timestamp('created_at');
                $table->unique(['case_id', 'inbox_id'], 'stripe_review_source_unique');
            });
        }
        if (! Schema::hasTable('stripe_deposit_review_acknowledgements')) {
            Schema::create('stripe_deposit_review_acknowledgements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('case_id')->constrained('stripe_deposit_review_cases')->restrictOnDelete();
                $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $table->unsignedInteger('case_version');
                $table->char('evidence_hash', 64);
                $table->longText('note_cipher');
                $table->char('note_hash', 64);
                $table->timestamp('created_at');
                $table->unique(['case_id', 'case_version'], 'stripe_review_ack_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_deposit_review_acknowledgements');
        Schema::dropIfExists('stripe_deposit_review_observations');
        Schema::dropIfExists('stripe_deposit_review_cases');
        Schema::dropIfExists('stripe_deposit_followup_inbox');
    }
};
