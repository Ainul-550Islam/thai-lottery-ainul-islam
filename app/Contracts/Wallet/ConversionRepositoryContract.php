<?php

declare(strict_types=1);

namespace App\Contracts\Wallet;

use App\DTOs\Wallet\ConversionHistoryFilterData;
use App\Enums\WalletConversionStatus;
use App\Models\WalletConversion;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Persistence boundary for wallet conversions.
 *
 * WHY THE SERVICE DOES NOT TOUCH THE MODEL DIRECTLY
 * Two reasons, both financial:
 *
 *  1. ROW LOCKING MUST BE EXPLICIT. A conversion is executed under a lock on
 *     its own row and on both wallet rows. If any service could load a
 *     conversion with a plain `find()`, a concurrent reversal and a concurrent
 *     execution could each read the same pre-movement state. Every read that
 *     participates in a decision must therefore go through a method whose name
 *     says whether it locks — `findForUpdate` — so the concurrency contract is
 *     visible at the call site instead of buried in a query builder.
 *
 *  2. AGGREGATES ARE PART OF THE DOMAIN. Daily and monthly window totals,
 *     reconciliation sums and per-status counts are not reporting niceties:
 *     they are inputs to a money decision. Naming them here keeps the exact
 *     arithmetic in one place, so a limit check and a reconciliation report can
 *     never disagree about what "today's total" means.
 *
 * Every read method is scoped by owner or explicitly marked as an operator
 * query; there is no unscoped "get all conversions" method.
 */
interface ConversionRepositoryContract
{
    /**
     * Create a conversion row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): WalletConversion;

    /**
     * Find by public reference without a lock. For reads that only display.
     */
    public function findByReference(string $reference): ?WalletConversion;

    /**
     * Find by public reference WITH a row lock. For any read that feeds a
     * decision or a state transition. Must be called inside a transaction.
     */
    public function findByReferenceForUpdate(string $reference): ?WalletConversion;

    /**
     * Find by primary key with a row lock.
     */
    public function findForUpdate(int $id): ?WalletConversion;

    /**
     * Transition a conversion to a new status, refusing illegal transitions.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws \App\Exceptions\Wallet\ConversionException when the transition is not in the state graph
     */
    public function transition(
        WalletConversion $conversion,
        WalletConversionStatus $status,
        array $attributes = [],
    ): WalletConversion;

    /**
     * Mark a conversion completed with the amounts and post-movement balances
     * that were actually written, in the same transaction that wrote them.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function markCompleted(WalletConversion $conversion, array $attributes = []): WalletConversion;

    /**
     * Sum of completed conversion amounts originating from the owner within the
     * window, used by the daily and monthly limit gates.
     */
    public function completedTotalForUser(
        int $userId,
        DateTimeImmutable $from,
        ?DateTimeImmutable $to = null,
    ): string;

    /**
     * A page of history, always scoped to the filter's owner.
     */
    public function paginate(ConversionHistoryFilterData $filter): LengthAwarePaginator;

    /**
     * Conversions that are mid-flight or stale, for the reconciler. Results are
     * bounded by the caller-supplied ceiling.
     *
     * @return Collection<int, WalletConversion>
     */
    public function listUnsettled(DateTimeImmutable $olderThan, int $limit = 200): Collection;

    /**
     * Completed conversions inside a window, with their postings, for
     * reconciliation. Results are enumerated in ascending id order so two
     * passes over the same window see the same sequence.
     *
     * @return Collection<int, WalletConversion>
     */
    public function listCompletedBetween(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $limit = 1000,
    ): Collection;

    /**
     * Counts per status for a user, keyed by the status backing value.
     *
     * @return array<string, int>
     */
    public function statusCountsForUser(int $userId): array;
}
