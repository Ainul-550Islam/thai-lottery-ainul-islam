<?php

declare(strict_types=1);

namespace App\Console\Commands\Conversions;

use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionStatus;
use App\Models\WalletConversion;
use App\Models\WalletConversionQuote;
use App\Services\Wallet\Conversion\ConversionFeeCalculator;
use App\Services\Wallet\Conversion\ConversionHistoryService;
use App\Services\Wallet\Conversion\ConversionLimitService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one screen an operator opens when something about conversions is wrong.
 *
 * WHAT IT ANSWERS, IN THE ORDER QUESTIONS ARE ACTUALLY ASKED
 *   Is the lane open?           — the master switch and each direction.
 *   Is money moving?            — volumes and counts by status for the window.
 *   Are members being refused?  — refusals by code, which is the difference
 *                                 between "quiet" and "broken".
 *   Is anything stuck?          — conversions that are neither completed nor
 *                                 terminally failed, oldest first, because a
 *                                 row processing for six hours is the symptom
 *                                 an operator is usually hunting.
 *   Is the price right?         — the published rate the engine would charge
 *                                 for each direction right now.
 *
 * WHY IT DOES NOT TOUCH ANYTHING
 * Every diagnostic command on a money system should be safe to run during an
 * incident, on production, by the most junior person on the rota. This one
 * reads. It has no flags that write, and nothing it prints changes state.
 */
class ConversionStatusCommand extends Command
{
    protected $signature = 'conversions:status
                            {--days=7 : How many days of history to summarise}
                            {--json : Emit the report as JSON}';

    protected $description = 'Report the state of the Cash <-> Win conversion lane';

    public function handle(ConversionHistoryService $history, ConversionLimitService $limits): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = Carbon::now()->subDays($days);

        $report = [
            'enabled' => (bool) config('conversions.enabled', true),
            'directions' => $this->directionState(),
            'window_days' => $days,
            'counts' => $this->counts($since),
            'volume' => $this->volume($since),
            'refusals' => $history->refusalCounts($days),
            'stuck' => $this->stuck(),
            'open_quotes' => $this->openQuotes(),
            'rates' => $this->rates(),
            'ledger' => $this->ledgerTotals($since),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info('Wallet conversion lane');
        $this->components->twoColumnDetail('Feature enabled', $report['enabled'] ? 'yes' : 'NO');

        foreach ($report['directions'] as $direction => $state) {
            $this->components->twoColumnDetail('  '.$direction, $state ? 'open' : 'CLOSED');
        }

        $this->newLine();
        $this->components->info(sprintf('Conversions in the last %d day(s)', $days));

        foreach ($report['counts'] as $status => $count) {
            $this->components->twoColumnDetail('  '.$status, (string) $count);
        }

        $this->components->twoColumnDetail('  amount converted', $report['volume']['amount_in'].' THB');
        $this->components->twoColumnDetail('  fees charged', $report['volume']['fee_amount'].' THB');
        $this->components->twoColumnDetail('  amount delivered', $report['volume']['amount_out'].' THB');

        if ($report['refusals'] !== []) {
            $this->newLine();
            $this->components->info('Refusals by code');
            foreach ($report['refusals'] as $code => $count) {
                $this->components->twoColumnDetail('  '.$code, (string) $count);
            }
        }

        if ($report['stuck'] !== []) {
            $this->newLine();
            $this->components->warn(sprintf('%d conversion(s) are not in a terminal state', count($report['stuck'])));
            $this->table(
                ['Reference', 'Status', 'Amount', 'Created'],
                array_map(static fn (array $row): array => [
                    $row['reference'],
                    $row['status'],
                    $row['amount_in'],
                    $row['created_at'],
                ], $report['stuck']),
            );
        }

        $this->newLine();
        $this->components->info('Published rates');
        foreach ($report['rates'] as $direction => $rate) {
            $this->components->twoColumnDetail('  '.$direction, $rate === null ? 'NO RULE — conversions refused' : $rate.'%');
        }

        $this->newLine();
        $this->components->info('Ledger in the window');
        $this->components->twoColumnDetail('  fee revenue (4100)', $report['ledger']['fee_revenue']);
        $this->components->twoColumnDetail('  open quotes', (string) $report['open_quotes']);

        return self::SUCCESS;
    }

    /**
     * @return array<string, bool>
     */
    private function directionState(): array
    {
        $state = [];

        foreach (WalletConversionDirection::cases() as $direction) {
            $state[$direction->value] = (bool) config('conversions.directions.'.$direction->value.'.enabled', true);
        }

        return $state;
    }

    /**
     * @return array<string, int>
     */
    private function counts(Carbon $since): array
    {
        $rows = WalletConversion::query()
            ->where('created_at', '>=', $since)
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        $counts = [];

        foreach (WalletConversionStatus::cases() as $status) {
            $counts[$status->value] = (int) ($rows[$status->value] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array{amount_in: string, fee_amount: string, amount_out: string}
     */
    private function volume(Carbon $since): array
    {
        $row = WalletConversion::query()
            ->where('created_at', '>=', $since)
            ->where('status', '=', WalletConversionStatus::Completed->value)
            ->selectRaw('COALESCE(SUM(amount_in), 0) as amount_in, COALESCE(SUM(fee_amount), 0) as fee_amount, COALESCE(SUM(amount_out), 0) as amount_out')
            ->first();

        return [
            'amount_in' => bcadd((string) ($row->amount_in ?? '0'), '0', 2),
            'fee_amount' => bcadd((string) ($row->fee_amount ?? '0'), '0', 2),
            'amount_out' => bcadd((string) ($row->amount_out ?? '0'), '0', 2),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stuck(): array
    {
        return WalletConversion::query()
            ->whereIn('status', [
                WalletConversionStatus::Pending->value,
                WalletConversionStatus::Processing->value,
            ])
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->map(static fn (WalletConversion $conversion): array => [
                'reference' => (string) $conversion->reference,
                'status' => $conversion->status->value,
                'amount_in' => bcadd((string) $conversion->amount_in, '0', 2),
                'created_at' => $conversion->created_at?->format('Y-m-d H:i') ?? '-',
            ])
            ->all();
    }

    private function openQuotes(): int
    {
        return (int) WalletConversionQuote::query()
            ->where('status', '=', WalletConversionStatus::Quoted->value)
            ->count();
    }

    /**
     * @return array<string, string|null>
     */
    private function rates(): array
    {
        $calculator = app(ConversionFeeCalculator::class);
        $rates = [];

        foreach (WalletConversionDirection::cases() as $direction) {
            $rates[$direction->value] = $calculator->publishedPercentage($direction);
        }

        return $rates;
    }

    /**
     * @return array{fee_revenue: string}
     */
    private function ledgerTotals(Carbon $since): array
    {
        $sum = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.ledger_account_id')
            ->where('ledger_accounts.code', 'like', '4100%')
            ->where('ledger_entries.reference_type', '=', 'wallet_conversion')
            ->where('ledger_entries.posted_at', '>=', $since)
            ->selectRaw("COALESCE(SUM(CASE WHEN ledger_entries.type = 'credit' THEN ledger_entries.amount ELSE -ledger_entries.amount END), 0) as net")
            ->first();

        return ['fee_revenue' => bcadd((string) ($sum->net ?? '0'), '0', 2)];
    }
}
