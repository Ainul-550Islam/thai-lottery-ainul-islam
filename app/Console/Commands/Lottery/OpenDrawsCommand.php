<?php

declare(strict_types=1);

namespace App\Console\Commands\Lottery;

use App\Exceptions\RiskConfigurationException;
use App\Models\Draw;
use App\Services\Draw\DrawLifecycleService;
use App\Services\Risk\NumberLimitProvisioningService;

/**
 * Opens betting on draws whose planned window has started.
 *
 * A draw is opened only if its betting window has begun AND has not already ended.
 * A draw provisioned late - after its own cut-off - is deliberately left in Draft:
 * opening it would produce a draw that advertises itself as open while
 * BetValidationService rejects every bet against it on the betting_close_at check,
 * which is a worse outcome than a draw that never opened.
 *
 * The transition itself is DrawLifecycleService::open(), which locks the row and
 * re-reads the state inside the lock, so two concurrent runs cannot both open the
 * same draw - the second is refused by the transition table and reported as a
 * failure for that draw only.
 *
 * CAPACITY IS PROVISIONED BEFORE THE DRAW OPENS, NOT AFTER
 *
 * NumberLimitEngine refuses any bet with no number_limits row for its
 * (draw_id, bet_type, number) triple, so an open draw without capacity rows is
 * exactly the failure this command's own rule above exists to prevent: a draw
 * that advertises itself as open while every bet against it is rejected. The rows
 * are therefore written before the transition.
 *
 * Provisioning is best effort, and that is a deliberate choice rather than a
 * weak one. The aggregate stake ceiling it needs has no default, because a
 * silently invented real-money ceiling is worse than an absent one. Treating an
 * unconfigured ceiling as fatal here would mean an existing deployment that
 * upgrades without setting RISK_MAX_STAKE_PER_NUMBER stops opening draws
 * altogether - trading an unbettable draw for no draws at all, which is the
 * larger outage. So the draw still opens, and the operator gets a warning naming
 * the exact variable. Use `risk:provision-number-limits --check` to detect open
 * draws that are missing capacity.
 */
final class OpenDrawsCommand extends LotteryAutomationCommand
{
    protected $signature = 'lottery:open-draws
        {--dry-run : List what would be opened without writing anything}
        {--force : Run even when automation is disabled}';

    protected $description = 'Open betting on draws whose planned betting window has started';

    protected function activity(): string
    {
        return 'open';
    }

    public function handle(): int
    {
        if (! $this->assertAutomationAllowed() || ! $this->assertNotInTransaction()) {
            return self::SUCCESS;
        }

        $lifecycle = app(DrawLifecycleService::class);
        $capacity = app(NumberLimitProvisioningService::class);

        $this->eachDueDraw(
            $this->schedule->dueToOpen(),
            function (Draw $draw) use ($lifecycle, $capacity): string {
                $rows = null;

                try {
                    $rows = $capacity->provision($draw)['total'];
                } catch (RiskConfigurationException $exception) {
                    $this->components->warn(sprintf(
                        'Draw %s opened WITHOUT number-limit capacity: %s Until '
                        .'RISK_MAX_STAKE_PER_NUMBER is set, every bet on this draw will be '
                        .'refused. Run risk:provision-number-limits once it is configured.',
                        (string) $draw->draw_number,
                        $exception->getMessage(),
                    ));
                }

                $lifecycle->open($draw, [
                    'stage' => 'automation',
                    'command' => 'lottery:open-draws',
                    'number_limit_rows' => $rows,
                ]);

                return $rows === null
                    ? sprintf(
                        'open (betting closes %s, NO capacity rows - bets will be refused)',
                        (string) $draw->betting_close_at,
                    )
                    : sprintf(
                        'open (betting closes %s, %d capacity rows)',
                        (string) $draw->betting_close_at,
                        $rows,
                    );
            },
        );

        return $this->exitCode();
    }
}
