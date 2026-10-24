<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionRepositoryContract;
use App\DTOs\Wallet\ConversionHistoryFilterData;
use App\Enums\Currency;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionStatus;
use App\Models\WalletConversion;
use App\Models\WalletConversionAudit;
use App\Support\Wallet\ConversionCurrency;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

final class ConversionHistoryService
{
    public function __construct(
        private readonly ConversionRepositoryContract $repository,
    ) {}

    public function history(ConversionHistoryFilterData $filter): LengthAwarePaginator
    {
        return $this->repository->paginate($filter);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(int $userId, ?Currency $currency = null): array
    {
        return $this->summaryForUser($userId, $currency);
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForUser(int $userId, ?Currency $currency = null): array
    {
        $selectedCurrency = $currency ?? ConversionCurrency::configured();
        $counts = $this->repository->statusCountsForUser($userId);
        $total = array_sum($counts);

        $byCurrency = [];
        foreach (Currency::cases() as $case) {
            $byCurrency[$case->value] = [
                'currency' => $case->value,
                'completed_volume_in' => '0.00',
                'completed_volume_out' => '0.00',
                'fee_total' => '0.00',
            ];
        }

        $cashToWinIn = '0.00';
        $winToCashIn = '0.00';

        $rows = WalletConversion::query()
            ->where('user_id', $userId)
            ->where('status', WalletConversionStatus::Completed->value)
            ->whereNull('reversal_of_id')
            ->get(['currency', 'direction', 'amount_in', 'amount_out', 'fee_amount']);

        foreach ($rows as $row) {
            $rowCurrency = ConversionCurrency::parse($row->currency)->value;
            $in = bcadd((string) ($row->getRawOriginal('amount_in') ?? $row->amount_in ?? '0'), '0', 2);
            $out = bcadd((string) ($row->getRawOriginal('amount_out') ?? $row->amount_out ?? '0'), '0', 2);
            $fee = bcadd((string) ($row->getRawOriginal('fee_amount') ?? $row->fee_amount ?? '0'), '0', 2);

            $byCurrency[$rowCurrency]['completed_volume_in'] = bcadd($byCurrency[$rowCurrency]['completed_volume_in'], $in, 2);
            $byCurrency[$rowCurrency]['completed_volume_out'] = bcadd($byCurrency[$rowCurrency]['completed_volume_out'], $out, 2);
            $byCurrency[$rowCurrency]['fee_total'] = bcadd($byCurrency[$rowCurrency]['fee_total'], $fee, 2);

            if ($rowCurrency === $selectedCurrency->value) {
                $dirVal = $row->direction instanceof WalletConversionDirection
                    ? $row->direction->value
                    : (string) $row->direction;
                if ($dirVal === WalletConversionDirection::CashToWin->value) {
                    $cashToWinIn = bcadd($cashToWinIn, $in, 2);
                } elseif ($dirVal === WalletConversionDirection::WinToCash->value) {
                    $winToCashIn = bcadd($winToCashIn, $in, 2);
                }
            }
        }

        $selectedBucket = $byCurrency[$selectedCurrency->value];

        return array_merge($counts, [
            'counts_scope' => 'all_currencies',
            'total' => $total,
            'completed_count' => $counts[WalletConversionStatus::Completed->value] ?? 0,
            'reversed_count' => $counts[WalletConversionStatus::Reversed->value] ?? 0,
            'currency' => $selectedCurrency->value,
            'completed_volume_in' => $selectedBucket['completed_volume_in'],
            'completed_volume_out' => $selectedBucket['completed_volume_out'],
            'fee_total' => $selectedBucket['fee_total'],
            'by_currency' => $byCurrency,
            'total_amount_in' => $selectedBucket['completed_volume_in'],
            'total_fee_amount' => $selectedBucket['fee_total'],
            'total_amount_out' => $selectedBucket['completed_volume_out'],
            'cash_to_win_amount_in' => $cashToWinIn,
            'win_to_cash_amount_in' => $winToCashIn,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function auditTimeline(string $reference, int $userId): array
    {
        $conversion = WalletConversion::query()
            ->where('reference', $reference)
            ->where('user_id', $userId)
            ->first();

        if (! $conversion instanceof WalletConversion) {
            return [];
        }

        $audits = WalletConversionAudit::query()
            ->where(static function ($q) use ($conversion, $reference): void {
                $q->where('wallet_conversion_id', (int) $conversion->getKey())
                    ->orWhere('reference', $reference);
            })
            ->orderByDesc('id')
            ->get();

        $timeline = [];
        foreach ($audits as $audit) {
            $timeline[] = [
                'event' => (string) $audit->event,
                'channel' => $audit->channel?->value ?? (string) $audit->getRawOriginal('channel'),
                'error_code' => $audit->error_code,
                'message' => $audit->message,
                'recorded_at' => $audit->recorded_at?->toDateTimeString() ?? '',
            ];
        }

        return $timeline;
    }

    /**
     * @return array<string, int>
     */
    public function refusalCounts(int $days = 7): array
    {
        $since = Carbon::now()->subDays(max(1, $days));

        $rows = WalletConversionAudit::query()
            ->whereNotNull('error_code')
            ->where('error_code', '!=', '')
            ->where('recorded_at', '>=', $since)
            ->selectRaw('error_code, COUNT(*) as aggregate_count')
            ->groupBy('error_code')
            ->pluck('aggregate_count', 'error_code')
            ->all();

        $result = [];
        foreach ($rows as $code => $count) {
            $result[(string) $code] = (int) $count;
        }

        return $result;
    }
}
