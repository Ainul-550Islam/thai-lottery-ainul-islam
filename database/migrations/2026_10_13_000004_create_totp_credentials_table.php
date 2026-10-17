<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('totp_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('enrollment_reference', 32);
            $table->text('secret');
            $table->unsignedBigInteger('confirmed_at_epoch');
            $table->unsignedBigInteger('last_accepted_step');
            $table->timestamps();
        });
        Schema::table('totp_pending_enrollments', function (Blueprint $table): void {
            $table->unsignedInteger('confirmation_attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('totp_pending_enrollments', function (Blueprint $table): void {
            $table->dropColumn('confirmation_attempts');
        });
        Schema::dropIfExists('totp_credentials');
    }
};
