<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('kyc_documents', 'submitted_at')) {
            Schema::table('kyc_documents', function (Blueprint $table): void {
                $table->timestamp('submitted_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('kyc_documents', 'submitted_at')) {
            Schema::table('kyc_documents', function (Blueprint $table): void {
                $table->dropIndex(['submitted_at']);
                $table->dropColumn('submitted_at');
            });
        }
    }
};
