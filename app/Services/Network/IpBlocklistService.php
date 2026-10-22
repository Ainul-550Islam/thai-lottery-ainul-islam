<?php

declare(strict_types=1);

namespace App\Services\Network;

use App\Models\BlockedIp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Class IpBlocklistService
 *
 * Cache-accelerated, database-authoritative IP/CIDR blocklist service with
 * emergency break-glass admin allowlist support.
 */
class IpBlocklistService
{
    private const CACHE_KEY = 'security:active_ip_blocks';

    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly IpCidrMatcher $cidrMatcher
    ) {}

    /**
     * Check if a client IP is currently blocked by active database rules.
     */
    public function isBlocked(string $ip): bool
    {
        // 1. Break-glass admin emergency allowlist check
        $allowlist = (array) config('network.admin_allowlist', []);
        foreach ($allowlist as $allowedRange) {
            if ($this->cidrMatcher->matches($ip, $allowedRange)) {
                return false; // Whitelisted administrator
            }
        }

        // 2. Fetch active rules from cache / database
        $activeRules = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () {
            return BlockedIp::where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>', now());
                })
                ->get(['ip_or_cidr', 'reason_code'])
                ->toArray();
        });

        foreach ($activeRules as $rule) {
            if ($this->cidrMatcher->matches($ip, $rule['ip_or_cidr'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a new IP/CIDR block rule.
     */
    public function blockIp(
        string $ipOrCidr,
        string $reasonCode,
        string $source = 'MANUAL',
        ?int $durationMinutes = null,
        ?string $actorId = null,
        ?string $notes = null
    ): BlockedIp {
        $ipOrCidr = trim($ipOrCidr);
        $expiresAt = $durationMinutes !== null ? now()->addMinutes($durationMinutes) : null;

        $record = BlockedIp::updateOrCreate(
            ['ip_or_cidr' => $ipOrCidr],
            [
                'id' => Str::uuid()->toString(),
                'ip_version' => str_contains($ipOrCidr, ':') ? 6 : 4,
                'is_cidr' => str_contains($ipOrCidr, '/'),
                'reason_code' => $reasonCode,
                'source' => $source,
                'is_active' => true,
                'created_by_user_id' => $actorId,
                'expires_at' => $expiresAt,
                'notes' => $notes,
            ]
        );

        $this->invalidateCache();

        Log::warning("IP/CIDR Block Added: {$ipOrCidr} [Reason: {$reasonCode}, Source: {$source}]");

        return $record;
    }

    /**
     * Unblock an IP or CIDR rule with audit tracking.
     */
    public function unblockIp(string $id, string $actorId, string $unblockReason): BlockedIp
    {
        /** @var BlockedIp $record */
        $record = BlockedIp::findOrFail($id);

        $record->update([
            'is_active' => false,
            'unblocked_by_user_id' => $actorId,
            'unblocked_at' => now(),
            'notes' => trim(($record->notes ?? '')." | Unblock Reason: {$unblockReason}"),
        ]);

        $this->invalidateCache();

        Log::info("IP/CIDR Unblocked: {$record->ip_or_cidr} by Actor {$actorId}");

        return $record;
    }

    /**
     * Invalidate Redis cache on block mutations.
     */
    public function invalidateCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
