<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\DTOs\Wallet\ConversionHistoryFilterData;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionStatus;
use App\Models\WalletConversion;
use App\Models\WalletConversionAudit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

final class ConversionHistoryService
{
    public function history(ConversionHistoryFilterData $filter): LengthAwarePaginator
    {
        $query = WalletConversion::query()
            ->where('user_id', $filter->userId)
            ->orderByDesc('id');

        if ($filter->direction !== null) {
            $query->where('direction', $filter->direction->value);
        }

        if ($filter->statuses !== []) {
            $query->whereIn(
                'status',
                array_map(static fn (WalletConversionStatus $s): string => $s->value, $filter->statuses),
            );
        }

        if ($filter->from !== null) {
            $query->where('created_at', '>=', Carbon::instance($filter->from));
        }

        if ($filter->to !== null) {
            $query->where('created_at', '<=', Carbon::instance($filter->to));
        }

        if ($filter->search !== null && trim($filter->search) !== '') {
            $term = '%'.trim($filter->search).'%';
            $query->where(static function ($inner) use ($term): void {
                $inner->where('reference', 'like', $term)
                    ->orWhere('quote_reference', 'like', $term);
            });
        }

        return $query->paginate(
            perPage: $filter->perPage,
            columns: ['*'],
            pageName: 'page',
            page: $filter->page,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForUser(int $userId): array
    {
        $completed = WalletConversion::query()
            ->where('user_id', $userId)
            ->where('status', WalletConversionStatus::Completed->value);

        $count = (clone $completed)->count();
        $totalAmountIn = bcadd((string) ((clone $completed)->sum('amount_in') ?? '0'), '0', 2);
        $totalFees = bcadd((string) ((clone $completed)->sum('fee_amount') ?? '0'), '0', 2);
        $totalAmountOut = bcadd((string) ((clone $completed)->sum('amount_out') ?? '0'), '0', 2);

        $cashToWinIn = bcadd((string) (WalletConversion::query()
            ->where('user_id', $userId)
            ->where('status', WalletConversionStatus::Completed->value)
            ->where('direction', WalletConversionDirection::CashToWin->value)
            ->sum('amount_in') ?? '0'), '0', 2);

        $winToCashIn = bcadd((string) (WalletConversion::query()
            ->where('user_id', $userId)
            ->where('status', WalletConversionStatus::Completed->value)
            ->where('direction', WalletConversionDirection::WinToCash->value)
            ->sum('amount_in') ?? '0'), '0', 2);

        return [
            'completed_count' => $count,
            'total_amount_in' => $totalAmountIn,
            'total_fee_amount' => $totalFees,
            'total_amount_out' => $totalAmountOut,
            'cash_to_win_amount_in' => $cashToWinIn,
            'win_to_cash_amount_in' => $winToCashIn,
        ];
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
