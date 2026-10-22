<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\FinancialException;
use App\Exceptions\PayoutApprovalException;
use App\Exceptions\PayoutBatchException;
use App\Exceptions\PayoutTransferException;
use App\Exceptions\PrizeClaimException;
use App\Http\Requests\Admin\DecidePayoutApprovalRequest;
use App\Http\Requests\Admin\StorePayoutApprovalRequest;
use App\Http\Resources\PayoutApprovalResource;
use App\Models\Payout;
use App\Models\PayoutBatch;
use App\Services\Finance\PayoutApprovalIntakeService;
use App\Services\Finance\PayoutBatchRoutingService;
use App\Services\Payment\PayoutTransferAccess;
use Illuminate\Http\Request;

/** Authorization, validation and projection only; no financial transaction in the HTTP layer. */
final class PayoutApprovalIntakeController
{
    public function index(Request $request)
    {
        app(PayoutTransferAccess::class)->authorize($request->user(), PayoutTransferAccess::VIEW);
        $rows = Payout::query()->orderByDesc('id')->paginate(30);
        if ($request->expectsJson()) {
            return PayoutApprovalResource::collection($rows)->response()->header('Cache-Control', 'private, no-store');
        }

        return response()->view('admin.payout-approvals', ['rows' => $rows, 'item' => null, 'batch' => null])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Payout $payout)
    {
        app(PayoutTransferAccess::class)->authorize($request->user(), PayoutTransferAccess::VIEW);
        if ($request->expectsJson()) {
            return (new PayoutApprovalResource($payout))->response()->header('Cache-Control', 'private, no-store');
        }

        return response()->view('admin.payout-approvals', ['rows' => null, 'item' => (new PayoutApprovalResource($payout))->resolve($request), 'batch' => null])->header('Cache-Control', 'private, no-store');
    }

    public function store(StorePayoutApprovalRequest $request)
    {
        try {
            $row = app(PayoutApprovalIntakeService::class)->request($request->user(), (int) $request->validated('payout_id'),
                $request->validated('method'), $request->validated('beneficiary'), $request->validated('note'));
        } catch (PrizeClaimException|PayoutApprovalException|PayoutBatchException|PayoutTransferException|FinancialException) {
            return $this->refused($request);
        }

        return $this->payoutResult($request, $row);
    }

    public function decide(DecidePayoutApprovalRequest $request, Payout $payout)
    {
        try {
            $row = app(PayoutApprovalIntakeService::class)->decide($request->user(), (int) $payout->id,
                $request->validated('decision'), $request->boolean('original_facts_verified'), $request->validated('note'));
        } catch (PrizeClaimException|PayoutApprovalException|PayoutBatchException|PayoutTransferException|FinancialException) {
            return $this->refused($request);
        }

        return $this->payoutResult($request, $row);
    }

    public function destination(Request $request, Payout $payout)
    {
        try {
            $target = app(PayoutApprovalIntakeService::class)->destination($request->user(), (int) $payout->id);
        } catch (PrizeClaimException|PayoutApprovalException|PayoutBatchException|PayoutTransferException|FinancialException) {
            return $this->refused($request);
        }

        return response()->json(['destination' => $target])->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function createBatch(Request $request)
    {
        app(PayoutTransferAccess::class)->authorize($request->user(), PayoutTransferAccess::ROUTE);
        $valid = $request->validate(['payout_ids' => ['required', 'array', 'min:1', 'max:200'], 'payout_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'amount' => ['prohibited'], 'currency' => ['prohibited'], 'batch_key' => ['prohibited']]);
        try {
            $batch = app(PayoutApprovalIntakeService::class)->batch($request->user(), array_map('intval', array_values($valid['payout_ids'])));
        } catch (PrizeClaimException|PayoutApprovalException|PayoutBatchException|PayoutTransferException|FinancialException) {
            return $this->refused($request);
        }

        return $this->batchResult($request, $batch);
    }

    public function showBatch(Request $request, PayoutBatch $batch)
    {
        app(PayoutTransferAccess::class)->authorize($request->user(), PayoutTransferAccess::VIEW);
        $data = app(PayoutBatchRoutingService::class)->project($batch);
        if ($request->expectsJson()) {
            return response()->json(['data' => $data])->header('Cache-Control', 'private, no-store');
        }

        return response()->view('admin.payout-approvals', ['rows' => null, 'item' => null, 'batch' => $data])->header('Cache-Control', 'private, no-store');
    }

    public function routeBatch(Request $request, PayoutBatch $batch)
    {
        app(PayoutTransferAccess::class)->authorize($request->user(), PayoutTransferAccess::ROUTE);
        try {
            $result = app(PayoutBatchRoutingService::class)->dispatchApproved($batch, $request->user());
        } catch (PrizeClaimException|PayoutApprovalException|PayoutBatchException|PayoutTransferException|FinancialException) {
            return $this->refused($request);
        }

        return $this->batchResult($request, $result['batch']);
    }

    public function refreshBatch(Request $request, PayoutBatch $batch)
    {
        try {
            $current = app(PayoutBatchRoutingService::class)->refresh($batch, $request->user());
        } catch (PrizeClaimException|PayoutApprovalException|PayoutBatchException|PayoutTransferException|FinancialException) {
            return $this->refused($request);
        }

        return $this->batchResult($request, $current);
    }

    private function payoutResult(Request $request, Payout $row)
    {
        return $request->expectsJson() ? (new PayoutApprovalResource($row))->response()->header('Cache-Control', 'private, no-store')
            : redirect()->route('admin.payout-approvals.show', $row)->with('status', 'Original facts/verdict recorded. This action did not send an external payment.');
    }

    private function batchResult(Request $request, PayoutBatch $row)
    {
        return $request->expectsJson() ? response()->json(['data' => app(PayoutBatchRoutingService::class)->project($row)])->header('Cache-Control', 'private, no-store')
            : redirect()->route('admin.payout-approvals.batch', $row)->with('status', 'Canonical batch state updated. External execution is record-only.');
    }

    private function refused(Request $request)
    {
        return $request->expectsJson() ? response()->json(['code' => 'payout_approval_or_routing_refused'], 409)->header('Cache-Control', 'private, no-store')
            : back()->withErrors(['workflow' => 'Original facts, authority or execution state require controlled review. Nothing was resent or reset.']);
    }
}
