<?php

declare(strict_types=1);

namespace Tests\Feature\Player;

use App\DTOs\Notification\NotificationPreferenceData;
use App\Enums\AuditAction;
use App\Exceptions\NotificationPreferenceException;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Models\AuditLog;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Notification\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/v1/player/settings/notifications — the player's own preference
 * matrix.
 *
 * The contract this suite pins:
 *  - a preference round-trips: what a player saves is what the canonical
 *    service (and therefore the dispatcher) reads back;
 *  - the vocabulary is the closed event × channel matrix: an unknown
 *    combination is a 422, never a new row;
 *  - the mandatory floor holds: security, compliance and withdrawal-terminal
 *    notices cannot be silenced, and a refused request writes nothing at all;
 *  - one player's write never touches another player's rows;
 *  - an unauthenticated caller gets the same 401 the controller already
 *    answered (this endpoint was never public).
 */
final class PlayerNotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    private User $player;

    protected function setUp(): void
    {
        parent::setUp();

        $this->player = User::factory()->create();
    }

    private function act(): void
    {
        Sanctum::actingAs($this->player);
    }

    /**
     * @param  array<int, array<string, mixed>>  $preferences
     * @return array<string, mixed>
     */
    private function payload(array $preferences): array
    {
        return ['preferences' => $preferences];
    }

    public function test_a_saved_preference_round_trips_through_the_canonical_service(): void
    {
        $this->act();

        $response = $this->postJson('/api/v1/player/settings/notifications', $this->payload([
            [
                'event_type' => NotificationEventType::BetWon->value,
                'channel' => NotificationChannel::Email->value,
                'enabled' => false,
            ],
            [
                'event_type' => NotificationEventType::DepositConfirmed->value,
                'channel' => NotificationChannel::Sms->value,
                'enabled' => true,
                'quiet_hours' => ['start' => '22:00', 'end' => '07:00'],
            ],
        ]))->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('data.preferences.0.event_type', NotificationEventType::BetWon->value)
            ->assertJsonPath('data.preferences.0.enabled', false);

        $service = app(NotificationPreferenceService::class);

        $this->assertFalse($service->enabledFor(
            (int) $this->player->id,
            NotificationEventType::BetWon,
            NotificationChannel::Email,
        ));

        $this->assertTrue($service->enabledFor(
            (int) $this->player->id,
            NotificationEventType::DepositConfirmed,
            NotificationChannel::Sms,
        ));

        // JSON objects have no key-order guarantee: MySQL's native JSON may
        // return these keys in a different order than SQLite. Require the
        // exact keys and values without making their serialization order a rule.
        $quietHours = $service->quietHoursFor(
            (int) $this->player->id,
            NotificationEventType::DepositConfirmed,
            NotificationChannel::Sms,
        );
        $this->assertIsArray($quietHours);
        $this->assertCount(2, $quietHours);
        $this->assertArrayHasKey('start', $quietHours);
        $this->assertArrayHasKey('end', $quietHours);
        $this->assertSame('22:00', $quietHours['start']);
        $this->assertSame('07:00', $quietHours['end']);

        $this->assertSame(2, NotificationPreference::query()->where('user_id', $this->player->id)->count());
    }

    public function test_service_rolls_back_every_write_when_a_later_row_belongs_to_another_player(): void
    {
        $other = User::factory()->create();
        $first = NotificationPreferenceData::fromInput([
            'user_id' => (int) $this->player->id, 'event_type' => NotificationEventType::BetWon,
            'channel' => NotificationChannel::Email, 'enabled' => false,
        ]);
        $second = NotificationPreferenceData::fromInput([
            'user_id' => (int) $other->id, 'event_type' => NotificationEventType::BetPlaced,
            'channel' => NotificationChannel::Email, 'enabled' => false,
        ]);
        try {
            app(NotificationPreferenceService::class)->writeManyFor((int) $this->player->id, [$first, $second]);
            self::fail('A mixed-owner matrix was accepted.');
        } catch (NotificationPreferenceException $exception) {
            self::assertSame(NotificationPreferenceException::MALFORMED, $exception->errorCode());
        }
        self::assertSame(0, NotificationPreference::query()->count());
    }

    public function test_writing_the_same_combination_twice_updates_rather_than_duplicates(): void
    {
        $this->act();

        $preference = [
            'event_type' => NotificationEventType::GloResultAvailable->value,
            'channel' => NotificationChannel::Push->value,
            'enabled' => true,
        ];

        $this->postJson('/api/v1/player/settings/notifications', $this->payload([$preference]))->assertOk();
        $this->postJson('/api/v1/player/settings/notifications', $this->payload([
            [...$preference, 'enabled' => false],
        ]))->assertOk();

        $this->assertSame(1, NotificationPreference::query()->where('user_id', $this->player->id)->count());
        $this->assertFalse(app(NotificationPreferenceService::class)->enabledFor(
            (int) $this->player->id,
            NotificationEventType::GloResultAvailable,
            NotificationChannel::Push,
        ));
    }

    public function test_an_unknown_event_type_or_channel_is_rejected(): void
    {
        $this->act();

        $this->postJson('/api/v1/player/settings/notifications', $this->payload([[
            'event_type' => 'definitely_not_an_event',
            'channel' => NotificationChannel::Email->value,
            'enabled' => true,
        ]]))->assertStatus(422);

        $this->postJson('/api/v1/player/settings/notifications', $this->payload([[
            'event_type' => NotificationEventType::BetWon->value,
            'channel' => NotificationChannel::None->value,
            'enabled' => true,
        ]]))->assertStatus(422);

        $this->postJson('/api/v1/player/settings/notifications', $this->payload([[
            'event_type' => NotificationEventType::BetWon->value,
            'channel' => NotificationChannel::Email->value,
            'enabled' => true,
            'quiet_hours' => ['start' => '25:00', 'end' => '07:00'],
        ]]))->assertStatus(422);

        $this->assertSame(0, NotificationPreference::query()->count());
    }

    public function test_a_mandatory_notification_cannot_be_disabled(): void
    {
        $this->act();

        $response = $this->postJson('/api/v1/player/settings/notifications', $this->payload([[
            'event_type' => NotificationEventType::ComplianceHold->value,
            'channel' => NotificationChannel::Email->value,
            'enabled' => false,
        ]]));

        $response->assertStatus(422);

        // The project's validation envelope nests the field errors under
        // error.details.fields; assert the reason is attached to the field the
        // player actually set.
        $fields = (array) ($response->json('error.details.fields') ?? []);
        $this->assertArrayHasKey('preferences.0.enabled', $fields);

        $this->assertSame(0, NotificationPreference::query()->count());
    }

    public function test_withdrawal_terminal_states_cannot_be_disabled(): void
    {
        $this->act();

        foreach ([NotificationEventType::WithdrawalPaid, NotificationEventType::WithdrawalRejected] as $eventType) {
            $this->postJson('/api/v1/player/settings/notifications', $this->payload([[
                'event_type' => $eventType->value,
                'channel' => NotificationChannel::Sms->value,
                'enabled' => false,
            ]]))->assertStatus(422);
        }

        $this->assertSame(0, NotificationPreference::query()->count());
    }

    public function test_one_refused_row_prevents_the_whole_write(): void
    {
        $this->act();

        // The valid row first, the mandatory-silencing row second: nothing may be
        // persisted, because a half-applied matrix is worse than none.
        $this->postJson('/api/v1/player/settings/notifications', $this->payload([
            [
                'event_type' => NotificationEventType::BetPlaced->value,
                'channel' => NotificationChannel::Email->value,
                'enabled' => false,
            ],
            [
                'event_type' => NotificationEventType::SessionRevoked->value,
                'channel' => NotificationChannel::Email->value,
                'enabled' => false,
            ],
        ]))->assertStatus(422);

        $this->assertSame(0, NotificationPreference::query()->count());
    }

    public function test_a_mandatory_notification_may_still_be_explicitly_enabled(): void
    {
        $this->act();

        $this->postJson('/api/v1/player/settings/notifications', $this->payload([[
            'event_type' => NotificationEventType::ComplianceHold->value,
            'channel' => NotificationChannel::Email->value,
            'enabled' => true,
        ]]))->assertOk()->assertJsonPath('data.preferences.0.mandatory', true);
    }

    public function test_integer_flags_mean_the_same_as_booleans(): void
    {
        $this->act();

        // 1 keeps a mandatory notice on (rather than being read as "off" and
        // refused), and 0 genuinely switches a preference off.
        $this->postJson('/api/v1/player/settings/notifications', $this->payload([
            [
                'event_type' => NotificationEventType::RealityCheck->value,
                'channel' => NotificationChannel::Email->value,
                'enabled' => 1,
            ],
            [
                'event_type' => NotificationEventType::BetPlaced->value,
                'channel' => NotificationChannel::Push->value,
                'enabled' => 0,
            ],
        ]))->assertOk();

        $this->assertTrue(app(NotificationPreferenceService::class)->enabledFor(
            (int) $this->player->id,
            NotificationEventType::RealityCheck,
            NotificationChannel::Email,
        ));

        $this->assertFalse(app(NotificationPreferenceService::class)->enabledFor(
            (int) $this->player->id,
            NotificationEventType::BetPlaced,
            NotificationChannel::Push,
        ));
    }

    public function test_another_players_preferences_are_untouched(): void
    {
        $other = User::factory()->create();

        $this->act();

        $this->postJson('/api/v1/player/settings/notifications', $this->payload([[
            'event_type' => NotificationEventType::BetWon->value,
            'channel' => NotificationChannel::Email->value,
            'enabled' => false,
        ]]))->assertOk();

        // The other player is not even a parameter of the request; the write is
        // scoped by the authenticated identity alone.
        $this->assertSame(0, NotificationPreference::query()->where('user_id', $other->id)->count());
        $this->assertTrue(app(NotificationPreferenceService::class)->enabledFor(
            (int) $other->id,
            NotificationEventType::BetWon,
            NotificationChannel::Email,
        ));
    }

    public function test_an_unauthenticated_caller_is_refused_and_writes_nothing(): void
    {
        $response = $this->postJson('/api/v1/player/settings/notifications', $this->payload([[
            'event_type' => NotificationEventType::BetWon->value,
            'channel' => NotificationChannel::Email->value,
            'enabled' => false,
        ]]));

        $this->assertContains($response->getStatusCode(), [401, 403]);
        $this->assertSame(0, NotificationPreference::query()->count());
    }

    public function test_the_write_is_audited_without_recording_quiet_hours(): void
    {
        $this->act();

        $this->postJson('/api/v1/player/settings/notifications', $this->payload([[
            'event_type' => NotificationEventType::BetWon->value,
            'channel' => NotificationChannel::Email->value,
            'enabled' => false,
            'quiet_hours' => ['start' => '22:00', 'end' => '07:00'],
        ]]))->assertOk();

        $audit = AuditLog::query()
            ->where('user_id', $this->player->id)
            ->where('action', AuditAction::Update->value)
            ->latest('id')
            ->first();

        $this->assertNotNull($audit, 'Saving preferences must leave an audit row.');
        $this->assertSame(1, (int) ($audit->metadata['count'] ?? 0));
        $this->assertTrue((bool) ($audit->metadata['preferences'][0]['has_quiet_hours'] ?? false));
        // The window itself is player behaviour, not an audit fact.
        $this->assertArrayNotHasKey('quiet_hours', (array) $audit->metadata['preferences'][0]);
    }

    public function test_an_empty_matrix_is_rejected(): void
    {
        $this->act();

        $this->postJson('/api/v1/player/settings/notifications', ['preferences' => []])
            ->assertStatus(422);
    }

    public function test_the_other_settings_lanes_are_unchanged(): void
    {
        $this->act();

        // These lanes stay fail-closed; this batch changed only the
        // notification-preferences method.
        $this->postJson('/api/v1/player/settings/security')->assertStatus(503)->assertJsonPath('status', 'NOT_CONFIGURED');
        $this->postJson('/api/v1/player/settings/2fa')->assertStatus(503)->assertJsonPath('status', 'NOT_CONFIGURED');
        $this->postJson('/api/v1/player/settings/betting')->assertStatus(503)->assertJsonPath('status', 'NOT_CONFIGURED');
        $this->postJson('/api/v1/player/settings/line-notify')->assertStatus(503)->assertJsonPath('status', 'NOT_CONFIGURED');
    }
}
