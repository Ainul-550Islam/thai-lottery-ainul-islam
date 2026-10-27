<?php

declare(strict_types=1);

namespace Tests\Feature\Glo;

use App\Enums\UserStatus;
use App\Enums\DrawStatus;
use App\Enums\NotificationEventType;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\GloNotificationDelivery;
use App\Models\GloSavedTicket;
use App\Models\GloTicket;
use App\Models\GloTicketFreeze;
use App\Enums\GloFreezeStatus;
use App\Enums\NotificationChannel;
use App\DTOs\Notification\NotificationPreferenceData;
use App\Services\Notification\NotificationPreferenceService;
use App\Models\Notification;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Lottery\GloResultNotificationService;
use App\Services\Lottery\GloSavedTicketService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * GLO-17 result notifications: verified result → saved tickets → idempotent notify.
 * Push provider NOT_CONFIGURED is reported honestly; in-app/database still works.
 */
class GloResultNotificationTest extends TestCase
{
    use DatabaseTruncation;

    private User $user;

    private Draw $draw;

    private GloSavedTicketService $saved;

    private GloResultNotificationService $notify;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->saved = app(GloSavedTicketService::class);
        $this->notify = app(GloResultNotificationService::class);
        $this->user = $this->freshUser()->create();

        $this->draw = Draw::factory()->create([
            'status' => DrawStatus::ResultPublished,
            'scheduled_at' => now()->subDays(6),
            'result_published_at' => now()->subDays(5),
        ]);

