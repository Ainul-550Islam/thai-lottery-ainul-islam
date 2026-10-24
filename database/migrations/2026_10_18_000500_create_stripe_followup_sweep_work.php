<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stripe_followup_sweep_work')) {
            Schema::create('stripe_followup_sweep_work', function (Blueprint $t): void {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->foreignId('inbox_id')->unique()->constrained('stripe_deposit_followup_inbox')->restrictOnDelete();
                $t->string('account_scope', 191);
                $t->char('configuration_hash', 64);
                $t->char('source_hash', 64);
                $t->string('state', 24);
                $t->unsignedInteger('generation')->default(0);
                $t->unsignedInteger('budget_used')->default(0);
                $t->foreignId('lease_actor_id')->nullable()->constrained('users')->restrictOnDelete();
                $t->uuid('lease_token')->nullable();
                $t->unsignedBigInteger('lease_until')->nullable();
                $t->unsignedBigInteger('available_epoch');
                $t->foreignId('last_decision_id')->nullable()->constrained('stripe_followup_route_decisions')->restrictOnDelete();
                $t->string('last_code', 100)->nullable();
                $t->unsignedBigInteger('last_event_id')->nullable();
                $t->unsignedBigInteger('created_epoch');
                $t->unsignedBigInteger('updated_epoch');
                $t->index(['account_scope', 'configuration_hash', 'state', 'available_epoch'], 'stripe_sweep_due');
            });
        }
        if (! Schema::hasTable('stripe_followup_sweep_events')) {
            Schema::create('stripe_followup_sweep_events', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('work_id')->constrained('stripe_followup_sweep_work')->restrictOnDelete();
                $t->unsignedInteger('generation');
                $t->string('kind', 24);
                $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
                $t->foreignId('previous_event_id')->nullable()->unique()->constrained('stripe_followup_sweep_events')->restrictOnDelete();
                $t->longText('response_cipher');
                $t->char('response_hash', 64);
                $t->unsignedBigInteger('created_epoch');
                $t->index(['work_id', 'generation'], 'stripe_sweep_event_generation');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_followup_sweep_events');
        Schema::dropIfExists('stripe_followup_sweep_work');
    }
};
