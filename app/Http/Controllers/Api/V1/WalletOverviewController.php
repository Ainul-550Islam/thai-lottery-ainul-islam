<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\WalletTransactionFeedRequest;
use App\Http\Resources\WalletFeedItemResource;
use App\Http\Resources\WalletOverviewResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Wallet\WalletOverviewService;
use App\Services\Wallet\WalletTransactionFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The wallet overview and the money feed. Both read-only.
 *
 * NOTHING HERE CAN MOVE MONEY
 * There is no POST on this controller. Deposits, withdrawals, conversions and
 * transfers keep their existing endpoints and their existing services; this
 * surface only SHOWS the result. That is the whole reason it is safe to serve it
 * from a cache-friendly read path.
 */
final class WalletOverviewController
{
    public function __construct(
        private readonly WalletOverviewService $overview,
        private readonly WalletTransactionFeedService $feed,
    ) {
    }

    /**
     * GET /me/wallet/overview
     */
    public function overview(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // The resource is where every amount becomes a decimal STRING and every
        // unknown stays null. The service did the arithmetic in bcmath; nothing is
        // recomputed or reformatted here.
        return ApiResponse::success(
            ['wallet' => (new WalletOverviewResource($this->overview->overview((int) $user->id)))->resolve()],
            'Wallet overview loaded.',
        );
    }

    /**
     * GET /me/wallet/transactions?type=&from=&to=&per_page=&cursor=
     */
    public function transactions(WalletTransactionFeedRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // The `type` vocabulary is derived from WalletFeedType::filterValues(), so a
        // feed bucket cannot be added without the validator learning about it.
        $validated = $request->validated();

        $from = isset($validated['from']) ? Carbon::parse($validated['from'])->startOfDay() : null;
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->endOfDay() : null;

        $page = $this->feed->feed(
            memberId: (int) $user->id,
            type: $validated['type'] ?? null,
            from: $from,
            to: $to,
            perPage: (int) ($validated['per_page'] ?? 20),
            cursor: $validated['cursor'] ?? null,
        );

        /*
         * project() produces the row; the resource retypes it. Keeping the two
         * apart is what lets WalletTransactionFeedServiceTest call project() with
         * no HTTP layer at all, while the wire shape still lives in one place.
         */
        $projected = [];

        foreach ($page->items() as $transaction) {
            $projected[] = $this->feed->project($transaction);
        }

        $items = WalletFeedItemResource::collection($projected)->resolve();

        return ApiResponse::success(
            ['transactions' => $items],
            'Transactions loaded.',
            200,
            [
                'pagination' => [
                    'next_cursor' => $page->nextCursor()?->encode(),
                    'prev_cursor' => $page->previousCursor()?->encode(),
                    'per_page' => $page->perPage(),
                ],
                'filter' => $validated['type'] ?? 'all',
            ],
        );
    }
}
