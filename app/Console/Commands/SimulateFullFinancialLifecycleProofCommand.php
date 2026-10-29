<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Never certify a synthetic lifecycle whose legacy payout path is unsafe. */
class SimulateFullFinancialLifecycleProofCommand extends Command
{
    protected $signature = 'fintech:verify-full-lifecycle';

    /** @var array<int, string> */
    protected $aliases = ['test:financial-lifecycle-proof'];

    protected $description = 'Report whether the legacy synthetic financial lifecycle proof is available.';

    public function handle(): int
    {
        if (! $this->laravel->environment('testing')) {
            $this->error('Refusing to run the synthetic financial lifecycle outside the testing environment; nothing was written.');

            return self::FAILURE;
        }

        $this->error('The legacy financial lifecycle proof is unavailable until LotteryEntry settlement is safely migrated; nothing was written.');

        return self::FAILURE;
    }
}
