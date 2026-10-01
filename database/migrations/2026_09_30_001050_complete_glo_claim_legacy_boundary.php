<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('glo_prize_claims', function (Blueprint $table): void {
            $table->foreignId('ticket_id')->nullable()->change();
            $table->foreignId('draw_id')->nullable()->change();
            $table->foreignId('claimant_user_id')->nullable()->change();
            $table->string('product', 8)->nullable()->change();
            $table->string('prize_category', 32)->nullable()->change();
            $table->decimal('gross_prize', 20, 2)->nullable()->change();
            $table->decimal('stamp_duty', 20, 2)->nullable()->change();
            $table->decimal('net_prize', 20, 2)->nullable()->change();
            $table->timestamp('submitted_at')->nullable()->change();
            $table->string('draw_date', 10)->nullable();
            $table->string('prize_tier', 32)->nullable();
            $table->string('claim_status', 32)->nullable();
            $table->decimal('gross_amount', 20, 2)->nullable();
            $table->decimal('stamp_duty_amount', 20, 2)->nullable();
            $table->decimal('net_payable_amount', 20, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('glo_prize_claims', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropColumn([
                'draw_date', 'prize_tier', 'claim_status', 'gross_amount',
                'stamp_duty_amount', 'net_payable_amount', 'currency', 'user_id',
            ]);
        });
    }
};
