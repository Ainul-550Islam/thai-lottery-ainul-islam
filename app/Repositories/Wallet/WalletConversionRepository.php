<?php

declare(strict_types=1);

namespace App\Repositories\Wallet;

use App\Contracts\Wallet\ConversionRepositoryContract;
use App\DTOs\Wallet\ConversionHistoryFilterData;
use App\Enums\WalletConversionStatus;
use App\Models\WalletConversion;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Query-builder persistence for wallet conversions.
 *
 * WHY EVERY DECISION-BEARING READ IS SEPARATED FROM EVERY DISPLAY READ
 * A conversion row that is being displayed may be read at any time and may be
 * stale by a few milliseconds — harmless. A conversion row that is being judged
 * (is it still reversible? has it already been completed?) must be read under a
 * row lock, inside the transaction that will act on it. Those two reads have
 * different correctness requirements, so they are different methods here and
 * the caller states which one it needs by name. There is no generic `find()`
 * that quietly provides the wrong one.
 */
class WalletConversionRepository implements ConversionRepositoryContract
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): WalletConversion
    {
        return WalletConversion::query()->create($attributes);
    }

    public function findByReference(string $reference): ?WalletConversion
    {
        return WalletConversion::query()->where('reference', $reference)->first();
    }

    public function findByReferenceForUpdate(string $reference): ?WalletConversion
    {
        return WalletConversion::query()
            ->where('reference', $reference)
            ->lockForUpdate()
            ->first();
    }

    public function findForUpdate(int $id): ?WalletConversion
    {
        return WalletConversion::query()->whereKey($id)->lockForUpdate()->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function transition(
        WalletConversion $conversion,
        WalletConversionStatus $status,
        array $attributes = [],
    ): WalletConversion {
        $conversion->assertTransition($status);

        $conversion->fill(array_merge($attributes, ['status' => $status->value]));
        $conversion->save();

        return $conversion->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function markCompleted(WalletConversion $conversion, array $attributes = []): WalletConversion
    {
        return $this->transition($conversion, WalletConversionStatus::Completed, array_merge([
            'completed_at' => $conversion->completed_at ?? Carbon::now(),
            'failure_reason' => null,
        ], $attributes));
    }

    public function completedTotalForUser(
        int $userId,
        DateTimeImmutable $from,
        ?DateTimeImmutable $to = null,
    ): string {
        $to ??= new DateTimeImmutable();

        $total = WalletConversion::query()
            ->where('user_id', $userId)
            ->where('status', WalletConversionStatus::Completed->value)
            ->where('completed_at', '>=', Carbon::instance($from))
            ->where('completed_at', '<=', Carbon::instance($to))
            ->sum('amount_in');

        return bcadd((string) ($total ?? '0'), '0', 2);
    }

    public function paginate(ConversionHistoryFilterData $filter): LengthAwarePaginator
    {
        $query = WalletConversion::query()->forUser($filter->userId);

        if ($filter->direction !== null) {
            $query->where('direction', $filter->direction->value);
        }

        if ($filter->hasStatuses()) {
            $query->whereIn('status', $filter->statusValues());
        }

        if ($filter->from !== null) {
            $query->where('created_at', '>=', Carbon::instance($filter->from));
        }

        if ($filter->to !== null) {
            $query->where('created_at', '<=', Carbon::instance($filter->to));
        }

        if ($filter->hasSearch()) {
            $term = (string) $filter->search;

            $query->where(function ($builder) use ($term): void {
                $builder->where('reference', 'like', '%'.$term.'%')
                    ->orWhere('quote_reference', 'like', '%'.$term.'%')
                    ->orWhere('idempotency_key', 'like', '%'.$term.'%');
            });
        }

        return $query->orderByDesc('id')->paginate(
            perPage: $filter->perPage,
            page: $filter->page,
        );
    }

    /**
     * @return Collection<int, WalletConversion>
     */
    public function listUnsettled(DateTimeImmutable $olderThan, int $limit = 200): Collection
    {
        return WalletConversion::query()
            ->unsettled()
            ->where('created_at', '<=', Carbon::instance($olderThan))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, WalletConversion>
     */
    public function listCompletedBetween(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit = 1000,
    ): Collection {
        // WHY THIS IS NOT scopeCompleted()
        // `completed` means status = completed, which excludes every conversion an
        // operator has since reversed — and a reversed conversion's ledger postings
        // are still in the ledger, still supposed to match the amounts they were
        // written for, and are exactly the kind of row an incident-time manual
        // correction touches. Excluding them created a blind spot precisely where
        // money is most likely to have been moved by hand: the reconciliation pass
        // would have reported a perfectly balanced night while a reversed
        // conversion's postings had been deleted. Both settled states are examined;
        // the reversal's own row is judged by its own shape (see the job's
        // expectations()).
        return WalletConversion::query()
            ->whereIn('status', array_map(
                static fn (WalletConversionStatus $status): string => $status->value,
                WalletConversionStatus::settledStates(),
            ))
            ->whereBetween('completed_at', [Carbon::instance($from), Carbon::instance($to)])
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<string, int>
     */
    public function statusCountsForUser(int $userId): array
    {
        $rows = WalletConversion::query()
            ->forUser($userId)
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
}
