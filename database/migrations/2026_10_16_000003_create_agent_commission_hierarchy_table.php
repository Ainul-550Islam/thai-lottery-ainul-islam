<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closure-table hierarchy (`agent_hierarchies`), commission override rules
 * (`commission_rules`), and immutable commission audit ledger
 * (`commission_ledgers`) unified onto the canonical unsignedBigInteger foreign
 * keys of `agents.id`, `users.id`, and `draws.id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agents')) {
            Schema::create('agents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
                $table->string('agent_code', 32)->unique();
                $table->unsignedTinyInteger('tier_level')->default(1)->index();
                $table->decimal('direct_commission_rate', 8, 6)->default(0);
                $table->string('status', 32)->default('active')->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('agent_hierarchies')) {
            Schema::create('agent_hierarchies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('ancestor_id')->constrained('agents')->cascadeOnDelete();
                $table->foreignId('descendant_id')->constrained('agents')->cascadeOnDelete();
                $table->unsignedTinyInteger('depth')->index();
                $table->timestamps();

                $table->unique(['ancestor_id', 'descendant_id']);
                $table->index(['descendant_id', 'depth']);
            });
        }

        if (! Schema::hasTable('commission_rules')) {
            Schema::create('commission_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('game_type', 32)->default('ALL')->index();
                $table->unsignedTinyInteger('tier_level')->index();
                $table->decimal('override_percentage', 8, 4)->default(0);
                $table->string('calculation_basis', 32)->default('STAKE');
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();

                $table->unique(['game_type', 'tier_level']);
            });
        }

        if (! Schema::hasTable('commission_ledgers')) {
            Schema::create('commission_ledgers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('agent_id')->constrained('agents')->restrictOnDelete();
                $table->foreignId('source_user_id')->constrained('users')->restrictOnDelete();
                $table->uuid('lottery_entry_id')->index();
                $table->foreignId('draw_id')->constrained('draws')->restrictOnDelete();
                $table->unsignedTinyInteger('hierarchy_depth');
                $table->decimal('basis_amount', 19, 4);
                $table->decimal('commission_rate', 8, 4);
                $table->decimal('commission_amount', 19, 4);
                $table->string('status', 32)->default('POSTED')->index();
                $table->uuid('ledger_transaction_id')->nullable()->index();
                $table->string('idempotency_key', 128)->unique();
                $table->timestamp('calculated_at')->nullable();
                $table->timestamps();

                $table->index(['agent_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_ledgers');
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('agent_hierarchies');
    }
};
