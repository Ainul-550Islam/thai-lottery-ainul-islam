<?php

declare(strict_types=1);

namespace App\Jobs\Betting;

use App\Enums\BetSlipStatus;
use App\Models\BetSlip;
use App\Services\BetSlip\BetSlipService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Sweeps drafts whose clock ran out.
 *
 * WHY A SWEEP AT ALL, WHEN THE READ PATH ALREADY CHECKS THE CLOCK
 * Because a read path only fixes the rows somebody looks at. A member who
 * abandons a slip leaves a draft that the unique index treats as their only
 * draft — which is correct (they get the same slip back when they return) but
 * means "when was this slip abandoned" has to be answerable operationally, and an
 * expired draft is also what stops a very old basket from being confirmed after a
 * long absence. The sweep makes the status column agree with the clock for
 * everybody, not only for the people currently looking.
 *
 * It writes ONLY the status column, through BetSlipService::expire(), which is the
 * same writer the confirmation path uses.
 */
final class ExpireBetSlipsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly ?string $at = null,
        public readonly int $chunk = 200,
    ) {
    }

    public function handle(BetSlipService $slips): void
    {
        $now = $this->at === null ? Carbon::now() : Carbon::parse($this->at);

        BetSlip::query()
            ->expiredByTime($now)
            ->orderBy('id')
            ->chunkById(max(1, $this->chunk), function ($drafts) use ($slips, $now): void {
                foreach ($drafts as $draft) {
                    if ($draft->status === BetSlipStatus::Draft) {
                        $slips->expire($draft, $now);
                    }
                }
            });
    }

    public function tags(): array
    {
        return ['bet-slip-expiry'];
    }
}
