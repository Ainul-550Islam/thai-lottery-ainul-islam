<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Services\Operations\ProviderReadinessService;
use App\Services\Operations\ScheduleCoverageService;
use Illuminate\Console\Command;

/**
 * Print the operations readiness picture: which lanes are configured, and which
 * of this application's commands are actually scheduled.
 *
 * It reports; it changes nothing. It also never invents a status — a lane with
 * no credentials is printed as NOT_CONFIGURED, which is the truth about this
 * deployment, and a command nobody scheduled is printed as unscheduled even
 * when that is uncomfortable to read.
 *
 * Exit codes: 0 normally, 1 when a lane is ENABLED_WITHOUT_CREDENTIALS or a
 * required command is missing from the schedule — the two states that mean
 * "someone has to act before this is production".
 */
final class ReportOperationsReadinessCommand extends Command
{
    protected $signature = 'operations:readiness-report
        {--lanes : Only the provider lanes}
        {--schedule : Only the schedule coverage}';

    protected $description = 'Report which provider lanes are configured and which application commands are scheduled';

    public function handle(
        ProviderReadinessService $readiness,
        ScheduleCoverageService $scheduleCoverage,
    ): int {
        $lanesOnly = (bool) $this->option('lanes');
        $scheduleOnly = (bool) $this->option('schedule');
        $showBoth = ! $lanesOnly && ! $scheduleOnly;
        $act = 0;

        if ($showBoth || $lanesOnly) {
            $act += $this->reportLanes($readiness);
        }

        if ($showBoth || $scheduleOnly) {
            $act += $this->reportSchedule($scheduleCoverage);
        }

        if ($act > 0) {
            $this->error($act.' item(s) need action before this deployment is production-ready.');

            return self::FAILURE;
        }

        $this->info('Nothing needs immediate action. NOT_CONFIGURED lanes are reported, not hidden.');

        return self::SUCCESS;
    }

    private function reportLanes(ProviderReadinessService $readiness): int
    {
        $this->info('Provider lanes');

        $rows = [];
        $act = 0;

        foreach ($readiness->lanes() as $lane) {
            $rows[] = [
                $lane['lane'],
                $lane['state'],
                $lane['switch'] === null ? '—' : (string) $lane['switch'].'='.(($lane['enabled']) ? 'on' : 'off'),
                $lane['missing'] === [] ? '—' : implode(', ', $lane['missing']),
            ];

            if (in_array($lane['state'], [
                ProviderReadinessService::ENABLED_WITHOUT_CREDENTIALS,
                ProviderReadinessService::LANE_NOT_DECLARED,
            ], true)) {
                $act++;
            }
        }

        $this->table(['lane', 'state', 'switch', 'missing credentials'], $rows);

        $summary = $readiness->summary();
        $this->line(sprintf(
            'configured: %d · not_configured: %d · enabled_without_credentials: %d · lane_not_declared: %d',
            $summary['configured'],
            $summary['not_configured'],
            $summary['enabled_without_credentials'],
            $summary['lane_not_declared'],
        ));

        return $act;
    }

    private function reportSchedule(ScheduleCoverageService $scheduleCoverage): int
    {
        $report = $scheduleCoverage->report();

        $this->info('Schedule coverage');
        $this->line($report['coverage']);
        $this->line('scheduled: '.($report['scheduled'] === [] ? '—' : implode(', ', $report['scheduled'])));
        $this->line('unscheduled application commands: '.count($report['unscheduled']));

        foreach (array_slice($report['unscheduled'], 0, 40) as $command) {
            $this->line('  · '.$command);
        }

        if ($report['required_but_missing'] !== []) {
            foreach ($report['required_but_missing'] as $command) {
                $this->warn('required but not scheduled: '.$command);
            }

            return count($report['required_but_missing']);
        }

        return 0;
    }
}
