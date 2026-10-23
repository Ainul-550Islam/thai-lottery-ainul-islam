<?php

declare(strict_types=1);

namespace App\Console\Commands\Settlement;

use App\Console\Commands\Lottery\VerifySettlementGuardsCommand as LotteryVerifySettlementGuardsCommand;
use App\Services\Draw\ResultSourceAuthorityService;
use App\Services\Draw\SettlementPayoutInvariantService;
use App\Services\ResponsibleGaming\SelfExclusionReleaseGate;
use Illuminate\Console\Command;

/** Read-only periodic inventory and verification of settled draws against settlement guards. */
final class VerifySettlementGuardsCommand extends Command
{
    protected $signature = 'settlement:verify-guards
        {--draw= : Verify one draw by id}
        {--recent= : How many recently settled draws to walk}
        {--release : Also report accounts sitting in the self-exclusion activation gap}';

    protected $description = 'Check that published results, paid payouts and draw counters still agree';

    public function handle(
        ResultSourceAuthorityService $authority,
        SettlementPayoutInvariantService $payouts,
        SelfExclusionReleaseGate $releaseGate,
    ): int {
        $delegate = new LotteryVerifySettlementGuardsCommand;
        $delegate->setLaravel($this->getLaravel());
        $delegate->setInput($this->input);
        $delegate->setOutput($this->output);

        return $delegate->handle($authority, $payouts, $releaseGate);
    }
}
