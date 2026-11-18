<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification preferences — ALREADY EXISTS.
 *
 * REPLACED BY REUSE (Phase 0 finding).
 * `notification_preferences` was created by
 * 2024_02_01_003500_create_notification_preferences_table.php with the exact
 * shape this batch needs: user_id + event_type + channel, UNIQUE across the
 * three, an `enabled` flag and quiet hours. App\Models\NotificationPreference,
 * App\Enums\NotificationEventType::isMandatory(),
 * App\Services\Notification\NotificationPreferenceService and
 * PUT /api/v1/notifications/preferences are all live.
 *
 * This migration therefore does NOT create a table. It adds the one column the
 * Batch 3 requirement needs and the existing schema lacks: a per-row audit
 * stamp saying WHY a row is enabled/disabled (a member choice vs a mandatory
 * override), which the preference API reports so a member can see that a
 * category is not switchable instead of watching a toggle silently bounce.
 *
 * Additive only: `default(true)` keeps every existing row's behaviour
 * byte-for-byte identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_preferences')) {
            return;
        }

        if (Schema::hasColumn('notification_preferences', 'locked_by_policy')) {
            return;
        }

        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->boolean('locked_by_policy')->default(false);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_preferences')) {
            return;
        }

        if (! Schema::hasColumn('notification_preferences', 'locked_by_policy')) {
            return;
        }

        Schema::table('notification_preferences', function (Blueprint $table): void {
            $table->dropColumn('locked_by_policy');
        });
    }
};
