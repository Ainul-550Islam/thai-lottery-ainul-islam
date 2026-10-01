<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prize_disbursements', function (Blueprint $table): void {
            $table->foreignId('payout_id')->nullable()->change();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('draw_id')->nullable()->constrained('draws')->nullOnDelete();
            $table->decimal('prize_amount', 20, 2)->nullable();
            $table->string('reference_number', 96)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('prize_disbursements', function (Blueprint $table): void {
            $table->dropUnique(['reference_number']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['draw_id']);
            $table->dropColumn(['user_id', 'draw_id', 'prize_amount', 'reference_number']);
            $table->foreignId('payout_id')->nullable(false)->change();
        });
    }
};
