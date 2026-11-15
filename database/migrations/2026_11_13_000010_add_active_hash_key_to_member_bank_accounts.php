<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB-level duplicate protection for bank accounts.
 *
 * active_hash_key mirrors account_number_hash while the row is live and is NULL
 * once it is soft-deleted. A UNIQUE index on it (NULLs never collide) makes two
 * concurrent registrations of the same account fail at the database, while a
 * removed account can still be registered again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_bank_accounts', function (Blueprint $table): void {
            $table->string('active_hash_key', 64)->nullable()->after('account_number_hash');
        });

        // Backfill: only the oldest live row per hash receives the key, so legacy
        // duplicate data can never make the unique index below fail. The derived
        // table keeps this valid on MySQL (no self-referencing UPDATE subquery).
        DB::table('member_bank_accounts')
            ->whereNull('deleted_at')
            ->whereIn('id', static function ($query): void {
                $query->select('keep.id')->fromSub(
                    DB::table('member_bank_accounts')
                        ->selectRaw('MIN(id) as id')
                        ->whereNull('deleted_at')
                        ->groupBy('account_number_hash'),
                    'keep',
                );
            })
            ->update(['active_hash_key' => DB::raw('account_number_hash')]);

        Schema::table('member_bank_accounts', function (Blueprint $table): void {
            $table->unique('active_hash_key', 'member_bank_accounts_active_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('member_bank_accounts', function (Blueprint $table): void {
            $table->dropUnique('member_bank_accounts_active_hash_unique');
            $table->dropColumn('active_hash_key');
        });
    }
};
