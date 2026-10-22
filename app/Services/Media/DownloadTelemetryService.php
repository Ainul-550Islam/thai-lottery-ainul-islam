<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\DownloadEvent;
use Illuminate\Http\Request;

/**
 * Privacy-preserving application download telemetry (Audit Finding D10.2).
 *
 * Never persists raw client IP addresses: every event stores only an HMAC-SHA256
 * digest keyed with APP_KEY and deduplicates repeated clicks within a bounded
 * window so single downloads never inflate metrics.
 */
final class DownloadTelemetryService
{
    public const ALLOWED_PLATFORMS = ['android', 'ios', 'pwa'];

    public function __construct(private readonly AndroidApkReleaseService $release) {}

    public function isEnabled(): bool
    {
        if ((bool) config('conversion_tooling.downloads.tracking_enabled', false) === true) {
            return true;
        }

        return $this->release->metadata() !== null;
    }

    /**
     * @return array{recorded: bool, deduplicated: bool, platform: string, version: ?string}
     */
    public function record(
        Request $request,
        string $platform,
        ?string $version = null,
        ?string $sha256 = null,
        string $source = 'web',
    ): array {
        $normalizedPlatform = strtolower(trim($platform));
        $ipHash = $this->hashClientIp((string) ($request->ip() ?? '0.0.0.0'));
        $dedupMinutes = max(1, (int) config('conversion_tooling.downloads.dedup_minutes', 60));

        $existing = DownloadEvent::query()
            ->where('ip_hash', $ipHash)
            ->where('platform', $normalizedPlatform)
            ->where('created_at', '>=', now()->subMinutes($dedupMinutes))
            ->exists();

        if ($existing) {
            return [
                'recorded' => false,
                'deduplicated' => true,
                'platform' => $normalizedPlatform,
                'version' => $version,
            ];
        }

        DownloadEvent::query()->create([
            'platform' => $normalizedPlatform,
            'version' => $version !== null && $version !== '' ? substr($version, 0, 64) : null,
            'sha256' => $sha256 !== null && preg_match('/\A[a-f0-9]{64}\z/i', $sha256) === 1
                ? strtolower($sha256)
                : null,
            'source' => substr($source, 0, 32),
            'ip_hash' => $ipHash,
            'user_agent_family' => $this->classifyUserAgent((string) $request->userAgent()),
            'created_at' => now(),
        ]);

        return [
            'recorded' => true,
            'deduplicated' => false,
            'platform' => $normalizedPlatform,
            'version' => $version,
        ];
    }

    private function hashClientIp(string $ip): string
    {
        $key = (string) config('app.key', 'thai-lottery-download-telemetry');

        return hash_hmac('sha256', 'download_telemetry|'.$ip, $key);
    }

    private function classifyUserAgent(string $userAgent): string
    {
        $ua = strtolower($userAgent);

        return match (true) {
            str_contains($ua, 'android') => 'android',
            str_contains($ua, 'iphone'), str_contains($ua, 'ipad'), str_contains($ua, 'ios') => 'ios',
            str_contains($ua, 'windows') => 'windows',
            str_contains($ua, 'macintosh'), str_contains($ua, 'mac os') => 'macos',
            str_contains($ua, 'linux') => 'linux',
            default => 'other',
        };
    }
}
