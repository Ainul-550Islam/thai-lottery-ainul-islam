<?php

declare(strict_types=1);

namespace App\Services\Network;

use App\Models\IpAccessRule;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Throwable;

/** Internal snapshot reader; ConfiguredIpPolicy uses it only with database opt-in. */
final class DatabaseIpRuleReader
{
    public const MAX_ACTIVE_RULES = 256;

    /**
     * Internal ranges must not be exposed as a public diagnostic response.
     * Any non-READY code has NO usable snapshot, not a proven empty blocklist.
     *
     * @return array{code: string, ranges: list<string>}
     */
    public function read(?CarbonInterface $at = null): array
    {
        $now = ($at ?? Carbon::now())->getTimestamp();
        try {
            $rules = IpAccessRule::query()
                ->whereNull('revoked_at_epoch')
                ->where('starts_at_epoch', '<=', $now)
                ->where(fn ($query) => $query->whereNull('expires_at_epoch')->orWhere('expires_at_epoch', '>', $now))
                ->orderBy('id')
                ->limit(self::MAX_ACTIVE_RULES + 1)
                ->get(['id', 'ip_range', 'reason_code', 'starts_at_epoch', 'expires_at_epoch', 'revoked_at_epoch']);
        } catch (Throwable) {
            return ['code' => 'STORE_UNAVAILABLE', 'ranges' => []];
        }
        if ($rules->count() > self::MAX_ACTIVE_RULES) {
            return ['code' => 'CAPACITY_EXCEEDED', 'ranges' => []];
        }
        $ranges = [];
        try {
            foreach ($rules as $rule) {
                $ranges[] = $rule->validatedRange();
            }
        } catch (InvalidArgumentException) {
            return ['code' => 'RULE_INVALID', 'ranges' => []];
        }

        return ['code' => 'READY', 'ranges' => array_values(array_unique($ranges))];
    }
}
