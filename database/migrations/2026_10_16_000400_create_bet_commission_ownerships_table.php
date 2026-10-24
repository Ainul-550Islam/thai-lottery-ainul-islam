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
        // NULL remains deliberately historical/unbound. No retroactive adoption.
        Schema::table('bet_purchase_ownerships', function (Blueprint $table): void {
            $table->string('commission_snapshot_hash', 64)->nullable();
            $table->unsignedInteger('commission_snapshot_count')->nullable();
        });
        if (! Schema::hasTable('bet_commission_ownerships')) {
            Schema::create('bet_commission_ownerships', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('purchase_ownership_id')->constrained('bet_purchase_ownerships')->restrictOnDelete();
                $table->foreignId('commission_id')->unique()->constrained('agent_commissions')->restrictOnDelete();
                $table->foreignId('agent_id')->constrained('agents')->restrictOnDelete();
                $table->string('currency', 3);
                $table->string('amount', 32);
                $table->string('terms_hash', 64);
                $table->string('state', 16)->default('active');
                $table->timestamp('cancelled_at')->nullable();
                $table->string('cancelled_reason', 64)->nullable();
                $table->timestamps();
                $table->index(['agent_id', 'state'], 'bet_commission_agent_state_index');
            });
        }
    }

    public function down(): void
    {
        if (DB::table('bet_commission_ownerships')->exists()
            || DB::table('bet_purchase_ownerships')->whereNotNull('commission_snapshot_hash')->exists()) {
            throw new RuntimeException('Refusing to erase durable commission ownership/snapshot history.');
        }
        Schema::dropIfExists('bet_commission_ownerships');
        Schema::table('bet_purchase_ownerships', fn (Blueprint $table) => $table->dropColumn(['commission_snapshot_hash', 'commission_snapshot_count']));
    }
};
