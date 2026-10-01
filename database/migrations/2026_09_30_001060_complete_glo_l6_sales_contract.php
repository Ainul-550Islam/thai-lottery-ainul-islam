<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('glo_l6_sales', function (Blueprint $table): void {
            $table->unsignedInteger('series_number')->default(1);
            $table->decimal('sold_fraction', 20, 12)->default(0);
            $table->decimal('full_allocation_pool', 20, 2)->default(0);
            $table->decimal('proportional_prize_pool', 20, 2)->default(0);
            $table->string('currency', 3)->default('THB');
        });
    }

    public function down(): void
    {
        Schema::table('glo_l6_sales', function (Blueprint $table): void {
            $table->dropColumn([
                'series_number', 'sold_fraction', 'full_allocation_pool',
                'proportional_prize_pool', 'currency',
            ]);
        });
    }
};
