<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Support\Ops\ProductionPreflight;
use Illuminate\Console\Command;

/**
 * `ops:preflight` — the gate a release passes through before it takes traffic.
 *
 * Exit codes are the contract, because everything else that uses this command
 * is a script:
 *   0  no blocking failure
 *   1  at least one blocking failure
 *   2  warnings only (and `--strict` was passed)
 *
 * The output never contains a secret value; see ProductionPreflight.
 */
class PreflightCommand extends Command
{
    protected $signature = 'ops:preflight
        {--json : Emit machine-readable JSON (for deploy scripts and CI)}
        {--offline : Skip database, cache and Redis probes}
        {--strict : Treat warnings as failures}
        {--environment= : Assert a specific APP_ENV before running the checks}';

    protected $description = 'Validate production configuration, backup readiness, build artifacts and connectivity without revealing secret values.';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $asserted = $this->option('environment');

        if (is_string($asserted) && $asserted !== '' && $asserted !== (string) config('app.env')) {
            $payload = [
                'status' => 'FAIL',
                'environment' => (string) config('app.env'),
                'checks' => [[
                    'id' => 'app.environment',
                    'status' => 'FAIL',
                    'detail' => sprintf('Refusing to continue: APP_ENV is "%s" but "%s" was required.', (string) config('app.env'), $asserted),
                    'severity' => 'blocking',
                ]],
                'summary' => ['pass' => 0, 'warn' => 0, 'fail' => 1, 'skip' => 0],
            ];

            $json ? $this->line((string) json_encode($payload, JSON_PRETTY_PRINT)) : $this->components->error($payload['checks'][0]['detail']);

            return self::FAILURE;
        }

        $production = in_array((string) config('app.env'), ['production', 'prod'], true);

        $report = (new ProductionPreflight($production, ! $this->option('offline')))->run();

        if ($json) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->newLine();
            $this->line(sprintf('  <fg=cyan>Deployment preflight</> — environment <comment>%s</comment>', $report['environment']));
            $this->newLine();

            foreach ($report['checks'] as $check) {
                $badge = match ($check['status']) {
                    'PASS' => '<fg=green>PASS</>',
                    'WARN' => '<fg=yellow>WARN</>',
                    'FAIL' => '<fg=red>FAIL</>',
                    default => '<fg=gray>SKIP</>',
                };

                $this->line(sprintf('  %s  %-32s %s', $badge, $check['id'], $check['detail']));
            }

            $this->newLine();
            $this->line(sprintf(
                '  %d pass, %d warn, %d fail, %d skipped',
                $report['summary']['pass'],
                $report['summary']['warn'],
                $report['summary']['fail'],
                $report['summary']['skip'],
            ));
        }
        $blocking = 0;

        foreach ($report['checks'] as $check) {
            if ($check['status'] === 'FAIL' && $check['severity'] === 'blocking') {
                $blocking++;
            }
        }

        if ($blocking > 0) {
            if (! $json) {
                $this->components->error(sprintf('Preflight FAILED: %d blocking problem(s). This release must not proceed.', $blocking));
            }

            return self::FAILURE;
        }

        if ($this->option('strict') && $report['summary']['warn'] > 0) {
            if (! $json) {
                $this->components->warn('Preflight produced warnings and --strict was passed.');
            }

            return 2;
        }

        if (! $json) {
            $this->components->info('Preflight passed.');
        }

        return self::SUCCESS;
    }
}
