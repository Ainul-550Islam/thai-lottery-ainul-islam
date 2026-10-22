<?php

declare(strict_types=1);

namespace App\Console\Commands\Lottery;

use App\Enums\DrawStatus;
use App\Models\Draw;
use App\Services\Draw\ResultSourceAuthorityService;
use App\Services\Draw\SettlementPayoutInvariantService;
use App\Services\ResponsibleGaming\SelfExclusionReleaseGate;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run the Part 62 guards over settled draws and say what they found.
 *
 * This is a spot check, not a repair tool. It reads what settlement already
 * wrote, prints one line per draw per guard, and exits non-zero when a guard
 * raised — which only happens in `enforce` mode, since `log` mode records and
 * moves on by design.
 *
 * SCHEDULING CAVEAT, STATED PLAINLY: registering a command is not the same as
 * running it. Until this command is on a real scheduler in the deployed
 * environment, the guards only ever run when a human types them, and a
 * divergence found late is still a divergence.
 */
final class VerifySettlementGuardsCommand extends Command
{
    protected $signature = 'settlement:verify-guards
        {--draw= : Verify one draw by id}
        {--recent= : How many recently settled draws to walk}
        {--release : Also report accounts sitting in the self-exclusion activation gap}';

    protected $description = 'Check that published results, paid payouts and draw counters still agree';

    private const SETTLED_STATUSES = [
        DrawStatus::ResultPublished->value,
        DrawStatus::Completed->value,
    ];

    public function handle(
        ResultSourceAuthorityService $authority,
        SettlementPayoutInvariantService $payouts,
        SelfExclusionReleaseGate $releaseGate,
    ): int {
        $drawIds = $this->drawIds();
        $failures = 0;

        if ($drawIds === []) {
            $this->info('No settled draws to verify.');
        }

        foreach ($drawIds as $drawId) {
            $guards = [
                'authority' => fn (): array => $authority->verify($drawId),
                'payouts' => fn (): array => $payouts->verify($drawId),
            ];

            foreach ($guards as $label => $run) {
                try {
                    /** @var array{verdict: string, divergences: list<string>} $result */
                    $result = $run();
                } catch (Throwable $e) {
                    $failures++;
                    $this->error(sprintf('draw %d %s: %s', $drawId, $label, $e->getMessage()));

                    continue;
                }

                $line = sprintf('draw %d %s: %s', $drawId, $label, $result['verdict']);

                if ($result['divergences'] !== []) {
                    $this->warn($line.' — '.implode('; ', $result['divergences']));
                } else {
                    $this->line($line);
                }
            }
        }

        if ($this->option('release')) {
            $gap = $releaseGate->accountsInActivationGap();
            $this->info('Accounts in the self-exclusion activation gap: '.count($gap));

            foreach ($gap as $userId) {
                $decision = $releaseGate->decision($userId);
                $this->warn(sprintf(
                    '  user %d: %s (exclusion %s, ends %s)',
                    $userId,
                    $decision['reason'],
                    (string) $decision['exclusion_id'],
                    (string) $decision['ends_at'],
                ));
            }
        }

        if ($failures > 0) {
            $this->error($failures.' guard run(s) raised.');

            return self::FAILURE;
        }

        $this->info('Done. Divergences, if any, are recorded in the audit log.');

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function drawIds(): array
    {
        $one = $this->option('draw');

        if ($one !== null && $one !== '') {
            return [(int) $one];
        }

        $recent = (int) ($this->option('recent') ?: config('settlement-guards.recent_draws', 20));

        return Draw::query()
            ->whereIn('status', self::SETTLED_STATUSES)
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->limit(max(1, $recent))
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
