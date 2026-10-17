<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Multi-Tier Agent Hierarchy & Commission Ledger.
     */
    public function up(): void
    {
        // 1. Agents Table
        Schema::create('agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->unique();
            $table->string('agent_code', 32)->unique();
            $table->uuid('parent_agent_id')->nullable()->index();
            $table->unsignedTinyInteger('tier_level')->default(1); // 1 = Master, 2 = Super, 3 = Senior, 4 = Direct
            $table->string('lineage_path', 512)->default('/'); // E.g. /uuid1/uuid2/uuid3/
            $table->string('status', 24)->default('ACTIVE'); // ACTIVE, SUSPENDED, TERMINATED
            $table->decimal('direct_commission_rate', 5, 2)->default('5.00'); // %
            $table->timestamps();

            $table->foreign('parent_agent_id')->references('id')->on('agents')->onDelete('restrict');
            $table->index(['tier_level', 'status'], 'idx_agents_tier_status');
        });

        // 2. Agent Hierarchies (Closure Table for lightning-fast sub-tree and ancestor queries)
        Schema::create('agent_hierarchies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ancestor_id')->index();
            $table->uuid('descendant_id')->index();
            $table->unsignedTinyInteger('depth'); // 0 = self, 1 = direct child, 2 = grandchild, etc.
            $table->timestamps();

            $table->unique(['ancestor_id', 'descendant_id'], 'uq_hierarchy_ancestor_descendant');
            $table->foreign('ancestor_id')->references('id')->on('agents')->onDelete('cascade');
            $table->foreign('descendant_id')->references('id')->on('agents')->onDelete('cascade');
        });

        // 3. Commission Rules Table
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('game_type', 32)->default('ALL');
            $table->unsignedTinyInteger('tier_level');
            $table->decimal('override_percentage', 5, 2); // E.g. L1 = 1.5%, L2 = 1.0%, L3 = 0.5%
            $table->string('calculation_basis', 32)->default('TURNOVER'); // TURNOVER, GGR (Gross Gaming Revenue), NGR
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['game_type', 'tier_level'], 'uq_commission_rule_game_tier');
        });

        // 4. Commission Ledgers Table
        Schema::create('commission_ledgers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('agent_id')->index();
            $table->uuid('source_user_id')->index();
            $table->uuid('lottery_entry_id')->index();
            $table->uuid('draw_id')->index();
            $table->unsignedTinyInteger('hierarchy_depth');
            $table->decimal('basis_amount', 16, 4); // stake or ggr
            $table->decimal('commission_rate', 5, 2);
            $table->decimal('commission_amount', 16, 4);
            $table->string('status', 24)->default('POSTED'); // PENDING, POSTED, SETTLED, VOIDED
            $table->uuid('ledger_transaction_id')->nullable()->index();
            $table->string('idempotency_key', 128)->unique();
            $table->timestamp('calculated_at')->useCurrent();
            $table->timestamps();

            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('restrict');
            $table->index(['draw_id', 'agent_id'], 'idx_comm_draw_agent');
            $table->index(['agent_id', 'calculated_at'], 'idx_comm_agent_calc');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_ledgers');
        Schema::dropIfExists('commission_rules');
        Schema::dropIfExists('agent_hierarchies');
        Schema::dropIfExists('agents');
    }
};
