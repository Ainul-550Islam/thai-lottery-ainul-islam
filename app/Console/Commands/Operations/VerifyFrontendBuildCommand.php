<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Services\Operations\FrontendBuildService;
use Illuminate\Console\Command;

final class VerifyFrontendBuildCommand extends Command
{
    protected $signature = 'operations:verify-frontend-build';
    protected $description = 'Verify the production Vite manifest covers all declared inputs';

    public function handle(FrontendBuildService $build): int
    {
        $report = $build->report();
        $this->line('public/build/manifest.json: '.($report['exists'] ? 'present' : 'missing'));
        if (! $report['exists']) {
            $this->error('Run npm ci && npm run build.');
            return self::FAILURE;
        }
        if ($report['missing'] !== []) {
            $this->error('Missing manifest entries: '.implode(', ', $report['missing']));
            return self::FAILURE;
        }
        $this->info('Every declared input has a manifest entry.');
        return self::SUCCESS;
    }
}
