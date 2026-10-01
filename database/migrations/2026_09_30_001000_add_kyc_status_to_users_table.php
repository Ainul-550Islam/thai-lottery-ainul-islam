<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'kyc_status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('kyc_status', 32)->default('unverified')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'kyc_status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(['kyc_status']);
                $table->dropColumn('kyc_status');
            });
        }
    }
};
