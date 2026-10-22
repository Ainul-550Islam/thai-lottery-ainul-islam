<?php

declare(strict_types=1);

namespace App\Console\Commands\Settlement;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only periodic inventory of completed draws requiring settlement evidence review. */
final class VerifySettlementGuardsCommand extends Command
{
    protected $signature = 'settlement:verify-guards';
    protected $description = 'Verify that completed draws are visible to settlement guard review';

    public function handle(): int
    {
        $count = Schema::hasTable('settlement_runs')
            ? DB::table('settlement_runs')->where('status', 'completed')->count()
            : 0;
        if ($count === 0) {
            $this->info('No settled draws to verify.');
            return self::SUCCESS;
        }
        $this->line(sprintf('%d completed draw(s) are present for settlement guard review.', $count));
        return self::SUCCESS;
    }
}
