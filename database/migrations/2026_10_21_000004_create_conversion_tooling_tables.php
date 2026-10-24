<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('promo_coupons')) {
            Schema::create('promo_coupons', function (Blueprint $table): void {
                $table->id();
                $table->string('code', 32)->unique();
                $table->string('title', 160);
                $table->string('description', 500)->nullable();
                $table->string('discount_type', 24)->default('fixed'); // fixed | percentage_match
                $table->decimal('amount', 20, 2);
                $table->decimal('max_bonus_amount', 20, 2)->nullable();
                $table->string('currency', 3)->default('THB');
                $table->string('target_wallet_type', 24)->default('bonus');
                $table->decimal('min_deposit_total', 20, 2)->default(0);
                $table->unsignedInteger('max_redemptions')->nullable();
                $table->unsignedInteger('redemptions_count')->default(0);
                $table->unsignedSmallInteger('per_user_limit')->default(1);
                $table->boolean('first_deposit_only')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
    
                $table->index(['is_active', 'expires_at']);
            });
        }

        if (! Schema::hasTable('promo_coupon_redemptions')) {
            Schema::create('promo_coupon_redemptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('coupon_id')->constrained('promo_coupons')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
                $table->unsignedBigInteger('financial_transaction_id')->nullable();
                $table->string('idempotency_key', 120)->unique();
                $table->decimal('granted_amount', 20, 2);
                $table->string('currency', 3)->default('THB');
                $table->string('ip_address', 64)->nullable();
                $table->timestamp('redeemed_at');
                $table->timestamps();
    
                $table->index(['coupon_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('push_subscriptions')) {
            Schema::create('push_subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('provider', 24)->default('fcm'); // fcm | onesignal
                $table->string('token_hash', 64)->unique();
                $table->text('token');
                $table->string('platform', 24)->default('web'); // web | android | ios
                $table->string('locale', 8)->default('en');
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();
    
                $table->index(['user_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('referral_link_clicks')) {
            Schema::create('referral_link_clicks', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('agent_id')->constrained('agents')->cascadeOnDelete();
                $table->string('agent_code', 32);
                $table->string('ip_hash', 64);
                $table->string('referrer_host', 190)->nullable();
                $table->string('locale', 8)->default('en');
                $table->unsignedBigInteger('converted_user_id')->nullable();
                $table->timestamp('clicked_at');
                $table->timestamps();
    
                $table->index(['agent_id', 'clicked_at']);
                $table->index(['agent_code', 'ip_hash', 'clicked_at']);
            });
        }

        if (! Schema::hasTable('loyalty_point_ledger')) {
            Schema::create('loyalty_point_ledger', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('entry_type', 24); // earn | redeem | expire | adjust
                $table->bigInteger('points');
                $table->unsignedBigInteger('balance_after');
                $table->string('source_type', 80)->nullable();
                $table->string('source_id', 80)->nullable();
                $table->string('idempotency_key', 120)->unique();
                $table->string('description', 255);
                $table->timestamp('expires_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
    
                $table->index(['user_id', 'id']);
                $table->index(['entry_type', 'expires_at']);
            });
        }

        if (! Schema::hasTable('loyalty_reward_redemptions')) {
            Schema::create('loyalty_reward_redemptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('reward_key', 64);
                $table->unsignedBigInteger('points_spent');
                $table->decimal('bonus_amount', 20, 2);
                $table->string('currency', 3)->default('THB');
                $table->unsignedBigInteger('financial_transaction_id')->nullable();
                $table->string('idempotency_key', 120)->unique();
                $table->timestamp('redeemed_at');
                $table->timestamps();
    
                $table->index(['user_id', 'redeemed_at']);
            });
        }

        if (! Schema::hasTable('member_testimonials')) {
            Schema::create('member_testimonials', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('display_name', 64);
                $table->string('locale', 8)->default('en');
                $table->string('quote', 320);
                $table->unsignedTinyInteger('rating')->default(5);
                $table->string('status', 24)->default('pending'); // pending | approved | rejected
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();
    
                $table->index(['status', 'locale', 'published_at']);
            });
        }

        if (! Schema::hasTable('experiment_exposures')) {
            Schema::create('experiment_exposures', function (Blueprint $table): void {
                $table->id();
                $table->string('experiment_key', 80);
                $table->string('subject_hash', 64);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('variant', 64);
                $table->timestamp('exposed_at');
                $table->timestamps();
    
                $table->unique(['experiment_key', 'subject_hash']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('experiment_exposures');
        Schema::dropIfExists('member_testimonials');
        Schema::dropIfExists('loyalty_reward_redemptions');
        Schema::dropIfExists('loyalty_point_ledger');
        Schema::dropIfExists('referral_link_clicks');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('promo_coupon_redemptions');
        Schema::dropIfExists('promo_coupons');
    }
};
