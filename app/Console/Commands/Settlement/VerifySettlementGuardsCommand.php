<?php

declare(strict_types=1);

namespace App\Console\Commands\Settlement;

use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Services\Draw\SettlementPayoutInvariantService;
use Illuminate\Console\Command;

/**
 * Part 62's settlement-invariant guard, as an operator command.
 *
 * ============================================================================
 * WHY THIS COMMAND EXISTS
 * ============================================================================
 * SettlementPayoutInvariantService (Part 62) can prove, for one settled draw,
 * that the published result, the paid payouts and the draw's own counters
 * still agree. Part 63's schedule measurement then showed the guard was
 * invoked by NOTHING: it was not scheduled, and there was not even a command
 * a human could run. config/operations-readiness.php therefore listed
 * `settlement:verify-guards` as a required scheduled command that did not
 * exist — a guard nobody runs is indistinguishable from a guard that does
 * not exist.
 *
 * This command is the missing verb. bootstrap/app.php schedules it daily;
 * ScheduleCoverageService inventories it; OperationsReadinessReportTest and
 * SettlementGuardsScheduledTest pin both facts.
 *
 * WHAT IT DOES AND DOES NOT DO
 * It is read-only, like the service under it: it never repairs a result,
 * reverses a payout or moves a counter. It verifies every draw in a settled
 * state (result published or completed — the service's own settled set),
 * reports each divergence it finds, and exits non-zero when any settled draw
 * diverges, so the scheduler's log and any wrapper around `schedule:run` can
 * see the failure without parsing prose.
 */
final class VerifySettlementGuardsCommand extends Command
{
    protected $signature = 'settlement:verify-guards';

    protected $description = 'Check published results, paid payouts and draw counters still agree';

    public function handle(SettlementPayoutInvariantService $invariants): int
    {
        $draws = Draw::query()
            ->whereIn('status', [
                DrawStatus::ResultPublished->value,
                DrawStatus::Completed->value,
            ])
            ->orderBy('id')
            ->get();

        if ($draws->isEmpty()) {
            $this->info('No settled draws to verify.');

            return self::SUCCESS;
        }

        $diverged = 0;

        foreach ($draws as $draw) {
            $verdict = $invariants->verify((int) $draw->getKey());
            $divergences = array_values((array) ($verdict['divergences'] ?? []));

            if ($divergences === []) {
                $this->line(sprintf(
                    'Draw %s (#%d): %s.',
                    (string) $draw->draw_number,
                    (int) $draw->getKey(),
                    (string) ($verdict['verdict'] ?? 'verified'),
                ));

                continue;
            }

            $diverged++;

            $this->error(sprintf(
                'Draw %s (#%d): %d divergence(s): %s',
                (string) $draw->draw_number,
                (int) $draw->getKey(),
                count($divergences),
                implode('; ', array_map('strval', $divergences)),
            ));
        }

        if ($diverged > 0) {
            $this->error(sprintf(
                'Settlement guard found divergences in %d of %d settled draw(s).',
                $diverged,
                $draws->count(),
            ));

            return self::FAILURE;
        }

        $this->info(sprintf('Settlement guard verified %d settled draw(s); no divergences.', $draws->count()));

        return self::SUCCESS;
    }
}
