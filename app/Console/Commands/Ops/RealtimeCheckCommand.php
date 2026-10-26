<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Support\Ops\RealtimeChecks;
use Illuminate\Console\Command;

/**
 * `php artisan realtime:check` — is the real-time lane actually wired, end to end?
 *
 * The lane has failed silently before at every layer (log driver in production,
 * a private channel with no callback, a wildcard origin, one Reverb node behind
 * two app nodes), and none of those failures shows up in a health probe. Each
 * check below is one line, PASS or FAIL, with the reason; the exit code is 1 on
 * any FAIL so a deploy script can gate on it. Nothing printed is a secret: keys,
 * secrets and passwords are never echoed, only whether they are present.
 *
 * When REALTIME_ENABLED is off the connection/reachability checks report SKIP
 * (the lane is deliberately dark) but the static checks still run, so a
 * missing channel callback is found before the switch is flipped.
 *
 * The checks themselves live in App\Support\Ops\RealtimeChecks, which
 * ProductionPreflight and the test suite call directly; this command is the
 * console surface over them.
 */
final class RealtimeCheckCommand extends Command
{
    protected $signature = 'realtime:check
        {--skip-network : Do not open TCP/TLS connections (static configuration checks only)}
        {--json : Emit the result list as JSON}';

    protected $description = 'Verify the real-time lane: broadcast driver, Reverb reachability, allowed origins, scaling, queue and private-channel authorization.';

    public function handle(RealtimeChecks $checks): int
    {
        // The console kernel reuses the command instance across calls, so the
        // results are rebuilt from scratch every run.
        $results = $checks->run(skipNetwork: (bool) $this->option('skip-network'));
        $failed = count(array_filter($results, static fn ($result): bool => $result->isFailure()));

        if ($this->option('json')) {
            $json = (string) json_encode([
                'status' => $failed === 0 ? 'PASS' : 'FAIL',
                'checks' => array_map(static fn ($result): array => $result->toArray(), $results),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            foreach (explode("\n", $json) as $line) {
                $this->line($line);
            }
        } else {
            foreach ($results as $result) {
                $this->line(sprintf('%s  %-28s %s', $result->status, $result->name, $result->message));
            }

            $this->line(sprintf('%d check(s), %d failed', count($results), $failed));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
