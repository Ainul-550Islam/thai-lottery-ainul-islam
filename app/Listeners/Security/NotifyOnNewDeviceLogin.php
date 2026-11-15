<?php

declare(strict_types=1);

namespace App\Listeners\Security;

use App\DTOs\Notification\NotificationMessageData;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Enums\NotificationPriority;
use App\Events\Security\NewDeviceLoginDetected;
use App\Services\Notification\NotificationDispatchService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class NotifyOnNewDeviceLogin
{
    /** @var \WeakMap<object, true>|null */
    private static ?\WeakMap $handled = null;

    public function __construct(
        private readonly NotificationDispatchService $notifications,
    ) {}

    public function handle(NewDeviceLoginDetected $event): void
    {
        self::$handled ??= new \WeakMap;
        if (isset(self::$handled[$event])) {
            return;
        }
        self::$handled[$event] = true;

        try {
            $loginEvent = $event->loginEvent;
            $message = NotificationMessageData::fromInput([
                'user_id' => (int) $event->member->getKey(),
                'event_type' => NotificationEventType::NewDeviceLogin,
                'channel' => NotificationChannel::InApp,
                'priority' => NotificationPriority::Critical,
                'locale' => 'en',
                'subject' => 'New device sign-in detected',
                'body' => sprintf(
                    'Your account was signed in from a new device (%s on %s, IP %s) at %s.',
                    (string) ($loginEvent->browser ?? 'Unknown browser'),
                    (string) ($loginEvent->os ?? 'Unknown OS'),
                    (string) ($loginEvent->maskedIp() ?? 'unknown'),
                    $loginEvent->occurred_at->toIso8601String(),
                ),
            ]);

            $this->notifications->pronounce((int) $event->member->getKey(), $message);
        } catch (Throwable $exception) {
            Log::warning('security.new_device_notification_failed', [
                'member_id' => (int) $event->member->getKey(),
                'login_event_id' => (int) $event->loginEvent->getKey(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
