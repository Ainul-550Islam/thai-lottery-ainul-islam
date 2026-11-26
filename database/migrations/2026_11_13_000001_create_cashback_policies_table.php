<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashback_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('grade', 32);
            $table->unsignedInteger('rate_bps');
            $table->string('period', 16)->default('daily');
            $table->string('basis', 32)->default('real_net_loss');
            $table->decimal('min_claim', 18, 2);
            $table->decimal('max_per_period', 18, 2);
            $table->unsignedInteger('expiry_days');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['grade', 'period'], 'cashback_policies_grade_period_unique');
            $table->index(['grade', 'active'], 'cashback_policies_grade_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashback_policies');
    }
};
