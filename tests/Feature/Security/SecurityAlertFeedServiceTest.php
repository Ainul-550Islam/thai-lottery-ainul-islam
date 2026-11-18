<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Security\SecurityAlertFeedService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * The member-facing security feed: owner-scoped, bounded, and masked.
 *
 * The raw security_events table is the operator's record - it carries IP addresses,
 * user agents and internal risk scores. A member sees a projection of it with the
 * network masked, and a feed that leaked an internal risk score would tell an attacker
 * exactly which signal to stay under.
 */
final class SecurityAlertFeedServiceTest extends TestCase
{
    use DatabaseTruncation;

    private SecurityAlertFeedService $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feed = app(SecurityAlertFeedService::class);
    }

    public function test_an_empty_feed_is_an_empty_list(): void
    {
        $user = User::factory()->create();

        $this->assertSame([], $this->feed->alerts($user));
    }

    public function test_the_feed_is_scoped_to_the_member(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        $this->makeEvent($mine, 'login_failed');
        $this->makeEvent($theirs, 'login_failed');

        $alerts = $this->feed->alerts($mine);

        $this->assertCount(1, $alerts);
    }

    public function test_the_feed_is_bounded_by_config(): void
    {
        $user = User::factory()->create();

        config(['player_experience.security.alerts_feed_limit' => 3]);

        for ($i = 0; $i < 10; $i++) {
            $this->makeEvent($user, 'login_failed');
        }

        $this->assertLessThanOrEqual(3, count($this->feed->alerts($user)));
    }

    public function test_the_ip_address_is_masked(): void
    {
        $user = User::factory()->create();
        $this->makeEvent($user, 'login_failed', '203.0.113.42');

        $alerts = $this->feed->alerts($user);

        $this->assertNotEmpty($alerts);

        foreach ($alerts as $alert) {
            $hint = (string) ($alert['ip_hint'] ?? '');

            $this->assertNotSame('203.0.113.42', $hint, 'The full address must not reach the member payload.');
            // The mask keeps the network and drops the host: '203.0.113.x'. A member
            // recognises "that was me, on my usual connection" without the payload
            // telling anyone else which machine it was.
            $this->assertStringContainsString('203.0.113', $hint);
            $this->assertStringNotContainsString('42', $hint);
        }
    }

    public function test_the_payload_carries_a_stable_kind_and_nothing_internal(): void
    {
        $user = User::factory()->create();
        $this->makeEvent($user, 'login_failed');

        $alerts = $this->feed->alerts($user);

        $this->assertNotEmpty($alerts);

        foreach ($alerts as $alert) {
            $this->assertArrayHasKey('kind', $alert);
            $this->assertArrayHasKey('event_type', $alert);
            $this->assertArrayHasKey('occurred_at', $alert);
            // Internal fields must not be forwarded.
            $this->assertArrayNotHasKey('user_agent', $alert);
            $this->assertArrayNotHasKey('payload', $alert);
            $this->assertArrayNotHasKey('device_fingerprint', $alert);
        }
    }

    public function test_reading_the_feed_writes_nothing(): void
    {
        $user = User::factory()->create();
        $this->makeEvent($user, 'login_failed');

        $before = SecurityEvent::query()->count();

        $this->feed->alerts($user);
        $this->feed->alerts($user);

        $this->assertSame($before, SecurityEvent::query()->count());
    }

    public function test_the_feed_never_returns_a_synthetic_entry_when_empty(): void
    {
        // "No alerts" must render as an empty list. A fabricated "all good" row is a
        // claim about the platform that the data does not support.
        $user = User::factory()->create();

        $this->assertSame([], $this->feed->alerts($user));
    }

    private function makeEvent(User $user, string $type, string $ip = '198.51.100.7'): SecurityEvent
    {
        // Computed from the METHOD ARGUMENT, before the model casts event_type to its
        // enum: once the attribute is an enum, concatenating it throws.
        $fingerprint = hash('sha256', $type.'|'.$user->id.'|'.$ip.'|'.uniqid('', true));
        // event_fingerprint is UNIQUE and NOT NULL: it is the de-duplication handle
        // the event recorder uses so the same incident seen twice is one row. A
        // fixture has to supply one for the same reason the recorder does.
        return SecurityEvent::query()->create([
            // event_type is cast to App\Enums\SecurityEventType on the model, so the
            // fixture hands the raw column value through, not the enum.
            'event_type' => $type,
            'event_fingerprint' => $fingerprint,
            'user_id' => $user->id,
            'ip_address' => $ip,
            'risk_level' => 'medium',
            'occurred_at' => now(),
        ]);
    }
}
