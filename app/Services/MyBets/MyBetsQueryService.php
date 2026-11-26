<?php

declare(strict_types=1);

namespace App\Services\MyBets;

use App\Enums\BetStatus;
use App\Models\Bet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * "My bets" — a READ MODEL over the existing bets table. No writes, ever.
 *
 * REUSE, NOT REIMPLEMENTATION
 * Bets, items, tickets, draws and results already exist and are already written
 * by the Batch-4.3 purchase pipeline. This service answers three questions a
 * player actually asks — "what have I got on", "what happened", "what am I owed"
 * — using only indexed reads on those existing tables. It creates no view table,
 * no summary table and no denormalised status column.
 *
 * TAB COUNTS COME FROM ONE QUERY
 * The mockup shows five tabs with counts. Running five COUNT queries per page
 * view is both slow and, worse, incoherent: a bet can settle between tab three
 * and tab five and the counts then disagree with the lists. counts() therefore
 * performs a single GROUP BY status aggregate and derives every tab from it; a
 * test asserts each tab count equals its filtered list's total.
 *
 * WHY 'WINNING' IS settlement WITH MONEY
 * A won bet with an actual_payout of zero is not a win in any sense a player
 * recognises. The winning bucket is therefore `status = won AND actual_payout > 0`,
 * expressed once in winningScope() and reused by both the count and the list so
 * the two can never drift.
 */
final class MyBetsQueryService
{
    /** The status filter values the API accepts. */
    public const FILTER_ALL = 'all';
    public const FILTER_PENDING = 'pending';
    public const FILTER_CONFIRMED = 'confirmed';
    public const FILTER_CANCELLED = 'cancelled';
    public const FILTER_WINNING = 'winning';

    /**
     * @return array<int, string>
     */
    public static function filters(): array
    {
        return [
            self::FILTER_ALL,
            self::FILTER_PENDING,
            self::FILTER_CONFIRMED,
            self::FILTER_CANCELLED,
            self::FILTER_WINNING,
        ];
    }

    /**
     * Paginated list of the member's own bets.
     *
     * Every query starts from forUser(), so there is no code path here that can
     * read another member's bets; the controller does not need to trust an
     * owner check afterwards.
     */
    public function list(
        int $memberId,
        string $filter = self::FILTER_ALL,
        ?Carbon $from = null,
        ?Carbon $to = null,
        int $perPage = 20,
        int $page = 1,
    ): LengthAwarePaginator {
        $query = $this->baseQuery($memberId, $filter, $from, $to);

        // Eager-loaded because the resource renders the draw and the item count
        // for every row; without this a 20-row page would run 40+ queries.
        $query->with(['draw:id,draw_number,type,status,scheduled_at,result_published_at', 'draw.result'])
            ->withCount('items');

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: max(1, min($perPage, 100)), page: max(1, $page));
    }

    /**
     * Per-tab counts in ONE grouped query.
     *
     * The date window is applied so the counts describe the same slice the list
     * does. `all` is the sum of the other buckets plus the statuses that belong
     * to none of them (lost, refunded, active) — computed from the same rows, not
     * from a sixth query, so the arithmetic is visible and cannot disagree with
     * the lists.
     *
     * @return array{all:int, pending:int, confirmed:int, cancelled:int, winning:int}
     */
    public function counts(int $memberId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $rows = Bet::query()
            ->forUser($memberId)
            ->when($from !== null, fn (Builder $q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('created_at', '<=', $to))
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate, SUM(CASE WHEN status = ? AND actual_payout > 0 THEN 1 ELSE 0 END) as winning_count', [
                BetStatus::Won->value,
            ])
            ->groupBy('status')
            ->get();

        $pending = 0;
        $confirmed = 0;
        $cancelled = 0;
        $winning = 0;
        $all = 0;

        foreach ($rows as $row) {
            $count = (int) $row->aggregate;
            $all += $count;

            $status = $row->status instanceof BetStatus
                ? $row->status
                : BetStatus::tryFrom((string) $row->status);

            match ($status) {
                BetStatus::Pending => $pending += $count,
                BetStatus::Active, BetStatus::Won, BetStatus::Lost => $confirmed += $count,
                BetStatus::Cancelled => $cancelled += $count,
                BetStatus::Refunded => null,
                default => null,
            };

            $winning += (int) $row->winning_count;
        }

        return [
            'all' => $all,
            'pending' => $pending,
            'confirmed' => $confirmed,
            'cancelled' => $cancelled,
            'winning' => $winning,
        ];
    }

    /**
     * One bet, with the draw and its published result, scoped to its owner.
     *
     * Returns null for a foreign bet rather than throwing a 404 with a different
     * message: an attacker must not be able to tell "not yours" from "does not
     * exist", so both are the same null.
     */
    public function detail(int $memberId, int|string $identifier): ?Bet
    {
        $query = Bet::query()
            ->forUser($memberId)
            ->with(['draw', 'draw.result', 'items', 'ticket']);

        if (is_int($identifier) || ctype_digit((string) $identifier)) {
            $query->whereKey((int) $identifier);
        } else {
            $query->where('bet_number', (string) $identifier);
        }

        return $query->first();
    }

    /**
     * The shared base query, so the list and the detail agree about what a
     * member's bet is.
     *
     * @return Builder<Bet>
     */
    private function baseQuery(int $memberId, string $filter, ?Carbon $from, ?Carbon $to): Builder
    {
        $query = Bet::query()->forUser($memberId);

        $this->applyFilter($query, $filter);

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        return $query;
    }

    /**
     * @param  Builder<Bet>  $query
     */
    public function applyFilter(Builder $query, string $filter): void
    {
        match ($filter) {
            self::FILTER_PENDING => $query->where('status', BetStatus::Pending->value),
            self::FILTER_CONFIRMED => $query->whereIn('status', [
                BetStatus::Active->value,
                BetStatus::Won->value,
                BetStatus::Lost->value,
            ]),
            self::FILTER_CANCELLED => $query->where('status', BetStatus::Cancelled->value),
            self::FILTER_WINNING => $this->winningScope($query),
            default => null,
        };
    }

    /**
     * The single definition of "winning": settled AND paid.
     *
     * @param  Builder<Bet>  $query
     */
    private function winningScope(Builder $query): void
    {
        $query->where('status', BetStatus::Won->value)
            ->where('actual_payout', '>', 0);
    }
}
