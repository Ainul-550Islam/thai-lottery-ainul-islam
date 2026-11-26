<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\DeviceTrustStatus;
use App\Enums\LoginEventOutcome;
use App\Enums\NotificationEventType;
use App\Events\Security\NewDeviceLoginDetected;
use App\Models\Notification;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Services\Security\LoginHistory\LoginEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class NewDeviceDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_trusted_device_is_silent_and_unknown_device_dispatches_notification_once_per_new_device(): void
    {
        $member = User::factory()->create();
        $knownFp = hash('sha256', 'known-trusted-device-001');

        TrustedDevice::query()->create([
            'device_fingerprint' => $knownFp,
            'user_id' => $member->getKey(),
            'trust_status' => DeviceTrustStatus::Trusted,
            'presentation_hash' => hash('sha256', 'pres-001'),
            'evidence_fingerprint' => hash('sha256', 'ev-001'),
            'registered_at' => Carbon::now()->subDays(5),
            'trusted_at' => Carbon::now()->subDays(5),
        ]);

        $recorder = app(LoginEventRecorder::class);

        // 1. Known TrustedDevice -> silent, is_new_device = false, links trusted_device_id
        $knownLogin = $recorder->record(
            member: $member,
            identifier: $member->email,
            outcome: LoginEventOutcome::Success,
            ip: '203.0.113.10',
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/125.0',
            explicitDeviceFingerprint: $knownFp,
        );

        $this->assertFalse($knownLogin->is_new_device);
        $this->assertNotNull($knownLogin->trusted_device_id);
        $this->assertSame(
            0,
            Notification::query()
                ->where('user_id', $member->getKey())
                ->where('event_type', NotificationEventType::NewDeviceLogin->value)
                ->count(),
        );

        // 2. First login from brand-new device -> fires NewDeviceLoginDetected AND writes 1 Notification row
        $dispatchedCount = 0;
        Event::listen(NewDeviceLoginDetected::class, static function () use (&$dispatchedCount): void {
            $dispatchedCount++;
        });

        $unknownFp = hash('sha256', 'brand-new-iphone-device-999');
        $newLogin = $recorder->record(
            member: $member,
            identifier: $member->email,
            outcome: LoginEventOutcome::Success,
            ip: '198.51.100.77',
            userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) Safari/605.1.15',
            explicitDeviceFingerprint: $unknownFp,
        );

        $this->assertTrue($newLogin->is_new_device);
        $this->assertSame(1, $dispatchedCount);
        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $member->getKey())
                ->where('event_type', NotificationEventType::NewDeviceLogin->value)
                ->count(),
        );

        // 3. Subsequent login from that SAME device -> NOT treated as new again (notification fired ONCE per new device)
        $repeatLogin = $recorder->record(
            member: $member,
            identifier: $member->email,
            outcome: LoginEventOutcome::Success,
            ip: '198.51.100.77',
            userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) Safari/605.1.15',
            explicitDeviceFingerprint: $unknownFp,
        );

        $this->assertFalse($repeatLogin->is_new_device);
        $this->assertSame(1, $dispatchedCount);
        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $member->getKey())
                ->where('event_type', NotificationEventType::NewDeviceLogin->value)
                ->count(),
        );
    }
}
