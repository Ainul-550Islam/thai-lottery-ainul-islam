<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Illuminate\Support\Facades\Config;

/**
 * One honest answer per provider lane: configured, or NOT_CONFIGURED — and why.
 *
 * WHY THIS EXISTS SEPARATELY FROM ProviderHealthService
 * `ProviderHealthService::knownProviders()` is built from observed traffic:
 * it lists the gateways that appear in `payments`, `payment_webhooks` and
 * `provider_operations`. That is the right question for a health desk, and the
 * wrong one for readiness — a lane that has never been used is invisible to it,
 * and a lane with no credentials at all is exactly the one an operator needs to
 * see before launch.
 *
 * WHAT IT WILL NOT DO
 * It does not guess a provider's status, synthesise an uptime figure, or call
 * any external API. It reads the lane catalogue in
 * config/operations_readiness.php and reports what the named keys hold. A lane
 * that is off stays NOT_CONFIGURED; that is a true statement about this
 * deployment, not a placeholder to be filled in.
 *
 * DECLARATION GAP vs CONFIGURATION GAP
 * The `declared` list names the config keys that must EXIST for the lane to be
 * declared at all; a missing one is a LANE_NOT_DECLARED finding. The
 * `credentials` list names the keys that must hold a value once the lane is in
 * use; an existing-but-empty one is a NOT_CONFIGURED /
 * ENABLED_WITHOUT_CREDENTIALS gap. The hyphenated config file is only a
 * schedule alias and never carries the lanes.
 *
 * THE STATE THAT MATTERS MOST
 * `ENABLED_WITHOUT_CREDENTIALS` — a switch flipped on with no credentials behind
 * it. NOT_CONFIGURED is safe and visible; a half-configured lane is the one that
 * fails at the moment a player uses it.
 */
final class ProviderReadinessService
{
    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    public const CONFIGURED = 'CONFIGURED';

    public const ENABLED_WITHOUT_CREDENTIALS = 'ENABLED_WITHOUT_CREDENTIALS';

    public const LANE_NOT_DECLARED = 'LANE_NOT_DECLARED';

    /**
     * The config root that holds the lane catalogue. Kept in one place so the
     * hyphenated alias file (which only carries required_scheduled_commands)
     * can never again be mistaken for the source of the lanes.
     */
    private const LANES_CONFIG_KEY = 'operations_readiness.lanes';

    /**
     * Every declared lane, in config order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function lanes(): array
    {
        $report = [];

        foreach ((array) config(self::LANES_CONFIG_KEY, []) as $lane => $definition) {
            $report[(string) $lane] = $this->lane((string) $lane, (array) $definition);
        }

        return $report;
    }

    /**
     * One lane. Unknown lanes are reported rather than throwing, because a
     * readiness report that dies on a typo tells an operator nothing.
     *
     * @param  array<string, mixed>|null  $definition
     * @return array<string, mixed>
     */
    public function lane(string $key, ?array $definition = null): array
    {
        // Array lookup, not a dotted config path: lane names contain dots
        // ('captcha.turnstile'), and config() would read those as nesting and
        // silently miss the lane.
        if ($definition === null) {
            $lanes = (array) config(self::LANES_CONFIG_KEY, []);
            $definition = (array) ($lanes[$key] ?? []);
        }

        if ($definition === []) {
            return [
                'lane' => $key,
                'state' => self::LANE_NOT_DECLARED,
                'enabled' => false,
                'switch' => null,
                'missing' => [],
                'undeclared' => [$key],
                'note' => 'This lane is not declared in config/operations_readiness.php.',
            ];
        }

        $switch = $definition['switch'] ?? null;
        $switch = is_string($switch) && $switch !== '' ? $switch : null;
        $credentials = array_values(array_map('strval', (array) ($definition['credentials'] ?? [])));
        $declared = array_values(array_map('strval', (array) ($definition['declared'] ?? [])));

        $enabled = $switch !== null ? (bool) config($switch, false) : false;

        $undeclared = [];
        $missing = [];

        // A declaration key that does not exist at all means the lane was
        // never fully provisioned in configuration — distinct from an empty
        // value the operator still has to fill in.
        foreach ($declared as $declaredKey) {
            if (! Config::has($declaredKey)) {
                $undeclared[] = $declaredKey;
            }
        }

        foreach ($credentials as $credentialKey) {
            if (! Config::has($credentialKey)) {
                $undeclared[] = $credentialKey;

                continue;
            }

            if (! $this->hasValue(config($credentialKey))) {
                $missing[] = $credentialKey;
            }
        }

        $undeclared = array_values(array_unique($undeclared));
        $missing = array_values(array_unique($missing));

        // A declaration gap wins over every other verdict: the report cannot
        // claim a lane is merely "off" when part of it does not exist.
        if ($undeclared !== []) {
            $state = self::LANE_NOT_DECLARED;
        } elseif ($switch === null) {
            // A lane with no switch is judged on its credentials alone: there
            // is nothing to turn on, so "configured" means the values exist.
            $state = $missing !== [] ? self::NOT_CONFIGURED : self::CONFIGURED;
        } elseif (! $enabled) {
            $state = self::NOT_CONFIGURED;
        } elseif ($missing !== []) {
            $state = self::ENABLED_WITHOUT_CREDENTIALS;
        } else {
            $state = self::CONFIGURED;
        }

        return [
            'lane' => $key,
            'state' => $state,
            'enabled' => $enabled,
            'switch' => $switch,
            'missing' => $missing,
            'undeclared' => $undeclared,
            'note' => (string) ($definition['note'] ?? ''),
        ];
    }

    /**
     * Only the lanes an operator has to act on: anything that is neither
     * cleanly CONFIGURED nor cleanly NOT_CONFIGURED.
     *
     * @return array<string, array<string, mixed>>
     */
    public function findings(): array
    {
        return array_filter(
            $this->lanes(),
            static fn (array $lane): bool => in_array(
                $lane['state'],
                [self::ENABLED_WITHOUT_CREDENTIALS, self::LANE_NOT_DECLARED],
                true,
            ),
        );
    }

    /**
     * @return array{configured: int, not_configured: int, enabled_without_credentials: int, lane_not_declared: int}
     */
    public function summary(): array
    {
        $counts = [
            'configured' => 0,
            'not_configured' => 0,
            'enabled_without_credentials' => 0,
            'lane_not_declared' => 0,
        ];

        foreach ($this->lanes() as $lane) {
            $counts[match ($lane['state']) {
                self::CONFIGURED => 'configured',
                self::NOT_CONFIGURED => 'not_configured',
                self::ENABLED_WITHOUT_CREDENTIALS => 'enabled_without_credentials',
                default => 'lane_not_declared',
            }]++;
        }

        return $counts;
    }

    /**
     * A value counts when it is present and not an empty string. `false` is a
     * real value — a sandbox flag set to false is configured, not missing.
     */
    private function hasValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }
}
