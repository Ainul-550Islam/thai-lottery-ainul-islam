<?php

declare(strict_types=1);

namespace App\Services\Security\LoginHistory;

use App\Enums\LoginEventOutcome;
use App\Events\Security\NewDeviceLoginDetected;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Security\LoginHistory\GeoIp\GeoIpResolver;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use App\Services\Home\DashboardCacheInvalidator;

final class LoginEventRecorder
{
    public function __construct(
        private readonly GeoIpResolver $geoIp,
        private readonly NewDeviceDetector $newDeviceDetector,
        private readonly DashboardCacheInvalidator $dashboardCache,
    ) {}

    /**
     * Sole writer of login_events. Never stores passwords, OTPs, or plaintext unknown identifiers.
     */
    public function record(
        ?User $member,
        string $identifier,
        LoginEventOutcome $outcome,
        ?string $ip = null,
        ?string $userAgent = null,
        ?string $explicitDeviceFingerprint = null,
        ?CarbonInterface $occurredAt = null,
    ): LoginEvent {
        $pointInTime = $occurredAt !== null ? Carbon::instance($occurredAt->toDateTime()) : Carbon::now();
        $parsedUa = $this->parseUserAgent($userAgent);
        $fingerprint = $this->resolveDeviceFingerprint($explicitDeviceFingerprint, $userAgent, $parsedUa);
        $identifierHash = $this->hashIdentifier($identifier !== '' ? $identifier : (string) ($member?->email ?? 'unknown'));
        $geoResult = $this->geoIp->resolve($ip);
        $country = $geoResult?->countryCode;

        $trustedDevice = null;
        $isNewDevice = false;

        if ($member instanceof User && $fingerprint !== null) {
            $trustedDevice = $this->newDeviceDetector->matchedTrustedDevice($member, $fingerprint);
            if ($outcome === LoginEventOutcome::Success) {
                $isNewDevice = $this->newDeviceDetector->isNewDevice($member, $fingerprint);
            }
        }

        /** @var LoginEvent $loginEvent */
        $loginEvent = LoginEvent::query()->create([
            'member_id' => $member instanceof User ? (int) $member->getKey() : null,
            'trusted_device_id' => $trustedDevice?->getKey() !== null ? (int) $trustedDevice->getKey() : null,
            'identifier_hash' => $identifierHash,
            'ip' => $ip !== null ? substr($ip, 0, 45) : null,
            'ip_country' => $country,
            'device' => $parsedUa['device'],
            'os' => $parsedUa['os'],
            'browser' => $parsedUa['browser'],
            'device_fingerprint' => $fingerprint,
            'is_new_device' => $isNewDevice,
            'outcome' => $outcome,
            'occurred_at' => $pointInTime,
        ]);

        if ($member instanceof User) {
            $this->dashboardCache->forget($member);

            if ($outcome === LoginEventOutcome::Success && $isNewDevice) {
                event(new NewDeviceLoginDetected($member, $loginEvent));
            }
        }

        return $loginEvent;
    }

    public function recordFromRequest(
        Request $request,
        ?User $member,
        string $identifier,
        LoginEventOutcome $outcome,
    ): LoginEvent {
        $headerFingerprint = $request->header('X-Device-Fingerprint');
        $explicitFingerprint = is_string($headerFingerprint) && trim($headerFingerprint) !== ''
            ? trim($headerFingerprint)
            : null;

        $duplicate = $this->recentDuplicateSuccess($request, $member, $identifier, $outcome, $explicitFingerprint);
        if ($duplicate instanceof LoginEvent) {
            return $duplicate;
        }

        return $this->record(
            member: $member,
            identifier: $identifier,
            outcome: $outcome,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            explicitDeviceFingerprint: $explicitFingerprint,
        );
    }

    /**
     * The same successful login can reach the recorder twice (framework Login
     * event + API token issuance). Only SUCCESS is collapsed, and only inside a
     * short configured window; failed/blocked attempts are never merged so
     * brute-force volume is always counted in full.
     */
    private function recentDuplicateSuccess(
        Request $request,
        ?User $member,
        string $identifier,
        LoginEventOutcome $outcome,
        ?string $explicitFingerprint,
    ): ?LoginEvent {
        $windowSeconds = max(0, (int) config('rewards.login_history.dedupe_window_seconds', 5));
        if ($windowSeconds === 0 || $outcome !== LoginEventOutcome::Success || ! $member instanceof User) {
            return null;
        }

        $userAgent = $request->userAgent();
        $fingerprint = $this->resolveDeviceFingerprint($explicitFingerprint, $userAgent, $this->parseUserAgent($userAgent));
        $identifierHash = $this->hashIdentifier($identifier !== '' ? $identifier : (string) ($member->email ?? 'unknown'));
        $ip = $request->ip();

        $query = LoginEvent::query()
            ->where('member_id', $member->getKey())
            ->where('identifier_hash', $identifierHash)
            ->where('outcome', LoginEventOutcome::Success->value)
            ->where('occurred_at', '>=', Carbon::now()->subSeconds($windowSeconds));

        $ip === null
            ? $query->whereNull('ip')
            : $query->where('ip', substr($ip, 0, 45));

        $fingerprint === null
            ? $query->whereNull('device_fingerprint')
            : $query->where('device_fingerprint', $fingerprint);

        $existing = $query->orderByDesc('id')->first();

        return $existing instanceof LoginEvent ? $existing : null;
    }

    public function hashIdentifier(string $identifier): string
    {
        $normalised = mb_strtolower(trim($identifier));
        $secret = (string) (config('rewards.login_history.identifier_hash_key') ?: config('app.key'));
        if ($secret === '') {
            throw new \RuntimeException('Login-history identifier hash key is not configured (set REWARDS_LOGIN_IDENTIFIER_HASH_KEY or APP_KEY).');
        }

        return hash_hmac('sha256', 'login-identifier|'.$normalised, $secret);
    }

    /**
     * @return array{device: string, os: string, browser: string}
     */
    public function parseUserAgent(?string $userAgent): array
    {
        $ua = trim((string) $userAgent);
        if ($ua === '') {
            return [
                'device' => 'Unknown',
                'os' => 'Unknown',
                'browser' => 'Unknown',
            ];
        }

        $os = match (true) {
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad'), str_contains($ua, 'iOS') => 'iOS',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X'), str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Other',
        };

        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/'), str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') && ! str_contains($ua, 'Chrome/') => 'Safari',
            str_contains($ua, 'curl/') => 'curl',
            default => 'Other',
        };

        $device = match (true) {
            str_contains($ua, 'iPad'), str_contains($ua, 'Tablet') => 'Tablet',
            str_contains($ua, 'Mobile'), str_contains($ua, 'iPhone'), str_contains($ua, 'Android') => 'Mobile',
            default => 'Desktop',
        };

        return [
            'device' => $device,
            'os' => $os,
            'browser' => $browser,
        ];
    }

    /**
     * @param  array{device: string, os: string, browser: string}  $parsedUa
     */
    private function resolveDeviceFingerprint(?string $explicit, ?string $userAgent, array $parsedUa): ?string
    {
        if ($explicit !== null && trim($explicit) !== '') {
            $clean = trim($explicit);

            return preg_match('/^[a-f0-9]{64}$/i', $clean) === 1
                ? strtolower($clean)
                : hash('sha256', 'device-fp|'.$clean);
        }

        $ua = trim((string) $userAgent);
        if ($ua === '') {
            return null;
        }

        return hash('sha256', sprintf('ua-fp|%s|%s|%s|%s', $parsedUa['device'], $parsedUa['os'], $parsedUa['browser'], $ua));
    }
}
