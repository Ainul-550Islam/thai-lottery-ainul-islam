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
        Schema::create('financial_provider_reference_owners', function (Blueprint $table): void {
            $table->id();
            $table->char('identity_key', 64)->unique();
            $table->foreignId('deposit_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('withdrawal_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payout_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('account_scope', 80);
            $table->char('reference_hash', 64);
            $table->char('facts_hash', 64);
            $table->timestamp('created_at');
        });
        $check = '((NEW.deposit_id IS NOT NULL) + (NEW.withdrawal_id IS NOT NULL) + (NEW.payout_id IS NOT NULL)) <> 1';
        if (DB::getDriverName() === 'sqlite') {
            foreach (['INSERT', 'UPDATE'] as $op) {
                DB::unprepared('CREATE TRIGGER financial_reference_owner_'.strtolower($op).' BEFORE '.$op.' ON financial_provider_reference_owners WHEN '.$check." BEGIN SELECT RAISE(ABORT, 'Exactly one financial owner required'); END");
            }
        } else {
            DB::statement('ALTER TABLE financial_provider_reference_owners ADD CONSTRAINT financial_reference_one_owner CHECK (((deposit_id IS NOT NULL) + (withdrawal_id IS NOT NULL) + (payout_id IS NOT NULL)) = 1)');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('financial_provider_reference_owners') && DB::table('financial_provider_reference_owners')->exists()) {
            throw new RuntimeException('Refused: preserve immutable financial reference ownership before rollback.');
        }
        Schema::dropIfExists('financial_provider_reference_owners');
    }
};
