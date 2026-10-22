<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationFailureReason;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provider-backed Push Notification Transport (Audit §D6.2).
 *
 * Bridges `NotificationChannel::Push` rows in `notifications` with:
 *   - Firebase Cloud Messaging (`fcm` HTTP v1 REST API)
 *   - OneSignal (`onesignal` REST API)
 *   - Local dry-run driver (`null`)
 *
 * Integrates directly with `NotificationDeliveryService` so attempt accounting,
 * retry ceilings, horizon expiry, and dead-token revocation operate identically
 * to every other notification lane on the platform.
 */
final class PushNotificationService
{
    private const ALLOWED_PLATFORMS = ['web', 'android', 'ios'];

    private const ALLOWED_PROVIDERS = ['fcm', 'onesignal'];

    public function hashToken(string $token): string
    {
        return hash('sha256', trim($token));
    }

    /**
     * Register or refresh a device/browser push token for a member.
     */
    public function registerSubscription(
        User $user,
        string $rawToken,
        string $platform = 'web',
        ?string $provider = null,
        ?string $locale = null,
    ): PushSubscription {
        $token = trim($rawToken);
        if ($token === '' || strlen($token) < 16 || strlen($token) > 4096) {
            throw new \InvalidArgumentException('Push subscription token must be between 16 and 4096 characters.');
        }

        $resolvedPlatform = in_array($platform, self::ALLOWED_PLATFORMS, true) ? $platform : 'web';
        $configuredProvider = strtolower((string) ($provider ?? config('conversion_tooling.push.provider', 'fcm')));
        $resolvedProvider = in_array($configuredProvider, self::ALLOWED_PROVIDERS, true) ? $configuredProvider : 'fcm';
        $resolvedLocale = substr(strtolower(trim((string) ($locale ?? app()->getLocale()))), 0, 8) ?: 'en';

        $hash = $this->hashToken($token);
        $now = Carbon::now();

        /** @var PushSubscription|null $existing */
        $existing = PushSubscription::query()->where('token_hash', $hash)->first();

        if ($existing instanceof PushSubscription) {
            $existing->fill([
                'user_id' => (int) $user->getKey(),
                'provider' => $resolvedProvider,
                'token' => $token,
                'platform' => $resolvedPlatform,
                'locale' => $resolvedLocale,
                'is_active' => true,
                'last_seen_at' => $now,
                'revoked_at' => null,
            ]);
            $existing->save();

            return $existing;
        }

        return PushSubscription::query()->create([
            'user_id' => (int) $user->getKey(),
            'provider' => $resolvedProvider,
            'token_hash' => $hash,
            'token' => $token,
            'platform' => $resolvedPlatform,
            'locale' => $resolvedLocale,
            'is_active' => true,
            'last_seen_at' => $now,
            'revoked_at' => null,
        ]);
    }

    /**
     * Revoke a device push token owned by the given member.
     */
    public function revokeSubscription(User $user, string $rawToken): bool
    {
        $hash = $this->hashToken($rawToken);

        /** @var PushSubscription|null $sub */
        $sub = PushSubscription::query()
            ->where('user_id', $user->getKey())
            ->where('token_hash', $hash)
            ->first();

        if (! $sub instanceof PushSubscription) {
            return false;
        }

        $sub->is_active = false;
        $sub->revoked_at = Carbon::now();
        $sub->save();

        return true;
    }

