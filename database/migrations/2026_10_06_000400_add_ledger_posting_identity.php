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
        if (! Schema::hasTable('ledger_entries')) {
            return;
        }

        $duplicate = DB::table('ledger_entries')
            ->select([
                'financial_transaction_id',
                'ledger_account_id',
                'type',
                DB::raw('COUNT(*) AS aggregate_count'),
            ])
            ->groupBy('financial_transaction_id', 'ledger_account_id', 'type')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            throw new RuntimeException(
                'Cannot add ledger posting identity: duplicate active transaction/account/side rows exist.'
            );
        }

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->unique(
                ['financial_transaction_id', 'ledger_account_id', 'type'],
                'ledger_entries_transaction_account_side_unique',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('ledger_entries')) {
            return;
        }

        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropUnique('ledger_entries_transaction_account_side_unique');
        });
    }
};
