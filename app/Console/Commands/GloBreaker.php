<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Lottery\Support\BreakerPolicy;
use App\Services\Lottery\Support\CircuitBreaker;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * glo:breaker — inspect and clear the result-source circuit breakers.
 *
 * ============================================================================
 * WHY AN OPERATOR NEEDS THIS, AND WHY IT IS NOT A CONVENIENCE COMMAND
 * ============================================================================
 * A circuit breaker that nobody can see or clear is a breaker that turns a
 * thirty-second upstream blip into an hour-long result outage. The breaker
 * deliberately refuses to ask an unhealthy source for `cooldown_seconds`; when
 * an operator has independent knowledge that the upstream is healthy again —
 * they can see the GLO site publishing, they just fixed the egress rule — the
 * only alternatives without this command are to wait out the cooldown or to
 * clear the cache by hand, and clearing the cache by hand also clears every
 * idempotency key and throttle bucket that happens to live in the same store.
 *
 * So the reset is SCOPED: it forgets one source's breaker key and nothing else.
 *
 * ============================================================================
 * RESET IS NOT A SILENT RETRY BUTTON
 * ============================================================================
 * `--reset` closes the circuit. It does NOT fetch, does not import, and does not
 * publish anything: the next scheduled or manual import does that, through the
 * ordinary path, subject to the ordinary controls. An operator reaching for this
 * command during an incident should not have to wonder whether it has already
 * moved money. It has not.
 *
 * The command prints the state BEFORE and AFTER, because "I reset it and it went
 * straight back to open" and "I reset it and it stayed closed" are different
 * incidents that deserve different next steps — and the second is only
 * distinguishable from the first if the before-state was recorded.
 */
class GloBreaker extends Command
{
    protected $signature = 'glo:breaker
        {source=official : The result source to act on: official or fixture}
        {--reset : Close the circuit for this source and clear its counters}
        {--json : Machine-readable output}';

    protected $description = 'Inspect or clear the GLO result-source circuit breakers (read-only unless --reset is given)';

    /**
     * The sources this command will act on. Anything else is refused: a resolver
     * that silently maps an unrecognised name onto a real source would let a typo
     * clear the wrong breaker.
     *
     * @var list<string>
     */
    private const SOURCES = ['official', 'fixture'];

    public function handle(
        CircuitBreaker $breaker,
        ConfigRepository $config,
    ): int {
        $source = strtolower(trim((string) $this->argument('source')));

        if (! in_array($source, self::SOURCES, true)) {
            // Not defaulted to 'official'. Guessing which source an operator
            // meant, and then RESETTING it, is how a typo becomes an incident.
            $this->error(sprintf(
                'Unknown source "%s". Expected one of: %s.',
                $source,
                implode(', ', self::SOURCES),
            ));

            return self::FAILURE;
        }

        $policy = BreakerPolicy::fromConfig($config);
        $before = $breaker->describe($source, $policy);

        $reset = (bool) $this->option('reset');

        if ($reset) {
            $breaker->reset($source, $policy);
        }

        $after = $reset ? $breaker->describe($source, $policy) : $before;

        $report = [
            'command' => 'glo:breaker',
            'source' => $source,
            'reset' => $reset,
            'policy' => [
                'enabled' => $policy->enabled,
                'failure_threshold' => $policy->failureThreshold,
                'cooldown_seconds' => $policy->cooldownSeconds,
                'half_open_probes' => $policy->halfOpenProbes,
            ],
            'before' => $before,
            'after' => $after,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('GLO result-source circuit breaker');
        $this->line('  source: '.$source);
        $this->line('  policy: '.($policy->enabled
            ? sprintf(
                'enabled (threshold=%d, cooldown=%ds, probes=%d)',
                $policy->failureThreshold,
                $policy->cooldownSeconds,
                $policy->halfOpenProbes,
            )
            : 'DISABLED — failures are not being tracked'));

        $this->describe($before, 'before');

        if ($reset) {
            $this->describe($after, 'after');
        }

        if (! $policy->enabled) {
            // Stated rather than implied. A disabled breaker in an incident is
            // the difference between "the source is down and we stopped asking"
            // and "we keep paying the timeout on every tick", and an operator
            // reading state=failures=0 must be told which one they are looking at.
            $this->warn('Breaker is DISABLED by configuration; state shown is not being maintained.');
        }

        if (! $reset && $before['state'] === 'open') {
            $this->warn(sprintf(
                'Circuit is OPEN; the source will not be asked again for %ds. '
                .'Use --reset only if you have independent evidence the source is healthy.',
                $before['retry_after_seconds'],
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{state: string, failures: int, opened_at: string|null, retry_after_seconds: int, last_success_at: string|null, last_failure_at: string|null}  $state
     */
    private function describe(array $state, string $label): void
    {
        $this->line(sprintf(
            '  %s: state=%s failures=%d opened_at=%s retry_after=%ds last_success=%s last_failure=%s',
            $label,
            $state['state'],
            $state['failures'],
            $state['opened_at'] ?? '-',
            $state['retry_after_seconds'],
            $state['last_success_at'] ?? '-',
            $state['last_failure_at'] ?? '-',
        ));
    }
}
