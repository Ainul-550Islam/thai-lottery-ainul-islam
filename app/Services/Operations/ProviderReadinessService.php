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
 * any external API. It reads config keys named in config/operations-readiness.php
 * and reports what they hold. A lane that is off stays NOT_CONFIGURED; that is a
 * true statement about this deployment, not a placeholder to be filled in.
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
     * Every declared lane, in config order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function lanes(): array
    {
        $report = [];

        foreach ((array) config('operations-readiness.lanes', []) as $lane => $definition) {
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
            $lanes = (array) config('operations-readiness.lanes', []);
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
                'note' => 'This lane is not declared in config/operations-readiness.php.',
            ];
        }

        $switch = $definition['switch'] ?? null;
        $required = array_values(array_map('strval', (array) ($definition['required'] ?? [])));

        $enabled = is_string($switch) && $switch !== '' ? (bool) config($switch, false) : false;

        $undeclared = [];
        $missing = [];

        foreach ($required as $requiredKey) {
            if (! Config::has($requiredKey)) {
                $undeclared[] = $requiredKey;

                continue;
            }

            if (! $this->hasValue(config($requiredKey))) {
                $missing[] = $requiredKey;
            }
        }

        $state = match (true) {
            $undeclared !== [] && $required !== [] => self::LANE_NOT_DECLARED,
            ! $enabled => self::NOT_CONFIGURED,
            $missing !== [] => self::ENABLED_WITHOUT_CREDENTIALS,
            default => self::CONFIGURED,
        };

        // A lane with no switch is judged on its credentials alone: there is
        // nothing to turn on, so "configured" means the values are there.
        if (! is_string($switch) || $switch === '') {
            $state = match (true) {
                $undeclared !== [] => self::LANE_NOT_DECLARED,
                $missing !== [] => self::NOT_CONFIGURED,
                default => self::CONFIGURED,
            };
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
