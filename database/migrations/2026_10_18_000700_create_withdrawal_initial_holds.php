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
        if (! Schema::hasTable('withdrawal_initial_holds')) {
            Schema::create('withdrawal_initial_holds', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('withdrawal_id')->unique()->constrained()->restrictOnDelete();
                $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->string('currency', 3);
                $table->decimal('amount', 20, 2);
                $table->char('terms_hash', 64);
                $table->text('origin_cipher');
                $table->enum('state', ['held', 'transferred', 'consumed', 'released']);
                $table->timestamp('created_at');
                $table->timestamp('transferred_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->index(['wallet_id', 'state']);
            });
        }
        Schema::table('withdrawal_reservations', function (Blueprint $table): void {
            $table->foreignId('initial_hold_id')->nullable()->unique()->constrained('withdrawal_initial_holds')->restrictOnDelete();
        });
        // No historical adoption, backfill, status edit or wallet adjustment.
    }

    public function down(): void
    {
        if (DB::table('withdrawal_initial_holds')->exists()
            || DB::table('withdrawal_reservations')->whereNotNull('initial_hold_id')->exists()) {
            throw new RuntimeException('Preserve initial withdrawal hold and handoff history before rollback.');
        }
        Schema::table('withdrawal_reservations', function (Blueprint $table): void {
            $table->dropForeign(['initial_hold_id']);
            $table->dropUnique(['initial_hold_id']);
            $table->dropColumn('initial_hold_id');
        });
        Schema::dropIfExists('withdrawal_initial_holds');
    }
};
