<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Draw;
use App\Models\User;
use App\Services\Lottery\GloFrozenWinnerService;
use App\Services\Lottery\GloTicketFreezeService;
use App\Support\Admin\AdminAccess;
use Illuminate\Console\Command;

/**
 * GLO-12: detect frozen tickets that won and open payment holds.
 *
 * NEVER pays: creates GloPrizePaymentHold + claim hold + public announcement
 * only. Idempotent: re-running finds existing holds (holds_existing) and does
 * not duplicate. Expired freezes are not treated as winners-for-payment; a
 * separate hold-clear path may return a claim to Eligible, but money still
 * requires full approval + pay().
 *
 *   glo:process-frozen-winners                 # every draw with a result
 *   glo:process-frozen-winners --draw=123
 *   glo:process-frozen-winners --dry-run
 *   glo:process-frozen-winners --json
 *   glo:process-frozen-winners --actor=admin@example.com
 *
 * Exit code is 0 even when no frozen winners exist (empty success).
 */
class GloProcessFrozenWinners extends Command
{
    protected $signature = 'glo:process-frozen-winners
        {--draw= : Restrict to a single draw id}
        {--dry-run : Report what would be held without writing}
        {--json : Emit machine-readable JSON only}
        {--actor= : Optional operator email for audit attribution}';

    protected $description = 'Scan draws for frozen winning GLO tickets and open payment holds (never pays)';

    public function handle(GloFrozenWinnerService $service, GloTicketFreezeService $freezes): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $asJson = (bool) $this->option('json');
        $actor = $this->resolveActor();

        $drawOption = $this->option('draw');

        if ($drawOption !== null && $drawOption !== '') {
            $drawIds = [(int) $drawOption];
        } else {
            $drawIds = Draw::query()
                ->whereHas('result')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $reports = [];
        $holdCreated = 0;
        $holdExisting = 0;
        $claimsHeld = 0;

        foreach ($drawIds as $drawId) {
            $report = $service->processDraw($drawId, $dryRun, $actor);
            $reports[] = $report;
            $holdCreated += (int) $report['holds_created'];
            $holdExisting += (int) $report['holds_existing'];
            $claimsHeld += (int) $report['claims_put_on_hold'];
        }

        // Optional expiry sweep is intentionally NOT folded in — expiry is a
        // separate audited lifecycle (glo:expire-freezes lives on freeze-ticket
        // workflow). Documented here so operators know the pairing.
        unset($freezes);

        $summary = [
            'command' => 'glo:process-frozen-winners',
            'dry_run' => $dryRun,
            'draws_scanned' => count($drawIds),
            'holds_created' => $holdCreated,
            'holds_existing' => $holdExisting,
            'claims_put_on_hold' => $claimsHeld,
            'paid' => 0,
            'reports' => $reports,
        ];

        if ($asJson) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Scanned %d draw(s): %d hold(s) created, %d existing, %d claim(s) held, paid=0 (never pays).',
                $summary['draws_scanned'],
                $holdCreated,
                $holdExisting,
                $claimsHeld,
            ));

            foreach ($reports as $report) {
                foreach ($report['details'] ?? [] as $detail) {
                    $line = sprintf(
                        '  draw=%s ticket=%s outcome=%s',
                        $report['draw_id'],
                        $detail['ticket_reference'] ?? '?',
                        $detail['outcome'] ?? '?',
                    );

                    if (isset($detail['hold_reference'])) {
                        $line .= ' hold='.$detail['hold_reference'];
                    }

                    if (isset($detail['winning_category'])) {
                        $line .= ' category='.$detail['winning_category'];
                    }

                    $this->line($line);
                }
            }
        }

        return self::SUCCESS;
    }

    private function resolveActor(): ?User
    {
        $email = (string) $this->option('actor');

        if ($email === '') {
            return null;
        }

        $actor = User::query()->where('email', $email)->first();

        if ($actor === null || ! $actor->isActive()) {
            return null;
        }

        if (! AdminAccess::allows($actor, AdminAccess::REVIEW_GLO_FREEZES)
            && ! AdminAccess::allows($actor, AdminAccess::MANAGE_GLO_PRIZE_CLAIMS)) {
            return null;
        }

        return $actor;
    }
}
