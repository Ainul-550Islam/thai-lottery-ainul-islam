<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_catalog_items', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('type', 32);
            $table->unsignedInteger('points_cost');
            $table->json('payload');
            $table->unsignedInteger('stock')->nullable();
            $table->unsignedInteger('per_member_limit')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'starts_at', 'ends_at'], 'reward_catalog_items_availability_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reward_catalog_items');
    }
};
