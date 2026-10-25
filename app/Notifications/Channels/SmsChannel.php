<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Services\Notification\SmsGatewayService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The SMS lane as a Laravel notification channel.
 *
 * ONE DOOR TO THE GATEWAY. The only thing this class calls is the existing
 * SmsGatewayService::sendSms(string, string): bool; it never builds a provider
 * request of its own, so the gateway's credentials, formatting and provider
 * stay in one place.
 *
 * FAIL-CLOSED ON THE DESTINATION. A notification reaches a phone only when the
 * notifiable has a non-empty number that has been verified (phone_verified_at),
 * or routes one explicitly through routeNotificationForSms(). An unverified
 * number is not a destination: a typo would page a stranger with a player's
 * money fact.
 *
 * FAIL-OPEN ON THE WIRE. A gateway outage never throws out of here. The
 * originating request (a withdrawal completing, a prize landing) must not fail
 * because a text message could not be sent; the outcome is returned as a bool
 * so the caller (App\Listeners\Notifications\*) records it as a delivery
 * attempt on the notification row, and the log line carries a masked number
 * only.
 */
final class SmsChannel
{
    public const SKIPPED = 'skipped';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** @var array<string, string> last outcome per notification id, for callers that go through the dispatcher */
    private static array $outcomes = [];

    public function __construct(
        private readonly SmsGatewayService $gateway,
    ) {}

    /**
     * Laravel channel entry point. Returns true only when the gateway accepted the message.
     */
    public function send(object $notifiable, Notification $notification): bool
    {
        $outcome = $this->deliver($notifiable, $notification);

        if (property_exists($notification, 'id') && is_string($notification->id)) {
            self::$outcomes[$notification->id] = $outcome;
        }

        return $outcome === self::SENT;
    }

    /**
     * The outcome of a send routed through the notification dispatcher, by notification id.
     */
    public static function outcomeFor(?string $notificationId): ?string
    {
        return $notificationId === null ? null : (self::$outcomes[$notificationId] ?? null);
    }

    public static function forgetOutcomes(): void
    {
        self::$outcomes = [];
    }

    /**
     * Send and classify: 'sent', 'failed' (gateway refused or threw) or 'skipped' (no verified number / no text).
     */
    public function deliver(object $notifiable, Notification $notification): string
    {
        $number = $this->destinationFor($notifiable);

        if ($number === null) {
            return self::SKIPPED;
        }

        if (! method_exists($notification, 'toSms')) {
            return self::SKIPPED;
        }

        try {
            $text = $notification->toSms($notifiable);
        } catch (Throwable $e) {
            Log::warning('notifications.sms.render_failed', [
                'notification' => $notification::class,
                'destination' => self::mask($number),
                'reason' => $e::class,
            ]);

            return self::FAILED;
        }

        $text = is_string($text) ? trim($text) : '';

        if ($text === '') {
            return self::SKIPPED;
        }

        try {
            $accepted = $this->gateway->sendSms($number, $text);
        } catch (Throwable $e) {
            Log::warning('notifications.sms.gateway_failed', [
                'notification' => $notification::class,
                'destination' => self::mask($number),
                'reason' => $e::class,
            ]);

            return self::FAILED;
        }

        if (! $accepted) {
            Log::warning('notifications.sms.gateway_refused', [
                'notification' => $notification::class,
                'destination' => self::mask($number),
            ]);

            return self::FAILED;
        }

        Log::info('notifications.sms.sent', [
            'notification' => $notification::class,
            'destination' => self::mask($number),
        ]);

        return self::SENT;
    }

    /**
     * The verified number this notifiable may be texted on, or null.
     */
    public function destinationFor(object $notifiable): ?string
    {
        $number = null;

        if (method_exists($notifiable, 'routeNotificationForSms')) {
            $routed = $notifiable->routeNotificationForSms();
            $number = is_string($routed) && trim($routed) !== '' ? trim($routed) : null;
        }

        if (method_exists($notifiable, 'getAttribute')) {
            if ($number === null) {
                $raw = $notifiable->getAttribute('phone');
                $number = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
            }

            // Verification is the ownership proof for a number; without it there is no destination.
            if ($notifiable->getAttribute('phone_verified_at') === null) {
                return null;
            }
        }

        if ($number === null) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', $number) ?? '';

        return strlen($digits) >= 8 ? $number : null;
    }

    /**
     * Last four digits only; everything else is replaced. Safe for logs.
     */
    public static function mask(string $number): string
    {
        $digits = preg_replace('/[^0-9]/', '', $number) ?? '';

        if (strlen($digits) <= 4) {
            return str_repeat('*', strlen($digits));
        }

        return str_repeat('*', strlen($digits) - 4).substr($digits, -4);
    }
}
