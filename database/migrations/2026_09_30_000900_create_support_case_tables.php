<?php

// TYPE: Laravel migration
// PURPOSE: Create owner-scoped support cases and public support messages without reusing anonymous ContactMessage rows.

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_cases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('public_reference', 32)->unique();
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->string('category', 64);
            $table->string('priority', 32)->default('normal');
            $table->string('status', 32)->default('open');
            $table->string('subject', 180);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['owner_user_id', 'status']);
            $table->index(['status', 'priority']);
        });

        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('support_case_id')->constrained('support_cases')->cascadeOnDelete();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('internal')->default(false);
            $table->timestamps();

            $table->index(['support_case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_cases');
    }
};
