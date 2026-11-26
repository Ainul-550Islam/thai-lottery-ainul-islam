<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\MyBetsIndexRequest;
use App\Http\Resources\MyBetCountsResource;
use App\Http\Resources\MyBetResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\MyBets\MyBetsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * My bets: list, tab counts, detail. Read-only, owner-scoped.
 *
 * WHY THIS IS NOT UNDER /me/bets/{bet} ALONGSIDE THE EXISTING API
 * GET /api/v1/bets already exists (BetController@index) and is consumed. Rather
 * than change or shadow it, Batch 3 mounts an explicit /me prefix for the new
 * player-experience surface. Both read the same rows; the new one adds tab
 * counts, date windows and a per-tab paginated list, and nothing about the old one
 * changes (see the route table in the final report).
 */
final class MyBetsController
{
    public function __construct(
        private readonly MyBetsQueryService $bets,
    ) {
    }

    /**
     * GET /me/bets?status=all|pending|confirmed|cancelled|winning&from=&to=&per_page=&page=
     */
    public function index(MyBetsIndexRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // MyBetsIndexRequest validates shape and derives its status vocabulary from
        // MyBetsQueryService::filters(), so a tab cannot be listed in one place and
        // unhandled in the other. The service still caps per_page itself.
        $validated = $request->validated();

        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : null;
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : null;

        $page = $this->bets->list(
            memberId: (int) $user->id,
            filter: (string) ($validated['status'] ?? MyBetsQueryService::FILTER_ALL),
            from: $from,
            to: $to,
            perPage: (int) ($validated['per_page'] ?? 20),
            page: (int) ($validated['page'] ?? 1),
        );

        /*
         * MyBetResource, not a hand-built array.
         *
         * The row shape was previously defined here AND in the resource; two
         * definitions of one contract, where a field added to one would be missing
         * from the other. The controller now only decides WHICH bets, never what a
         * bet looks like.
         */
        $items = MyBetResource::collection($page->items())->resolve();

        return ApiResponse::success(
            ['bets' => $items],
            'Bets loaded.',
            200,
            [
                'pagination' => [
                    'total' => $page->total(),
                    'per_page' => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                ],
                'filter' => (string) ($validated['status'] ?? MyBetsQueryService::FILTER_ALL),
            ],
        );
    }

    /**
     * GET /me/bets/counts — the five tab counts, from ONE grouped query.
     */
    public function counts(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Two date bounds and nothing else: a full form request for this would be
        // ceremony. The vocabulary that matters (the tab names) is checked on the
        // list endpoint, which is where a client sends it.
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : null;
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : null;

        return ApiResponse::success(
            ['counts' => (new MyBetCountsResource($this->bets->counts((int) $user->id, $from, $to)))->resolve()],
            'Counts loaded.',
        );
    }

    /**
     * GET /me/bets/{bet} — one bet, with its draw, result, items and ticket.
     *
     * A bet that is not the caller's returns 404, the same as one that does not
     * exist, so an identifier probe learns nothing.
     */
    public function show(Request $request, string $bet): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $record = $this->bets->detail((int) $user->id, $bet);

        if ($record === null) {
            return ApiResponse::error('bet_not_found', 'That bet does not exist.', 404);
        }

        $result = $record->draw?->result;

        return ApiResponse::success([
            // The list row and the detail header are THE SAME shape, because both
            // come from MyBetResource. A client that renders a list row can render
            // the detail header with no extra mapping.
            'bet' => (new MyBetResource($record))->resolve() + [
                'won_at' => $record->won_at?->toIso8601String(),
            ],
            'items' => $record->items->map(static fn ($item): array => [
                'number' => $item->number,
                'position' => $item->position,
                'amount' => (string) $item->amount,
                'payout_multiplier' => (string) $item->payout_multiplier,
                'potential_payout' => (string) $item->potential_payout,
            ])->all(),
            'draw' => $record->draw === null ? null : [
                'id' => (int) $record->draw->id,
                'draw_number' => $record->draw->draw_number,
                'status' => $record->draw->status?->value,
                'scheduled_at' => $record->draw->scheduled_at?->toIso8601String(),
                'result_published_at' => $record->draw->result_published_at?->toIso8601String(),
            ],
            'result' => $result === null ? null : [
                'first_prize' => $result->first_prize,
                'three_digit_front' => $result->three_digit_front,
                'three_digit_back' => $result->three_digit_back,
                'two_digit_back' => $result->two_digit_back,
                'source_state' => $result->source_state,
            ],
            'ticket' => $record->ticket === null ? null : [
                'ticket_number' => $record->ticket->ticket_number,
                'status' => $record->ticket->status?->value,
            ],
        ], 'Bet loaded.');
    }
}
