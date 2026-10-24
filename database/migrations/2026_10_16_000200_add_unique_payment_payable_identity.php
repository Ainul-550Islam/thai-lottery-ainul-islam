<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX_NAME = 'payments_payable_once_unique';

    public function up(): void
    {
        $duplicate = DB::table('payments')
            ->select(['payable_type', 'payable_id'])
            ->whereNull('deleted_at')
            ->whereNotNull('payable_type')
            ->whereNotNull('payable_id')
            ->groupBy(['payable_type', 'payable_id'])
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicate !== null) {
            // Payment records are financial evidence. Do not pick a winner or
            // delete historical rows automatically; operators must reconcile
            // duplicates before this database invariant can be activated.
            throw new RuntimeException(
                'Cannot enforce one payment per payable: duplicate payable identities exist in payments. Reconcile the financial records without deleting evidence, then rerun migrations.',
            );
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->index(['payable_type', 'payable_id'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX_NAME);
        });
    }
};
