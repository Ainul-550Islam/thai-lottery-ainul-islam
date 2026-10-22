<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\PrizeClaimException;
use App\Http\Requests\Admin\ReviewPlayerPrizeClaimRequest;
use App\Http\Resources\PrizeClaimResource;
use App\Models\Payout;
use App\Services\Payment\PayoutTransferAccess;
use App\Services\Prize\PrizeClaimService;
use Illuminate\Http\Request;

/** Separate player-assertion review: does not approve a destination, create a batch, or move money. */
final class PlayerPrizeClaimReviewController
{
    public function index(Request $request)
    {
        app(PayoutTransferAccess::class)->authorize($request->user(), PayoutTransferAccess::CLAIM_REVIEW);
        $rows = Payout::query()->whereIn('metadata->claim->status', ['submitted', 'under_review'])
            ->whereNotNull('metadata->claim->claimant_user_id')->orderBy('id')->paginate(30);
        $items = $rows->getCollection()->filter(fn ($row) => app(PrizeClaimService::class)->claimFor((int) $row->id) !== null)
            ->map(fn ($row) => (new PrizeClaimResource($row))->resolve($request))->values()->all();
        if ($request->expectsJson()) {
            return response()->json(['data' => $items, 'pagination' => ['page' => $rows->currentPage(), 'last_page' => $rows->lastPage(),
                'candidate_total' => $rows->total()]])->header('Cache-Control', 'private, no-store');
        }

        return response()->view('admin.player-prize-claims', ['rows' => $rows, 'items' => $items, 'item' => null])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Payout $payout)
    {
        app(PayoutTransferAccess::class)->authorize($request->user(), PayoutTransferAccess::CLAIM_REVIEW);
        abort_unless(app(PrizeClaimService::class)->claimFor((int) $payout->id) !== null, 404);
        $item = (new PrizeClaimResource($payout))->resolve($request);
        if ($request->expectsJson()) {
            return response()->json(['data' => $item])->header('Cache-Control', 'private, no-store');
        }

        return response()->view('admin.player-prize-claims', ['rows' => null, 'items' => [], 'item' => $item])->header('Cache-Control', 'private, no-store');
    }

    public function decide(ReviewPlayerPrizeClaimRequest $request, Payout $payout)
    {
        try {
            $claims = app(PrizeClaimService::class);
            $args = [(int) $payout->id, (int) $request->user()->id, $request->validated('note'), $request->boolean('original_facts_verified')];
            $request->validated('decision') === 'approved' ? $claims->approve(...$args) : $claims->reject(...$args);
        } catch (PrizeClaimException) {
            return $request->expectsJson()
                ? response()->json(['code' => 'player_claim_review_refused'], 409)->header('Cache-Control', 'private, no-store')
                : redirect()->back()->withErrors(['claim' => 'Review refused. Refresh the original claim; no money was moved.']);
        }
        if ($request->expectsJson()) {
            return response()->json(['data' => (new PrizeClaimResource($payout->fresh()))->resolve($request)])->header('Cache-Control', 'private, no-store');
        }

        return redirect()->route('admin.player-prize-claims.show', $payout)->with('status', 'Player assertion reviewed. Financial maker/checker approval is still separate.');
    }
}
