<?php

declare(strict_types=1);

namespace App\Services\Security\LoginHistory;

use App\Enums\LoginEventOutcome;
use App\Models\LoginEvent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

final class LoginHistoryQueryService
{
    /**
     * @return LengthAwarePaginator<LoginEvent>
     */
    public function forMember(User $member, int $perPage = 20): LengthAwarePaginator
    {
        $defaultPerPage = max(1, (int) config('rewards.login_history.per_page', 20));
        $maxPerPage = max($defaultPerPage, (int) config('rewards.login_history.max_per_page', 100));
        $resolvedPerPage = max(1, min($maxPerPage, $perPage > 0 ? $perPage : $defaultPerPage));

        return LoginEvent::query()
            ->where('member_id', $member->getKey())
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($resolvedPerPage);
    }

    /**
     * @return array{
     *     window_days: int,
     *     total_events_30d: int,
     *     successful_logins_30d: int,
     *     failed_attempts_30d: int,
     *     blocked_attempts_30d: int,
     *     mfa_challenges_30d: int,
     *     distinct_ips_30d: int,
     *     distinct_countries_30d: int,
     *     countries_30d: list<string>,
     *     distinct_devices_30d: int,
     *     new_devices_30d: int,
     *     last_login: array<string, mixed>|null
     * }
     */
    public function summaryForMember(User $member, ?CarbonInterface $now = null): array
    {
        $pointInTime = $now ?? Carbon::now();
        $windowDays = max(1, (int) config('rewards.login_history.summary_window_days', 30));
        $since = Carbon::instance($pointInTime->toDateTime())->subDays($windowDays);

        $baseQuery = LoginEvent::query()
            ->where('member_id', $member->getKey())
            ->where('occurred_at', '>=', $since);

        $total = (clone $baseQuery)->count();
        $successful = (clone $baseQuery)->where('outcome', LoginEventOutcome::Success->value)->count();
        $failed = (clone $baseQuery)->where('outcome', LoginEventOutcome::Failed->value)->count();
        $blocked = (clone $baseQuery)->where('outcome', LoginEventOutcome::Blocked->value)->count();
        $mfaRequired = (clone $baseQuery)->where('outcome', LoginEventOutcome::MfaRequired->value)->count();
        $distinctIps = (clone $baseQuery)
            ->whereNotNull('ip')
            ->distinct('ip')
            ->count('ip');

        /** @var list<string> $countries */
        $countries = (clone $baseQuery)
            ->whereNotNull('ip_country')
            ->distinct()
            ->orderBy('ip_country')
            ->pluck('ip_country')
            ->map(static fn ($c): string => (string) $c)
            ->values()
            ->all();

        $distinctDevices = (clone $baseQuery)
            ->whereNotNull('device_fingerprint')
            ->distinct('device_fingerprint')
            ->count('device_fingerprint');
        $newDevices = (clone $baseQuery)->where('is_new_device', true)->count();

        /** @var LoginEvent|null $lastSuccess */
        $lastSuccess = LoginEvent::query()
            ->where('member_id', $member->getKey())
            ->where('outcome', LoginEventOutcome::Success->value)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        return [
            'window_days' => $windowDays,
            'total_events_30d' => $total,
            'successful_logins_30d' => $successful,
            'failed_attempts_30d' => $failed,
            'blocked_attempts_30d' => $blocked,
            'mfa_challenges_30d' => $mfaRequired,
            'distinct_ips_30d' => $distinctIps,
            'distinct_countries_30d' => count($countries),
            'countries_30d' => $countries,
            'distinct_devices_30d' => $distinctDevices,
            'new_devices_30d' => $newDevices,
            'last_login' => $lastSuccess instanceof LoginEvent ? [
                'occurred_at' => $lastSuccess->occurred_at->toIso8601String(),
                'ip' => $lastSuccess->ip,
                'ip_country' => $lastSuccess->ip_country,
                'device' => $lastSuccess->device,
                'os' => $lastSuccess->os,
                'browser' => $lastSuccess->browser,
            ] : null,
        ];
    }
}
