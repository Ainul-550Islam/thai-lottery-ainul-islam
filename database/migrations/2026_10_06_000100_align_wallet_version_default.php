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
        if (! Schema::hasTable('wallets') || ! Schema::hasColumn('wallets', 'version')) {
            return;
        }

        DB::table('wallets')->where('version', 0)->update(['version' => 1]);

        Schema::table('wallets', function (Blueprint $table): void {
            $table->unsignedBigInteger('version')->default(1)->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wallets') || ! Schema::hasColumn('wallets', 'version')) {
            return;
        }

        Schema::table('wallets', function (Blueprint $table): void {
            $table->unsignedBigInteger('version')->default(0)->change();
        });
    }
};
