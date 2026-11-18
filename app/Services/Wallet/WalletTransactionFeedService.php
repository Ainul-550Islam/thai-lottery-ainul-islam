<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\TransactionType;
use App\Enums\WalletFeedType;
use App\Models\FinancialTransaction;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The unified member-facing money feed. READ-ONLY, forever.
 *
 * WHAT IT READS
 * financial_transactions — the existing Postgres/MySQL row the ledger already
 * writes for every money movement, joined to nothing and computed from nothing.
 * The feed is a PROJECTION: same rows, player-facing vocabulary, a cursor.
 *
 * WHY CURSOR PAGINATION AND NOT OFFSETS
 * New money lands at the head of this list while the member is scrolling. An
 * offset page 2 can then show a row that was on page 1 a second ago, or skip one
 * entirely. A cursor on (created_at, id) is stable under inserts, which is what
 * makes "load more" correct on a busy account.
 *
 * THE TYPE FILTER IS TOTAL
 * WalletFeedType::fromTransactionType() maps every storage type, and the filter
 * is translated back into the set of storage types that fall in the requested
 * bucket. A bucket that maps to no storage type returns an empty page rather than
 * an unfiltered one — the failure mode that would show a player their entire
 * history when they asked for fees.
 *
 * OWNERSHIP
 * Every query is scoped by user_id first. There is no path in this class that can
 * read another member's transactions, so the controller never has to verify
 * ownership afterwards.
 */
final class WalletTransactionFeedService
{
    /**
     * @return CursorPaginator<int, FinancialTransaction>
     */
    public function feed(
        int $memberId,
        ?string $type = null,
        ?Carbon $from = null,
        ?Carbon $to = null,
        int $perPage = 20,
        ?string $cursor = null,
    ): CursorPaginator {
        $query = FinancialTransaction::query()->where('user_id', $memberId);

        if ($type !== null && $type !== '' && $type !== 'all') {
            $bucket = WalletFeedType::tryFrom($type);

            if ($bucket === null) {
                // An unknown filter is an empty result, not an ignored filter.
                $query->whereRaw('1 = 0');
            } else {
                $types = $this->storageTypesFor($bucket);

                if ($types === []) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('type', $types);
                }
            }
        }

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(perPage: max(1, min($perPage, 100)), cursor: $cursor);
    }

    /**
     * Project one row into the feed shape.
     *
     * `direction` prefers the enum's stated side and falls back to the SIGN of the
     * stored amount only for the types where direction is genuinely ambiguous
     * (transfer, adjustment, conversion). It never guesses from the description.
     *
     * @return array<string, mixed>
     */
    public function project(FinancialTransaction $transaction): array
    {
        $storageType = $transaction->type instanceof TransactionType
            ? $transaction->type
            : (TransactionType::tryFrom((string) $transaction->type) ?? TransactionType::Adjustment);

        $feedType = WalletFeedType::fromTransactionType($storageType);

        $amount = (string) $transaction->amount;
        $direction = $feedType->direction();

        if ($direction === null) {
            $direction = bccomp($amount, '0', 2) < 0 ? 'debit' : 'credit';
            $amount = ltrim($amount, '-');
        }

        return [
            'id' => (int) $transaction->id,
            'reference' => (string) ($transaction->reference_number ?? $transaction->id),
            'type' => $feedType->value,
            'label' => $feedType->label(),
            'direction' => $direction,
            // Absolute value: the direction carries the sign, so a client never
            // has to render '-(-10.00)'.
            'amount' => bcadd($amount, '0.00', 2),
            'currency' => (string) ($transaction->currency?->value ?? $transaction->currency ?? 'THB'),
            'status' => (string) ($transaction->status?->value ?? $transaction->status ?? ''),
            'description' => $transaction->description,
            'created_at' => $transaction->created_at?->toIso8601String(),
        ];
    }

    /**
     * The storage types that belong to one feed bucket.
     *
     * Derived from the total mapping, not written by hand, so adding a
     * TransactionType case cannot leave this filter behind.
     *
     * @return list<string>
     */
    public function storageTypesFor(WalletFeedType $bucket): array
    {
        $out = [];

        foreach (TransactionType::cases() as $case) {
            if (WalletFeedType::fromTransactionType($case) === $bucket) {
                $out[] = $case->value;
            }
        }

        return $out;
    }
}