        DrawResult::create([
            'draw_id' => $this->draw->getKey(),
            'first_prize' => '042042',
            'second_prize' => ['112233'],
            'third_prize' => ['600001'],
            'published_at' => now()->subDays(5),
            'metadata' => [
                'glo' => [
                    'import_fingerprint' => 'fp-test-result-v1',
                    'import_provider' => 'fixture',
                ],
            ],
        ]);
    }

    private function plantSavedOnResultDraw(string $sixDigit): GloSavedTicket
    {
        // Ticket for the already-published draw (simulating it was saved pre-draw).
        $ticket = Ticket::create([
            'ticket_number' => 'TKP-'.$sixDigit,
            'user_id' => $this->user->getKey(),
            'draw_id' => $this->draw->getKey(),
            'currency' => 'THB',
            'metadata' => ['product' => 'l6'],
        ]);
        $ticket->status = \App\Enums\TicketStatus::Confirmed;
        $ticket->save();

        return GloSavedTicket::create([
            'user_id' => $this->user->getKey(),
            'ticket_id' => $ticket->getKey(),
            'draw_id' => $this->draw->getKey(),
            'product' => 'l6',
            'ticket_reference' => $ticket->ticket_number,
            'status' => 'active',
            'saved_at' => now()->subDays(7),
            'notification_state' => 'pending',
        ]);
    }

    private function plantSavedForUser(User $user, string $ref): GloSavedTicket
    {
        $ticket = Ticket::create([
            'ticket_number' => $ref,
            'user_id' => $user->getKey(),
            'draw_id' => $this->draw->getKey(),
            'currency' => 'THB',
            'metadata' => [],
        ]);
        $ticket->status = \App\Enums\TicketStatus::Confirmed;
        $ticket->save();

        return GloSavedTicket::create([
            'user_id' => $user->getKey(),
            'ticket_id' => $ticket->getKey(),
            'draw_id' => $this->draw->getKey(),
            'product' => 'l6',
            'ticket_reference' => $ticket->ticket_number,
            'status' => 'active',
            'saved_at' => now(),
            'notification_state' => 'pending',
        ]);
    }

    public function test_verified_result_processes_saved_tickets(): void
    {
        $saved = $this->plantSavedOnResultDraw('042042');

        $report = $this->notify->processDraw($this->draw);

        $this->assertSame(1, $report['processed']);
        $this->assertSame(1, $report['notified']);
        $this->assertSame(0, count($report['failures']));
        $this->assertSame('fp-test-result-v1', $report['result_version']);

        $saved->refresh();
        $this->assertSame('notified', $saved->notification_state);
        $this->assertNotNull($saved->notification_sent_at);

        $this->assertSame(1, GloNotificationDelivery::query()->count());
        $this->assertSame(
            1,
            Notification::query()->where('event_type', NotificationEventType::GloResultAvailable)->count(),
        );
    }

    public function test_notification_is_idempotent_on_second_run(): void
    {
        $this->plantSavedOnResultDraw('042042');

        $first = $this->notify->processDraw($this->draw);
        $second = $this->notify->processDraw($this->draw);

        $this->assertSame(1, $first['notified']);
        $this->assertSame(0, $second['notified']);
        $this->assertSame(1, $second['replayed']);
        $this->assertSame(1, GloNotificationDelivery::query()->count());
        $this->assertSame(
            1,
            Notification::query()->where('event_type', NotificationEventType::GloResultAvailable)->count(),
        );
    }

    public function test_duplicate_process_import_same_version_no_second_notification(): void
    {
        $this->plantSavedOnResultDraw('042042');
        $this->notify->processDraw($this->draw);

        // Re-import with same fingerprint → same result_version → no new notify.
        DrawResult::query()->where('draw_id', $this->draw->getKey())->update([
            'metadata' => json_encode([
                'glo' => [
                    'import_fingerprint' => 'fp-test-result-v1',
                    'import_provider' => 'fixture',
                ],
            ]),
        ]);

        $again = $this->notify->processDraw($this->draw);
        $this->assertSame(1, $again['replayed']);
        $this->assertSame(1, GloNotificationDelivery::query()->count());
    }

        public function test_multiple_saved_tickets_multiple_users(): void
    {
        $other = $this->freshUser()->create();
        $this->plantSavedForUser($this->user, 'TKP-A');
        $this->plantSavedForUser($other, 'TKP-B');

        $report = $this->notify->processDraw($this->draw);
        $this->assertSame(2, $report['processed']);
        $this->assertSame(2, $report['notified']);
        $this->assertSame(2, GloNotificationDelivery::query()->count());
    }

    public function test_no_winning_ticket_still_notifies_with_not_a_winner(): void
    {
        $saved = $this->plantSavedOnResultDraw('999999');
        $this->notify->processDraw($this->draw);

        $delivery = GloNotificationDelivery::query()->first();
        $this->assertNotNull($delivery);
        $this->assertFalse((bool) ($delivery->payload_summary['won'] ?? true));
        $this->assertSame('not_a_winner', $delivery->payload_summary['claim_status']);
    }

    public function test_process_requires_verified_result_state(): void
    {
        $open = Draw::factory()->open()->create();

        try {
            $this->notify->processDraw($open);
            $this->fail('open draw must not notify');
        } catch (\App\Exceptions\GloDealerException $e) {
            $this->assertStringContainsString('not verified', $e->getMessage());
        }
    }

    public function test_command_requires_draw_and_runs(): void
    {
        $this->artisan('glo:notify-saved-tickets')->assertExitCode(1);

        $this->plantSavedOnResultDraw('042042');
        $this->artisan('glo:notify-saved-tickets', [
            '--draw' => $this->draw->draw_number,
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->plantSavedOnResultDraw('042042');
        $report = $this->notify->processDraw($this->draw, true);

        $this->assertSame(1, $report['processed']);
        $this->assertSame(0, GloNotificationDelivery::query()->count());
    }

    public function test_push_provider_not_configured_is_honest(): void
    {
        config(['glo.notifications.default_channel' => 'push']);
        $this->plantSavedOnResultDraw('042042');
        $this->notify->processDraw($this->draw);

        $delivery = GloNotificationDelivery::query()->first();
        $this->assertNotNull($delivery);
        $this->assertSame('not_configured', $delivery->provider);
        $this->assertSame('not_configured', $delivery->delivery_state);
    }

    public function test_in_app_delivery_uses_database_provider(): void
    {
        $this->plantSavedOnResultDraw('042042');
        $this->notify->processDraw($this->draw);

        $delivery = GloNotificationDelivery::query()->first();
        $this->assertSame('database', $delivery->provider);
        $this->assertSame('queued', $delivery->delivery_state);
    }

    public function test_two_workers_same_result_only_one_delivery(): void
    {
        $this->plantSavedOnResultDraw('042042');
        $version = $this->notify->resultVersion((int) $this->draw->getKey());
        $saved = GloSavedTicket::query()->firstOrFail();

        $a = $this->notify->notifyOne($saved, $version);
        $b = $this->notify->notifyOne($saved->refresh(), $version);

        $this->assertSame('notified', $a);
        $this->assertSame('replayed', $b);
        $this->assertSame(1, GloNotificationDelivery::query()->count());
    }

    public function test_payload_has_no_other_user_pii(): void
    {
        $saved = $this->plantSavedOnResultDraw('042042');
        $payload = $this->notify->buildPayload($saved);

        $encoded = json_encode($payload);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('@example.com', $encoded);
        $this->assertArrayNotHasKey('user_id', $payload);
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('legal_evidence', $payload);
    }

    private function freshUser(): \Illuminate\Database\Eloquent\Factories\Factory
    {
        return User::factory()->state(fn (): array => [
            'email' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(12)).'.'.bin2hex(random_bytes(4)).'@glofreeze.local',
            'username' => 'gz'.\Illuminate\Support\Str::random(10),
            'phone' => '+8801'.random_int(100_000_000, 999_999_999),
            'status' => UserStatus::Active,
        ]);
    }

    public function test_frozen_winning_ticket_notification_carries_hold_without_legal_detail(): void
    {
        $saved = $this->plantSavedOnResultDraw('042042');

        // GLO ticket identity + active freeze (public-safe hold flag only).
        $gloTicket = GloTicket::create([
            'draw_id' => $this->draw->getKey(),
            'product' => 'l6',
            'ticket_number' => '042042',
            'ticket_reference' => 'GLOF-'.$this->draw->getKey().'-l6-042042',
            'set_series' => 'A',
            'owner_user_id' => $this->user->getKey(),
        ]);
        GloTicketFreeze::create([
            'freeze_case_id' => 'CASE-NOTIFY-HOLD-'.uniqid(),
            'ticket_id' => $gloTicket->getKey(),
            'draw_id' => $this->draw->getKey(),
            'product' => 'l6',
            'ticket_number' => '042042',
            'set_series' => 'A',
            'requesting_authority' => 'Royal Thai Police',
            'jurisdiction' => 'Bangkok',
            'case_reference' => 'CASE-NOTIFY-HOLD',
            'evidence_reference' => 'EVD-NOTIFY-HOLD',
            'evidence_type' => 'official_notice',
            'status' => GloFreezeStatus::Frozen,
            'review_status' => 'approved',
            'requested_at' => now(),
            'effective_at' => now(),
            'fingerprint' => hash('sha256', 'notify-hold-fixture'),
            'requested_by' => $this->user->getKey(),
        ]);

        $outcome = $this->notify->processDraw($this->draw);
        $this->assertSame(1, $outcome['notified']);

        $delivery = GloNotificationDelivery::query()->firstOrFail();
        $payload = $delivery->payload_summary;
        $this->assertTrue((bool) $payload['payment_hold']);
        $this->assertNotNull($payload['hold_notice']);
        $this->assertStringContainsString('on hold', (string) $payload['hold_notice']);
        // No private legal detail in the public-safe payload.
        $this->assertStringNotContainsString('police', strtolower((string) json_encode($payload)));
        $this->assertStringNotContainsString('CASE-NOTIFY-HOLD', (string) json_encode($payload));
    }

    public function test_send_failure_marks_delivery_failed_when_preference_disables_event(): void
    {
        $saved = $this->plantSavedOnResultDraw('042042');

        app(NotificationPreferenceService::class)->write(NotificationPreferenceData::fromInput([
            'user_id' => $this->user->getKey(),
            'event_type' => NotificationEventType::GloResultAvailable,
            'channel' => NotificationChannel::InApp,
            'enabled' => false,
        ]));

        $outcome = $this->notify->processDraw($this->draw);
        $this->assertSame(0, $outcome['notified']);
        $this->assertSame(1, $outcome['processed']);

        $delivery = GloNotificationDelivery::query()->firstOrFail();
        $this->assertSame('failed', $delivery->delivery_state);
        $this->assertSame(0, Notification::query()->where('event_type', NotificationEventType::GloResultAvailable)->count());
    }

    public function test_retry_after_failure_advances_same_delivery_key_without_duplicate_rows(): void
    {
        $saved = $this->plantSavedOnResultDraw('042042');
        $prefs = app(NotificationPreferenceService::class);

        // First pass: preference off → failed delivery.
        $prefs->write(NotificationPreferenceData::fromInput([
            'user_id' => $this->user->getKey(),
            'event_type' => NotificationEventType::GloResultAvailable,
            'channel' => NotificationChannel::InApp,
            'enabled' => false,
        ]));
        $this->notify->processDraw($this->draw);

        $delivery = GloNotificationDelivery::query()->firstOrFail();
        $this->assertSame('failed', $delivery->delivery_state);
        $key = $delivery->delivery_key;

        // User re-enables → second worker pass retries the failed row.
        $prefs->write(NotificationPreferenceData::fromInput([
            'user_id' => $this->user->getKey(),
            'event_type' => NotificationEventType::GloResultAvailable,
            'channel' => NotificationChannel::InApp,
            'enabled' => true,
        ]));
        $outcome = $this->notify->processDraw($this->draw);

        $this->assertSame(1, $outcome['notified']);
        $this->assertSame(1, GloNotificationDelivery::query()->count());
        $delivery->refresh();
        $this->assertSame($key, $delivery->delivery_key);
        $this->assertSame('queued', $delivery->delivery_state);
        $this->assertSame(1, Notification::query()->where('event_type', NotificationEventType::GloResultAvailable)->count());

        // Third pass: settled → replayed, still one row / one notification.
        $again = $this->notify->processDraw($this->draw);
        $this->assertSame(0, $again['notified']);
        $this->assertSame(1, $again['replayed']);
        $this->assertSame(1, GloNotificationDelivery::query()->count());
    }
}
