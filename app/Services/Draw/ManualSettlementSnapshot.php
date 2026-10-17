<?php

declare(strict_types=1);

namespace App\Services\Draw;

use Illuminate\Config\Repository;

/**
 * The rule snapshot a manually driven chunked run is pinned to.
 *
 * A chunked run that an operator drives by hand (start, chunk, chunk, …)
 * can span minutes and several processes. The rules that price its chunks —
 * chunk geometry, the multiplier source, the approval thresholds, the money
 * precision — must be ONE set for the whole run, not whatever the config
 * happens to say when a later chunk starts. This snapshot names those keys,
 * captures their values at open, and fingerprints them so a later chunk can
 * prove it is still running under the rules the run was opened with.
 *
 * The safety mode is deliberately NOT in this list: the worker that drives a
 * manual run supplies `finance.prize_payout.safety_mode` alongside these
 * keys, and the mode is recorded on the run row itself.
 */
final class ManualSettlementSnapshot
{
    /**
     * Config keys whose values govern a manual chunked run.
     *
     * @var list<string>
     */
    public const RULE_KEYS = [
        'lottery.settlement.chunk_size',
        'lottery.settlement.chunk_threshold',
        'lottery.markets',
        'lottery.payouts.multiplier_source',
        'finance.prize_payout.auto_approve_below',
        'finance.prize_payout.require_approval_at_or_above',
        'finance.precision',
    ];

    public function __construct(private readonly Repository $config) {}

    /**
     * The current values of every pinned key.
     *
     * @return array<string, mixed>
     */
    public function capture(): array
    {
        $values = [];

        foreach (self::RULE_KEYS as $key) {
            $values[$key] = $this->config->get($key);
        }

        return $values;
    }

    /**
     * Stable hash of the captured values. Two runs opened under the same
     * rules share a fingerprint; any drift changes it.
     *
     * @param  array<string, mixed>|null  $values
     */
    public function fingerprint(?array $values = null): string
    {
        return hash('sha256', (string) json_encode($values ?? $this->capture(), JSON_UNESCAPED_SLASHES));
    }

    /**
     * Whether the live rules still match a previously captured fingerprint.
     */
    public function matches(string $fingerprint): bool
    {
        return hash_equals($fingerprint, $this->fingerprint());
    }
}
