<?php

declare(strict_types=1);

namespace App\Console\Commands\Lifecycle;

use App\Enums\LifecycleCampaign;
use App\Services\Lifecycle\LifecycleCampaignService;
use Illuminate\Console\Command;

/**
 * Run the lifecycle email campaigns (D4).
 *
 * ONE CAMPAIGN PER INVOCATION
 * The scheduler runs this once per campaign rather than looping inside a single
 * process. That is deliberate: a crash or a slow send in one campaign must not
 * stop the other four, and a run report is far easier to read when it describes
 * exactly one thing.
 *
 * DRY RUN IS THE DEFAULT POSTURE
 * Every campaign ships with dry_run = true in config. Until an operator turns
 * that off, this command reports who would be contacted and touches nothing.
 * That is the correct default for a system that emails real people about real
 * money.
 *
 * THE KILL SWITCH IS HONOURED TWICE
 * Once here, so a manual run is refused, and once in the schedule, so the task
 * is not even registered. Two guards because the consequence of forgetting is
 * an unwanted mailshot, and unwanted mailshots are not retractable.
 */
final class RunLifecycleCampaignCommand extends Command
{
    protected $signature = 'lifecycle:campaign
        {campaign? : One of: '.self::CAMPAIGN_LIST.'}
        {--all : Run every campaign that is enabled, in declaration order}
        {--dry-run : Report who would be contacted without sending anything}
        {--no-dry-run : Send for real, overriding the per-campaign dry_run setting}
        {--limit= : Cap the number of sends this run (defaults to lifecycle.max_sends_per_run)}
        {--force : Run even when lifecycle.enabled is false}';

    protected $description = 'Run a lifecycle email campaign against currently eligible players';

    private const CAMPAIGN_LIST = 'welcome, deposit_nudge, draw_reminder, winback, grade_promotion';

    public function handle(LifecycleCampaignService $service): int
    {
        if (! (bool) config('lifecycle.enabled', false) && ! $this->option('force')) {
            $this->components->error('Lifecycle campaigns are disabled (lifecycle.enabled).');
            $this->line('  Nothing was sent. Set LIFECYCLE_ENABLED=true, or pass --force for a one-off run.');

            // Non-zero, so a scheduler that ignores the guard still reports a
            // failure rather than silently doing nothing every day.
            return self::FAILURE;
        }

        $runAll = (bool) $this->option('all');
        $name = $this->argument('campaign');

        if (! $runAll && (! is_string($name) || $name === '')) {
            $this->components->error('Name a campaign or pass --all.');
            $this->line('  Available: '.self::CAMPAIGN_LIST);

            return self::FAILURE;
        }

        $campaigns = $runAll
            ? LifecycleCampaign::cases()
            : [LifecycleCampaign::tryFrom((string) $name)];

        $dryRunOverride = $this->option('no-dry-run') ? false : null;
        if ($this->option('dry-run')) {
            $dryRunOverride = true;
        }

        $limit = $this->option('limit');
        $limit = is_numeric($limit) ? (int) $limit : null;

        $failed = false;

        foreach ($campaigns as $campaign) {
            if (! $campaign instanceof LifecycleCampaign) {
                $this->components->error('Unknown campaign: '.(string) $name);

                return self::FAILURE;
            }

            $report = $service->run($campaign, $dryRunOverride, $limit);

            $this->render($campaign, $report);

            if (($report['error'] ?? 0) > 0) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function render(LifecycleCampaign $campaign, array $report): void
    {
        $this->newLine();

        if (($report['disabled'] ?? false) === true) {
            $this->components->warn($campaign->label().': lifecycle system disabled.');

            return;
        }

        if (($report['campaign_disabled'] ?? false) === true) {
            $this->components->warn($campaign->label().': campaign not enabled in config.');

            return;
        }

        $headline = ($report['dry_run'] ?? false)
            ? $campaign->label().' — DRY RUN, nothing was sent'
            : $campaign->label().' — sent';

        $this->components->info($headline);

        $this->components->twoColumnDetail('Eligible', (string) ($report['eligible'] ?? 0));
        $this->components->twoColumnDetail('Already sent', (string) ($report['already_sent'] ?? 0));

        if (($report['dry_run'] ?? false) === true) {
            $this->components->twoColumnDetail('Would send', (string) ($report['would_send'] ?? 0));
        } else {
            $this->components->twoColumnDetail('Sent', (string) ($report['sent'] ?? 0));
        }

        // Skips are the interesting part of the report: they explain why the
        // send count is lower than the eligible count.
        $skips = [
            'Suppressed by preference', 'suppressed',
            'Skipped: self-excluded', 'self_excluded',
            'Skipped: no user', 'no_user',
            'Skipped: already claimed', 'already_claimed',
            'Failed', 'error',
        ];

        for ($i = 0; $i < count($skips); $i += 2) {
            $count = (int) ($report[$skips[$i + 1]] ?? 0);
            if ($count > 0) {
                $this->components->twoColumnDetail($skips[$i], (string) $count);
            }
        }

        if (($report['cap_reached'] ?? false) === true) {
            $this->components->warn('Send cap reached — the remaining eligible players were not processed this run.');
        }
    }
}
