<?php

declare(strict_types=1);

namespace App\Console\Commands\Risk;

use App\Enums\DrawStatus;
use App\Exceptions\RiskConfigurationException;
use App\Models\Draw;
use App\Services\Risk\NumberLimitProvisioningService;
use Illuminate\Console\Command;

/**
 * Create the per-number capacity rows a draw needs before it can take bets.
 *
 * Without these rows NumberLimitEngine refuses every bet, because there is no row
 * to lock and therefore no way to bound exposure on a number. Nothing in the
 * codebase created them, so no bet could be placed at all. This command is the
 * missing provisioning step.
 *
 * Idempotent: re-running re-asserts the configured ceilings and never resets the
 * accumulated counters on a live draw.
 */
final class ProvisionNumberLimits extends Command
{
    protected $signature = 'risk:provision-number-limits
        {draw? : Draw id or draw number. Omit with --open to do every open draw}
        {--open : Provision every draw currently open for betting}
        {--check : Report provisioning state and write nothing}
        {--json : Machine readable output}';

    protected $description = 'Provision number_limits capacity rows for a draw so bets can be accepted';

    public function handle(NumberLimitProvisioningService $service): int
    {
        $draws = $this->resolveDraws();

        if ($draws === []) {
            $this->components->error(
                'No draw matched. Pass a draw id or draw number, or use --open to provision every open draw.'
            );

            return self::FAILURE;
        }

        $results = [];

        foreach ($draws as $draw) {
            if ((bool) $this->option('check')) {
                $complete = $service->isFullyProvisioned($draw);

                $results[] = [
                    'draw_id' => (int) $draw->getKey(),
                    'draw_number' => (string) $draw->draw_number,
                    'fully_provisioned' => $complete,
                ];

                if (! (bool) $this->option('json')) {
                    $this->components->twoColumnDetail(
                        sprintf('Draw %s (#%d)', (string) $draw->draw_number, (int) $draw->getKey()),
                        $complete ? '<fg=green>provisioned</>' : '<fg=red>INCOMPLETE - bets will be refused</>',
                    );
                }

                continue;
            }

            try {
                $report = $service->provision($draw);
            } catch (RiskConfigurationException $exception) {
                $this->components->error($exception->getMessage());
                $this->line('');
                $this->components->warn(
                    'Set RISK_MAX_STAKE_PER_NUMBER to the aggregate stake ceiling for one number '
                    .'on one draw. It has no default on purpose: it is a real-money ceiling, and '
                    .'inventing one would look configured while expressing nobody\'s risk appetite.'
                );

                return self::FAILURE;
            }

            $results[] = $report;

            if (! (bool) $this->option('json')) {
                $this->components->twoColumnDetail(
                    sprintf('Draw %s (#%d)', $report['draw_number'], $report['draw_id']),
                    sprintf('%d rows', $report['total']),
                );

                foreach ($report['written'] as $betType => $count) {
                    $this->components->twoColumnDetail('  '.$betType, (string) $count);
                }

                $this->components->twoColumnDetail('  stake ceiling', $report['stake_ceiling']);
                $this->components->twoColumnDetail('  payout ceiling', $report['payout_ceiling']);
            }
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<Draw>
     */
    private function resolveDraws(): array
    {
        if ((bool) $this->option('open')) {
            /** @var list<Draw> $open */
            $open = Draw::query()
                ->where('status', DrawStatus::Open)
                ->orderBy('scheduled_at')
                ->get()
                ->all();

            return $open;
        }

        $reference = $this->argument('draw');

        if ($reference === null || $reference === '') {
            return [];
        }

        $reference = (string) $reference;

        $draw = ctype_digit($reference)
            ? Draw::query()->whereKey((int) $reference)->first()
            : null;

        $draw ??= Draw::query()->where('draw_number', $reference)->first();

        return $draw instanceof Draw ? [$draw] : [];
    }
}
