<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Points multiplier campaigns — the only source of a points multiplier.
 *
 * BASIS POINTS, NOT FLOATS
 * `multiplier_bps` is an integer: 10000 bps = 1.0x, 15000 bps = 1.5x. Points
 * arithmetic is integer in the existing LoyaltyPointsService (it returns int),
 * so a multiplier expressed in bps keeps the whole path integer and makes
 * rounding a decision rather than an accident. The service floors the product,
 * which is the conservative choice: a member never receives a fractional point
 * invented by rounding up.
 *
 * WINDOW SEMANTICS
 * A campaign is active at time t when `active` is true, starts_at <= t and
 * (ends_at is null OR ends_at >= t). Both bounds are INCLUSIVE; the window
 * boundary tests assert exactly that, because an off-by-one-second gap at
 * midnight on the 1st of a month is the kind of defect nobody notices for
 * a year.
 *
 * `game_scope` is a nullable game code. Null means "every game".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('points_multiplier_campaigns')) {
            return;
        }

        Schema::create('points_multiplier_campaigns', function (Blueprint $table): void {
            $table->id();

            $table->string('name', 120);
            $table->string('slug', 120)->unique();

            $table->unsignedInteger('multiplier_bps')->default(10000);

            // Null = all games.
            $table->string('game_scope', 40)->nullable();

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            $table->boolean('active')->default(true);

            // Nullable tier gate, so a campaign can target one grade without a
            // second table.
            $table->string('tier_scope', 32)->nullable();

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('description', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['active', 'starts_at', 'ends_at'], 'points_multiplier_active_window_idx');
            $table->index(['game_scope', 'active'], 'points_multiplier_game_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('points_multiplier_campaigns');
    }
};
