<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('draw_results', 'source_state')) {
            Schema::table('draw_results', function (Blueprint $table): void {
                $table->string('source_state', 40)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('draw_results', 'source_state')) {
            Schema::table('draw_results', function (Blueprint $table): void {
                $table->dropIndex(['source_state']);
                $table->dropColumn('source_state');
            });
        }
    }
};
