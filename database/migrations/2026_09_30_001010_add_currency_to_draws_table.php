<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('draws', 'currency')) {
            Schema::table('draws', function (Blueprint $table): void {
                $table->string('currency', 3)->default('THB')->after('status')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('draws', 'currency')) {
            Schema::table('draws', function (Blueprint $table): void {
                $table->dropIndex(['currency']);
                $table->dropColumn('currency');
            });
        }
    }
};
