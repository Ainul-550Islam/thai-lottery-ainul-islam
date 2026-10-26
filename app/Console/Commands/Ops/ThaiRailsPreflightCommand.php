<?php

declare(strict_types=1);

namespace App\Console\Commands\Ops;

use App\Support\Ops\ThaiRailsChecks;
use Illuminate\Console\Command;

/**
 * `php artisan thai-rails:preflight` — check the enabled Thai payment rails
 * without printing credentials.
 *
 * This command is now a thin wrapper: the questions live in
 * App\Support\Ops\ThaiRailsChecks, which ProductionPreflight and the test suite
 * call directly, so a rule can never be true in one place and false in
 * another. The output lines and the exit code are unchanged.
 */
final class ThaiRailsPreflightCommand extends Command
{
    protected $signature = 'thai-rails:preflight';

    protected $description = 'Check enabled Thai payment rails without printing credentials.';

    public function handle(ThaiRailsChecks $checks): int
    {
        $failures = [];

        foreach ($checks->run() as $result) {
            $this->line($result->line());

            foreach ($result->failingLanes() as $lane) {
                $failures[] = $lane;
            }
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
