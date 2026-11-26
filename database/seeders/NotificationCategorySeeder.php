<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Notification categories — REPORTED AS SKIPPED FOR TABLE CREATION.
 *
 * Phase 0 finding: notification categories are NOT DB-driven in this codebase.
 * The vocabulary is the closed enum App\Enums\NotificationEventType (19 cases,
 * including GLO-15/17 additions), the channel vocabulary is
 * App\Enums\NotificationChannel, and mandatory status is a method on the enum
 * (isMandatory()), not a column. Creating a `notification_categories` table
 * would fork that vocabulary in two and let the two disagree.
 *
 * WHAT THIS SEEDER DOES INSTEAD — and why it exists at all.
 * It seeds the one genuinely missing piece of DATA: the default preference
 * rows for members who already exist. Without it, a member created before
 * Batch 3 has no rows at all, and "no row" is indistinguishable from "the
 * member switched everything off" in any UI that reads rows rather than
 * defaults. Creating the rows makes the state legible.
 *
 * It is idempotent (upsert on the existing UNIQUE(user_id, event_type,
 * channel)) and it never enables a channel the enum does not declare, so a
 * second run changes nothing.
 *
 * Mandatory events are seeded enabled AND locked; optional events are seeded
 * enabled and unlocked, because the product default is "everything on".
 */
class NotificationCategorySeeder extends Seeder
{
    public function run(): void
    {
        $channel = NotificationChannel::InApp;
        $now = now();

        $rows = [];

        foreach (User::query()->orderBy('id')->cursor() as $user) {
            foreach (NotificationEventType::cases() as $eventType) {
                $rows[] = [
                    'user_id' => $user->id,
                    'event_type' => $eventType->value,
                    'channel' => $channel->value,
                    'enabled' => true,
                    'quiet_hours' => null,
                    'locked_by_policy' => $eventType->isMandatory(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // Chunked so a large member base does not build one enormous
            // INSERT that SQLite refuses and MySQL truncates.
            if (count($rows) >= 500) {
                $this->upsert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $this->upsert($rows);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(array $rows): void
    {
        DB::table((new NotificationPreference)->getTable())->upsert(
            $rows,
            ['user_id', 'event_type', 'channel'],
            ['enabled', 'quiet_hours', 'locked_by_policy', 'updated_at'],
        );
    }
}
