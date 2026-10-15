<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Services\Operations\FrontendBuildService;
use Illuminate\Console\Command;

/**
 * Report whether the frontend build exists and covers every declared input.
 *
 * It does not build anything. A missing manifest means every page whose layout
 * calls `@vite` renders a 500, and the right response is to run the build and
 * find out why the artifact was absent — not to have a checker paper over it.
 *
 * Exit codes: 0 when the manifest exists and covers every declared input,
 * 1 otherwise.
 */
final class VerifyFrontendBuildCommand extends Command
{
    protected $signature = 'operations:verify-frontend-build';

    protected $description = 'Check that public/build/manifest.json exists and covers every vite input';

    public function handle(FrontendBuildService $build): int
    {
        $report = $build->report();

        if (! $report['exists']) {
            $this->error('public/build/manifest.json is missing.');
            $this->line('Every page whose layout calls @vite will fail to render until it exists.');
            $this->line('Build it with: npm ci && npm run build');

            return self::FAILURE;
        }

        $this->info('public/build/manifest.json: '.$report['entries'].' entries');

        if ($report['inputs'] === []) {
            $this->warn('No inputs were found in vite.config.js, so coverage cannot be checked.');

            return self::FAILURE;
        }

        $this->line('declared inputs: '.count($report['inputs']));

        if ($report['missing'] !== []) {
            foreach ($report['missing'] as $input) {
                $this->error('declared input has no manifest entry: '.$input);
            }

            $this->line('Rebuild with: npm run build');

            return self::FAILURE;
        }

        if ($report['stale']) {
            $this->warn(
                'the build looks stale: '.$report['newest_source'].' is newer than the manifest',
            );
        }

        $this->info('Every declared input has a manifest entry.');

        return self::SUCCESS;
    }
}
