<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ledger_transactions') || ! Schema::hasColumn('ledger_transactions', 'previous_hash')) {
            return;
        }

        Schema::table('ledger_transactions', function (Blueprint $table): void {
            $table->unique('previous_hash', 'ledger_transactions_previous_hash_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ledger_transactions')) {
            return;
        }

        Schema::table('ledger_transactions', function (Blueprint $table): void {
            $table->dropUnique('ledger_transactions_previous_hash_unique');
        });
    }
};
