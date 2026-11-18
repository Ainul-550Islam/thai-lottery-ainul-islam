<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The member-facing alert feed. READ-ONLY, and it does not create events.
 *
 * WHERE THE ALERTS COME FROM
 * security_events — the table the existing security lanes (Batch 1 device trust,
 * MFA, session revocation, password change) already write. This service adds no
 * table and no write path; it maps the stored event vocabulary onto a small,
 * stable set of member-facing kinds so the UI does not have to know twenty
 * internal event names.
 *
 * THE MAPPING IS EXPLICIT AND PARTIAL
 * An event whose type is not in the map is still returned — as `other` with its
 * raw type preserved — because silently dropping an alert is worse than showing
 * an unfamiliar one. What must NOT happen is a generic "you are protected"
 * banner: an alert feed that reassures instead of informing is the failure this
 * batch is designed to prevent.
 *
 * OWNERSHIP
 * Every query is scoped by user_id. There is no unscoped read in this class.
 */
final class SecurityAlertFeedService
{
    /**
     * Member-facing kinds and the stored event types that map to them.
     *
     * @var array<string, list<string>>
     */
    private const KIND_MAP = [
        'new_device' => ['device.trusted', 'device.new', 'login.new_device', 'device_trusted'],
        'password_changed' => ['password.changed', 'password_changed', 'credential.changed'],
        'security_settings_changed' => ['security.settings.updated', 'settings.security', 'security_settings_updated'],
        'session_revoked' => ['session.revoked', 'session_revoked'],
        'mfa_changed' => ['mfa.enrolled', 'mfa.disabled', 'mfa_enabled', 'mfa_disabled'],
        'login_failed' => ['login.failed', 'login_failed', 'authentication.failed'],
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function alerts(User $user, ?int $limit = null, ?Carbon $at = null): array
    {
        $max = max(1, min($limit ?? (int) config('player_experience.security.alerts_feed_limit', 20), 100));

        try {
            $events = SecurityEvent::query()
                ->where('user_id', $user->id)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit($max)
                ->get();
        } catch (\Throwable) {
            return [];
        }

        $out = [];

        foreach ($events as $event) {
            $out[] = $this->project($event);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function project(SecurityEvent $event): array
    {
        /*
         * event_type is CAST TO AN ENUM ON THE MODEL, so `(string) $event->event_type`
         * throws "Object of class App\Enums\SecurityEventType could not be
         * converted to string" - a backed enum is not a Stringable. Reading the raw
         * column is the only form that is correct whether the cast is configured or
         * not, which matters because a future change to the enum should not silently
         * break a member-facing feed.
         */
        $rawType = $event->getRawOriginal('event_type') ?? $event->event_type;
        $type = $rawType instanceof \BackedEnum ? (string) $rawType->value : (string) $rawType;

        return [
            'id' => (int) $event->id,
            'kind' => $this->kindFor($type),
            'event_type' => $type,
            'risk_level' => $event->risk_level,
            'occurred_at' => $event->occurred_at?->toIso8601String(),
            'reviewed' => (bool) $event->reviewed,
            // The IP is truncated to the network a member would recognise; the
            // full address stays in the operator surface, where it is needed for
            // investigation and where a member cannot screenshot it by accident.
            'ip_hint' => $this->maskIp($event->ip_address),
        ];
    }

    /**
     * The stable member-facing kind, or 'other' with the raw type preserved.
     */
    public function kindFor(string $eventType): string
    {
        foreach (self::KIND_MAP as $kind => $types) {
            if (in_array($eventType, $types, true)) {
                return $kind;
            }
        }

        return 'other';
    }

    /**
     * IPv4 -> first three octets + 'x', IPv6 -> first two groups + '::x'.
     * A member recognises "the network I was on"; the last octet is not needed
     * for that and is the part that identifies a device.
     */
    private function maskIp(?string $ip): ?string
    {
        if ($ip === null || trim($ip) === '') {
            return null;
        }

        if (str_contains($ip, ':')) {
            $parts = explode(':', $ip);

            return $parts[0].':'.$parts[1].'::x';
        }

        $parts = explode('.', $ip);

        if (count($parts) === 4) {
            return $parts[0].'.'.$parts[1].'.'.$parts[2].'.x';
        }

        return null;
    }
}
