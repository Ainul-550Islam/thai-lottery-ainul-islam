<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_commissions', function (Blueprint $table): void {
            $table->decimal('stake_amount', 20, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_commissions', function (Blueprint $table): void {
            $table->dropColumn('stake_amount');
        });
    }
};
