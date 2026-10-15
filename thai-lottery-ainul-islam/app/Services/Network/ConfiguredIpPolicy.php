<?php

declare(strict_types=1);

namespace App\Services\Network;

use Illuminate\Contracts\Config\Repository;
use LogicException;

/** Configured + optional database membership, never a reputation/fraud score. */
final class ConfiguredIpPolicy
{
    public function __construct(
        private readonly Repository $config,
        private readonly IpRangeMatcher $matcher,
        private readonly ?DatabaseIpRuleReader $database = null,
    ) {}

    /** @return array{mode: string, code: string} No raw IP or matching range. */
    public function assess(mixed $address): array
    {
        $mode = $this->config->get('ip_access.mode', 'log');
        $enabled = $this->config->get('ip_access.enabled', true);
        if (! in_array($mode, ['log', 'enforce'], true) || ! is_bool($enabled)) {
            throw new LogicException('Invalid IP access policy.');
        }
        if (! $enabled) {
            return ['mode' => $mode, 'code' => 'DISABLED'];
        }
        $databaseEnabled = $this->config->get('ip_access.database_enabled', false);
        if (! is_bool($databaseEnabled)) {
            throw new LogicException('Invalid IP access policy.');
        }
        $ranges = $this->config->get('ip_access.blocked_ranges', []);
        if (! is_array($ranges) || ! array_is_list($ranges) || count($ranges) > 256) {
            throw new LogicException('Invalid IP access policy.');
        }
        $normalized = [];
        foreach ($ranges as $range) {
            $range = $this->matcher->normalizeRange($range);
            if ($range === null) {
                // Validate ALL entries before considering any match/nonmatch.
                throw new LogicException('Invalid IP access policy.');
            }
            $normalized[] = $range;
        }
        $address = $this->matcher->normalizeAddress($address);
        if ($address === null) {
            return ['mode' => $mode, 'code' => 'ADDRESS_UNAVAILABLE'];
        }
        foreach (array_unique($normalized) as $range) {
            if ($this->matcher->matches($address, $range)) {
                return ['mode' => $mode, 'code' => 'LISTED'];
            }
        }

        // A known configured match above already decides LISTED, without DB I/O.
        // Never report a missing/invalid/over-capacity DB snapshot as NOT_LISTED.
        if ($databaseEnabled) {
            $snapshot = ($this->database ?? new DatabaseIpRuleReader)->read();
            if ($snapshot['code'] !== 'READY') {
                return ['mode' => $mode, 'code' => $snapshot['code']];
            }
            foreach ($snapshot['ranges'] as $range) {
                if ($this->matcher->matches($address, $range)) {
                    return ['mode' => $mode, 'code' => 'LISTED'];
                }
            }
        }

        return ['mode' => $mode, 'code' => 'NOT_LISTED'];
    }
}
