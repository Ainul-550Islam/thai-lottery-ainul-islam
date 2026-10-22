<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\FinancialException;
use App\Exceptions\PayoutApprovalException;
use App\Exceptions\PayoutTransferException;
use App\Exceptions\PrizeClaimException;
use App\Http\Requests\Admin\ReviewPayoutTransferRequest;
use App\Http\Resources\PayoutTransferResource;
use App\Models\Payout;
use App\Services\Payment\PayoutEvidenceService;
use App\Services\Payment\PayoutTransferAccess;
use App\Services\Payment\PayoutTransferGuard;
use App\Services\Payment\PayoutTransferService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

final class PayoutTransferReviewController
{
    public function index(Request $request)
    {
        app(PayoutTransferAccess::class)->authorize($request->user(), PayoutTransferAccess::VIEW);
        $rows = Payout::query()->whereNotNull('metadata->transfer')->orderByDesc('id')->paginate(30);
        if ($request->expectsJson()) {
            return PayoutTransferResource::collection($rows)->response()->header('Cache-Control', 'private, no-store');
        }

        return response()->view('admin.payout-transfers', ['rows' => $rows, 'item' => null])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Payout $payout)
    {
        app(PayoutTransferAccess::class)->authorize($request->user(), PayoutTransferAccess::VIEW);
        $resource = new PayoutTransferResource($payout);
        if ($request->expectsJson()) {
            return $resource->response()->header('Cache-Control', 'private, no-store');
        }

        $archives = [];
        try {
            $archives = app(PayoutEvidenceService::class)->listing($request->user(), (int) $payout->id);
        } catch (AuthorizationException) {
            // View permission does not imply access to private files.
        }

        return response()->view('admin.payout-transfers', ['rows' => null, 'item' => $resource->resolve($request), 'archives' => $archives])->header('Cache-Control', 'private, no-store');
    }

    public function open(Request $request, Payout $payout)
    {
        app(PayoutTransferAccess::class)->authorize($request->user(), PayoutTransferAccess::REVIEW);
        try {
            app(PayoutTransferService::class)->transfer(app(PayoutTransferGuard::class)->fromRow($payout));
        } catch (PrizeClaimException|PayoutTransferException|PayoutApprovalException|FinancialException) {
            return $this->refused($request);
        }

        return $this->result($request, $payout);
    }

    public function decide(ReviewPayoutTransferRequest $request, Payout $payout)
    {
        try {
            $data = app(PayoutTransferGuard::class)->fromRow($payout);
            $service = app(PayoutTransferService::class);
            if ($request->validated('outcome') === 'completed') {
                $service->complete($data, $request->validated('provider_reference'), $request->user(), $request->boolean('evidence_verified'), $request->validated('note'), (int) $request->validated('evidence_id'));
            } else {
                $service->fail($data, $request->validated('note'), $request->user(), $request->boolean('evidence_verified'), (int) $request->validated('evidence_id'));
            }
        } catch (PrizeClaimException|PayoutTransferException|PayoutApprovalException|FinancialException) {
            return $this->refused($request);
        }

        return $this->result($request, $payout);
    }

    public function destination(Request $request, Payout $payout)
    {
        app(PayoutTransferAccess::class)->authorize($request->user(), PayoutTransferAccess::REVIEW);
        try {
            $details = app(PayoutTransferService::class)->destinationForReview($payout, $request->user());
        } catch (PrizeClaimException|PayoutTransferException|FinancialException) {
            return $this->refused($request);
        }

        return response()->json(['destination' => $details])->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    private function result(Request $request, Payout $row)
    {
        return $request->expectsJson()
            ? (new PayoutTransferResource($row->fresh()))->response()->header('Cache-Control', 'private, no-store')
            : redirect()->route('admin.payout-transfers.show', $row)->with('status', 'Canonical state updated. No external transfer was sent by this action.');
    }

    private function refused(Request $request)
    {
        return $request->expectsJson() ? response()->json(['code' => 'payout_transfer_guard_refused'], 409)->header('Cache-Control', 'private, no-store')
            : back()->withErrors(['transfer' => 'Incompatible authority, original facts or financial state. Preserve uncertainty; do not resend.']);
    }
}