    /**
     * @return Collection<int, PushSubscription>
     */
    public function activeSubscriptionsFor(User|int $user): Collection
    {
        $userId = $user instanceof User ? (int) $user->getKey() : (int) $user;

        return PushSubscription::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Deliver a Push `Notification` row and record the terminal/retryable outcome
     * on `NotificationDeliveryService`.
     *
     * @return array{delivered: int, failed: int, revoked: int, provider_reference: ?string}
     */
    public function deliverNotification(
        Notification $notification,
        NotificationDeliveryService $delivery,
    ): array {
        $summary = [
            'delivered' => 0,
            'failed' => 0,
            'revoked' => 0,
            'provider_reference' => null,
        ];

        if ($notification->channel !== NotificationChannel::Push) {
            return $summary;
        }

        $subscriptions = $this->activeSubscriptionsFor((int) $notification->user_id);

        if ($subscriptions->isEmpty()) {
            $delivery->seatFailed($notification, NotificationFailureReason::InvalidDestination);

            return $summary;
        }

        $driver = strtolower((string) config('conversion_tooling.push.provider', 'null'));
        $throttled = false;

        foreach ($subscriptions as $subscription) {
            $outcome = $this->dispatchSingle($driver, $subscription, $notification);

            if ($outcome['ok']) {
                $summary['delivered']++;
                $summary['provider_reference'] ??= $outcome['reference'];
                $subscription->last_seen_at = Carbon::now();
                $subscription->save();

                continue;
            }

            $summary['failed']++;

            if ($outcome['reason'] === NotificationFailureReason::InvalidDestination) {
                $subscription->is_active = false;
                $subscription->revoked_at = Carbon::now();
                $subscription->save();
                $summary['revoked']++;
            } elseif ($outcome['reason'] === NotificationFailureReason::Throttled) {
                $throttled = true;
            }
        }

        if ($summary['delivered'] > 0) {
            $delivery->seatDelivered($notification, $summary['provider_reference']);

            return $summary;
        }

        if ($summary['revoked'] === $subscriptions->count()) {
            $delivery->seatFailed($notification, NotificationFailureReason::InvalidDestination);

            return $summary;
        }

        $delivery->seatFailed(
            $notification,
            $throttled ? NotificationFailureReason::Throttled : NotificationFailureReason::ProviderFailure,
        );

        return $summary;
    }

    /**
     * @return array{ok: bool, reference: ?string, reason: ?NotificationFailureReason}
     */
    private function dispatchSingle(
        string $driver,
        PushSubscription $subscription,
        Notification $notification,
    ): array {
        $title = trim((string) ($notification->subject ?? config('app.name', 'Thai Lottery')));
        $body = trim((string) ($notification->body ?? ''));
        $timeout = max(1, (int) config('conversion_tooling.push.timeout_seconds', 4));

        if ($driver === 'null') {
            return [
                'ok' => true,
                'reference' => 'dryrun:'.$subscription->token_hash,
                'reason' => null,
            ];
        }

        if ($driver === 'fcm') {
            return $this->sendViaFcm($subscription, $title, $body, $notification, $timeout);
        }

        if ($driver === 'onesignal') {
            return $this->sendViaOneSignal($subscription, $title, $body, $notification, $timeout);
        }

        Log::error('push.unsupported_provider', ['provider' => $driver]);

        return [
            'ok' => false,
            'reference' => null,
            'reason' => NotificationFailureReason::ProviderFailure,
        ];
    }

    /**
     * @return array{ok: bool, reference: ?string, reason: ?NotificationFailureReason}
     */
    private function sendViaFcm(
        PushSubscription $subscription,
        string $title,
        string $body,
        Notification $notification,
        int $timeout,
    ): array {
        $projectId = trim((string) config('conversion_tooling.push.fcm.project_id', ''));
        $accessToken = trim((string) config('conversion_tooling.push.fcm.access_token', ''));
        $base = rtrim((string) config('conversion_tooling.push.fcm.endpoint_base', 'https://fcm.googleapis.com/v1/projects'), '/');

        if ($projectId === '' || $accessToken === '') {
            Log::warning('push.fcm_unconfigured');

            return ['ok' => false, 'reference' => null, 'reason' => NotificationFailureReason::ProviderFailure];
        }

        $url = sprintf('%s/%s/messages:send', $base, rawurlencode($projectId));

        try {
            $response = Http::timeout($timeout)
                ->withToken($accessToken)
                ->acceptJson()
                ->post($url, [
                    'message' => [
                        'token' => $subscription->token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body,
                        ],
                        'data' => [
                            'notification_id' => (string) $notification->getKey(),
                            'event_type' => $notification->event_type->value ?? (string) $notification->event_type,
                        ],
                    ],
                ]);

            if ($response->successful()) {
                $payload = $response->json();

                return [
                    'ok' => true,
                    'reference' => is_array($payload) && isset($payload['name']) ? (string) $payload['name'] : 'fcm:ok',
                    'reason' => null,
                ];
            }

            $status = $response->status();
            if ($status === 404 || $status === 410) {
                return ['ok' => false, 'reference' => null, 'reason' => NotificationFailureReason::InvalidDestination];
            }
            if ($status === 429) {
                return ['ok' => false, 'reference' => null, 'reason' => NotificationFailureReason::Throttled];
            }

            return ['ok' => false, 'reference' => null, 'reason' => NotificationFailureReason::ProviderFailure];
        } catch (Throwable $e) {
            Log::warning('push.fcm_exception', ['message' => $e->getMessage()]);

            return ['ok' => false, 'reference' => null, 'reason' => NotificationFailureReason::ProviderFailure];
        }
    }

    /**
     * @return array{ok: bool, reference: ?string, reason: ?NotificationFailureReason}
     */
    private function sendViaOneSignal(
        PushSubscription $subscription,
        string $title,
        string $body,
        Notification $notification,
        int $timeout,
    ): array {
        $appId = trim((string) config('conversion_tooling.push.onesignal.app_id', ''));
        $apiKey = trim((string) config('conversion_tooling.push.onesignal.rest_api_key', ''));
        $endpoint = trim((string) config('conversion_tooling.push.onesignal.endpoint', 'https://api.onesignal.com/notifications'));

        if ($appId === '' || $apiKey === '') {
            Log::warning('push.onesignal_unconfigured');

            return ['ok' => false, 'reference' => null, 'reason' => NotificationFailureReason::ProviderFailure];
        }

        try {
            $response = Http::timeout($timeout)
                ->withHeaders(['Authorization' => 'Key '.$apiKey])
                ->acceptJson()
                ->post($endpoint, [
                    'app_id' => $appId,
                    'target_channel' => 'push',
                    'include_subscription_ids' => [$subscription->token],
                    'headings' => ['en' => $title],
                    'contents' => ['en' => $body],
                    'data' => [
                        'notification_id' => (string) $notification->getKey(),
                    ],
                ]);

            if ($response->successful()) {
                $payload = $response->json();
                $errors = is_array($payload) ? ($payload['errors'] ?? []) : [];

                if (is_array($errors) && isset($errors['invalid_player_ids'])) {
                    return ['ok' => false, 'reference' => null, 'reason' => NotificationFailureReason::InvalidDestination];
                }

                return [
                    'ok' => true,
                    'reference' => is_array($payload) && isset($payload['id']) && $payload['id'] !== ''
                        ? 'onesignal:'.(string) $payload['id']
                        : 'onesignal:ok',
                    'reason' => null,
                ];
            }

            $status = $response->status();
            if ($status === 404 || $status === 410) {
                return ['ok' => false, 'reference' => null, 'reason' => NotificationFailureReason::InvalidDestination];
            }
            if ($status === 429) {
                return ['ok' => false, 'reference' => null, 'reason' => NotificationFailureReason::Throttled];
            }

            return ['ok' => false, 'reference' => null, 'reason' => NotificationFailureReason::ProviderFailure];
        } catch (Throwable $e) {
            Log::warning('push.onesignal_exception', ['message' => $e->getMessage()]);

            return ['ok' => false, 'reference' => null, 'reason' => NotificationFailureReason::ProviderFailure];
        }
    }
}
