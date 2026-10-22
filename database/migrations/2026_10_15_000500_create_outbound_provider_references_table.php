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
        Schema::create('outbound_provider_references', function (Blueprint $table): void {
            $table->id();
            $table->char('identity_key', 64)->unique();
            $table->foreignId('withdrawal_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payout_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('account_scope', 80);
            $table->char('reference_hash', 64);
            $table->timestamp('created_at');
        });
        // SQL enforces exactly one real owner; nullable UNIQUEs permit multiple owners of the other domain.
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared('CREATE TRIGGER outbound_owner_'.strtolower($operation).' BEFORE '.$operation.' ON outbound_provider_references '
                    .'WHEN (NEW.withdrawal_id IS NULL) = (NEW.payout_id IS NULL) BEGIN SELECT RAISE(ABORT, \'Exactly one outbound owner required\'); END');
            }
        } else {
            DB::statement('ALTER TABLE outbound_provider_references ADD CONSTRAINT outbound_exactly_one_owner CHECK ((withdrawal_id IS NULL) <> (payout_id IS NULL))');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('outbound_provider_references') && DB::table('outbound_provider_references')->exists()) {
            throw new RuntimeException('Refused: preserve common outbound reference ownership before rollback.');
        }
        Schema::dropIfExists('outbound_provider_references');
    }
};
