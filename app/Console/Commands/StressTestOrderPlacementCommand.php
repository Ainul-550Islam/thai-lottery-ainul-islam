<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** The retired legacy benchmark must not manufacture balances or claim success. */
class StressTestOrderPlacementCommand extends Command
{
    protected $signature = 'benchmark:order-placement {concurrency=100 : Number of simulated orders} {users=10 : Distinct users}';

    protected $description = 'Report whether the legacy order placement benchmark is available.';

    public function handle(): int
    {
        $this->error('Legacy lottery-entry sales are unavailable pending an audited migration; no benchmark data was created.');

        return self::FAILURE;
    }
}
