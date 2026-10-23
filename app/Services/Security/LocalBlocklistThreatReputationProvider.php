<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Contracts\Security\ThreatReputationProvider;
use App\Models\SecurityIpBlock;
use Illuminate\Support\Facades\Cache;

class LocalBlocklistThreatReputationProvider implements ThreatReputationProvider
{
    public function queryReputation(string $target): array
    {
        $normalized = strtolower(trim($target));
        $cacheKey = 'security:threat-reputation:'.hash('sha256', $normalized);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $cached['cached'] = true;

            return $cached;
        }

        $configuredTargets = array_map(
            static fn (mixed $item): string => strtolower(trim((string) $item)),
            (array) config('security.threat_feeds.blocked_targets', [])
        );

        if (in_array($normalized, $configuredTargets, true)) {
            $result = [
                'is_malicious' => true,
                'threat_type' => 'CONFIGURED_BLOCKLIST',
                'confidence_score' => 100,
                'source' => 'config.security.threat_feeds',
                'cached' => false,
            ];
            Cache::put($cacheKey, $result, 300);

            return $result;
        }

        $ipBlock = SecurityIpBlock::query()
            ->where('ip_or_cidr', $normalized)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        $result = $ipBlock !== null
            ? [
                'is_malicious' => true,
                'threat_type' => (string) ($ipBlock->reason ?? 'IP_BLOCKLIST'),
                'confidence_score' => 95,
                'source' => 'security_ip_blocks',
                'cached' => false,
            ]
            : [
                'is_malicious' => false,
                'threat_type' => null,
                'confidence_score' => 0,
                'source' => 'local_blocklist',
                'cached' => false,
            ];

        Cache::put($cacheKey, $result, 300);

        return $result;
    }
}
