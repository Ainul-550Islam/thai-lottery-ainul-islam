<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('withdrawal_requests') && ! Schema::hasColumn('withdrawal_requests', 'member_bank_account_id')) {
            Schema::table('withdrawal_requests', function (Blueprint $table): void {
                $table->foreignId('member_bank_account_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('member_bank_accounts')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('withdrawals') && ! Schema::hasColumn('withdrawals', 'member_bank_account_id')) {
            Schema::table('withdrawals', function (Blueprint $table): void {
                $table->foreignId('member_bank_account_id')
                    ->nullable()
                    ->after('wallet_id')
                    ->constrained('member_bank_accounts')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('withdrawals') && Schema::hasColumn('withdrawals', 'member_bank_account_id')) {
            Schema::table('withdrawals', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('member_bank_account_id');
            });
        }

        if (Schema::hasTable('withdrawal_requests') && Schema::hasColumn('withdrawal_requests', 'member_bank_account_id')) {
            Schema::table('withdrawal_requests', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('member_bank_account_id');
            });
        }
    }
};
