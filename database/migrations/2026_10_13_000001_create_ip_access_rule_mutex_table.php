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
        Schema::create('ip_access_rule_mutex', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            // Serialization token, not a rule count or operator-facing metric.
            $table->unsignedBigInteger('version')->default(0);
        });
        DB::table('ip_access_rule_mutex')->insert(['id' => 1, 'version' => 0]);
    }

    public function down(): void
    {
        // Rule/audit history is retained; guarded creation fails until this is restored.
        Schema::dropIfExists('ip_access_rule_mutex');
    }
};
