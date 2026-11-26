<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashback_accruals', function (Blueprint $table): void {
            $table->decimal('clawback_amount', 18, 2)->nullable()->after('reversal_reason');
            $table->decimal('clawback_shortfall', 18, 2)->nullable()->after('clawback_amount');
            $table->foreignId('clawback_transaction_id')
                ->nullable()
                ->after('clawback_shortfall')
                ->constrained('financial_transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cashback_accruals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('clawback_transaction_id');
            $table->dropColumn(['clawback_amount', 'clawback_shortfall']);
        });
    }
};
