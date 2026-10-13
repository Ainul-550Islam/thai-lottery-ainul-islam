<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Enums\WalletConversionStatus;
use App\Models\WalletConversion;
use App\Support\Admin\AdminAccess;
use App\Support\Admin\AdminFormat;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Is the conversion lane healthy right now?
 *
 * WHY THESE FOUR NUMBERS
 * A dashboard is read in five seconds, so it gets the four figures that would
 * make an operator open the list: volume today (is it being used), fees today
 * (is it earning), refusals today (is it working for the people using it), and
 * anything not in a terminal state (is something stuck). Everything else — the
 * per-code refusal breakdown, the reconciliation detail — belongs on the screen
 * an operator goes to after this one tells them to look.
 *
 * WHY THE FIGURES ARE SUMMED WITH BCMATH
 * Money that is summed into floats acquires artefacts; a "1.0000000000000002"
 * on a dashboard destroys trust in every other number on the page. The sums come
 * out of the database as strings and never leave that domain.
 *
 * WHY "STUCK" IS THE STAT WITH A COLOUR
 * Volume and fees are informational — they describe normal business. Stuck work
 * is the one figure that is actionable, so it is the one that turns red, and it
 * stays visible even at zero because a missing figure is not reassuring, it is
 * ambiguous.
 */
class ConversionVolumeWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return AdminAccess::currentAny([
            AdminAccess::VIEW_TRANSACTION_HISTORY,
            AdminAccess::VIEW_FINANCIAL_REPORTS,
            AdminAccess::MANAGE_WALLET,
        ]);
    }

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $today = Carbon::today();

        $todayRow = WalletConversion::query()
            ->where('created_at', '>=', $today)
            ->where('status', '=', WalletConversionStatus::Completed->value)
            ->selectRaw('COUNT(*) as conversions, COALESCE(SUM(amount_in), 0) as volume, COALESCE(SUM(fee_amount), 0) as fees')
            ->first();

        $refusals = WalletConversion::query()
            ->where('created_at', '>=', $today)
            ->where('status', '=', WalletConversionStatus::Failed->value)
            ->count();

        $stuck = WalletConversion::query()
            ->whereIn('status', [
                WalletConversionStatus::Pending->value,
                WalletConversionStatus::Processing->value,
            ])
            ->count();

        $currency = (string) config('conversions.currency', 'THB');

        return [
            Stat::make('Conversions today', AdminFormat::count((int) ($todayRow->conversions ?? 0)))
                ->description('Completed Cash ⇄ Win movements')
                ->icon('heroicon-o-arrows-right-left'),

            Stat::make('Volume today', AdminFormat::money((string) ($todayRow->volume ?? '0'), $currency))
                ->description('Gross amount converted')
                ->icon('heroicon-o-banknotes'),

            Stat::make('Fees today', AdminFormat::money((string) ($todayRow->fees ?? '0'), $currency))
                ->description('Recognised in account 4100')
                ->icon('heroicon-o-receipt-percent'),

            Stat::make($stuck > 0 ? 'Awaiting settlement' : 'Awaiting settlement', (string) $stuck)
                ->description($stuck > 0
                    ? 'Investigate: a movement has not reached a terminal state'
                    : sprintf('%d refused today', $refusals))
                ->color($stuck > 0 ? 'danger' : 'success')
                ->icon('heroicon-o-clock'),
        ];
    }
}
