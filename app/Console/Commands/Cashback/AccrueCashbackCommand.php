<?php

declare(strict_types=1);

namespace App\Console\Commands\Cashback;

use App\Enums\BetStatus;
use App\Jobs\Cashback\AccrueCashbackJob;
use App\Models\Bet;
use App\Models\User;
use App\Services\Cashback\CashbackAccrualService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

final class AccrueCashbackCommand extends Command
{
    protected $signature = 'cashback:accrue
        {--period=daily : Accrual period (daily or weekly)}
        {--date= : Reference date YYYY-MM-DD (defaults to previous completed period)}
        {--dry-run : Preview accruals without writing to the database}';

    protected $description = 'Accrue real-money net-loss cashback for settled bets in the target period.';

    public function handle(CashbackAccrualService $accrualService): int
    {
        $period = strtolower((string) $this->option('period'));
        if (! in_array($period, ['daily', 'weekly'], true)) {
            $this->error('Invalid --period option. Supported values: daily, weekly.');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        [$periodStart, $periodEnd] = $this->resolveWindow($period, $this->option('date'));

        if (! $dryRun) {
            AccrueCashbackJob::dispatch(
                period: $period,
                periodStartIso: $periodStart->toIso8601String(),
                periodEndIso: $periodEnd->toIso8601String(),
            );

            $this->info(sprintf(
                '[DISPATCHED] AccrueCashbackJob queued for %s window %s -> %s.',
                $period,
                $periodStart->toIso8601String(),
                $periodEnd->toIso8601String(),
            ));

            return self::SUCCESS;
        }

        $processed = 0;
        $totalAccrued = '0.00';

        Bet::query()
            ->whereIn('status', [BetStatus::Won->value, BetStatus::Lost->value])
            ->where(function ($query) use ($periodStart, $periodEnd): void {
                $query
                    ->whereBetween('won_at', [$periodStart, $periodEnd])
                    ->orWhereBetween('updated_at', [$periodStart, $periodEnd]);
            })
            ->select('user_id')
            ->distinct()
            ->orderBy('user_id')
            ->chunk(max(1, (int) config('rewards.cashback.accrual_chunk_size', 200)), function ($rows) use ($accrualService, $periodStart, $periodEnd, $period, &$processed, &$totalAccrued): void {
                $ids = $rows->pluck('user_id')->map(static fn ($id): int => (int) $id)->all();
                $members = User::query()->whereIn('id', $ids)->get()->keyBy('id');

                foreach ($ids as $memberId) {
                    $member = $members->get($memberId);
                    if (! $member instanceof User) {
                        continue;
                    }

                    $accrual = $accrualService->accrueForMember($member, $periodStart, $periodEnd, $period, true);
                    if ($accrual !== null) {
                        $processed++;
                        $totalAccrued = bcadd($totalAccrued, (string) $accrual->accrual_amount, 2);
                    }
                }
            });

        $this->info(sprintf(
            '[DRY-RUN] Cashback %s window %s -> %s: %d accruals (%s %s).',
            $period,
            $periodStart->toIso8601String(),
            $periodEnd->toIso8601String(),
            $processed,
            $totalAccrued,
            strtoupper((string) config('rewards.currency', 'THB')),
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveWindow(string $period, mixed $dateOption): array
    {
        $anchor = is_string($dateOption) && $dateOption !== ''
            ? Carbon::parse($dateOption)
            : ($period === 'weekly' ? Carbon::now()->subWeek() : Carbon::now()->subDay());

        if ($period === 'weekly') {
            return [$anchor->copy()->startOfWeek(), $anchor->copy()->endOfWeek()];
        }

        return [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()];
    }
}
