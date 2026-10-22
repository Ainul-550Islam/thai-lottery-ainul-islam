<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\DTOs\Payment\WithdrawalReconciliationFacts;
use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Exceptions\WithdrawalReconciliationException;
use App\Http\Requests\Admin\DecideWithdrawalReconciliationRequest;
use App\Http\Requests\Admin\StoreWithdrawalReconciliationRequest;
use App\Http\Resources\WithdrawalReconciliationResource;
use App\Models\AuditLog;
use App\Models\WithdrawalReconciliation;
use App\Services\Payment\WithdrawalReconciliationAccess;
use App\Services\Payment\WithdrawalReconciliationEvidenceStore;
use App\Services\Payment\WithdrawalReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class WithdrawalReconciliationController extends Controller
{
    public function __construct(
        private readonly WithdrawalReconciliationAccess $access,
        private readonly WithdrawalReconciliationService $service,
        private readonly WithdrawalReconciliationEvidenceStore $evidence,
    ) {}

    public function index(Request $request)
    {
        $this->access->authorize($request->user(), WithdrawalReconciliationAccess::VIEW);
        $cases = WithdrawalReconciliation::query()->orderByDesc('id')->paginate(30);
        if ($request->expectsJson()) {
            return WithdrawalReconciliationResource::collection($cases)->response()->header('Cache-Control', 'private, no-store');
        }

        return response()->view('admin.withdrawal-reconciliation', ['cases' => $cases, 'item' => null])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, WithdrawalReconciliation $reconciliation)
    {
        $this->access->authorize($request->user(), WithdrawalReconciliationAccess::VIEW);
        $resource = new WithdrawalReconciliationResource($reconciliation);
        if ($request->expectsJson()) {
            return $resource->response()->header('Cache-Control', 'private, no-store');
        }

        return response()->view('admin.withdrawal-reconciliation', ['cases' => null, 'item' => $resource->resolve($request)])->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreWithdrawalReconciliationRequest $request)
    {
        try {
            $case = $this->service->request($request->user(), (int) $request->validated('withdrawal_id'),
                WithdrawalReconciliationFacts::fromArray($request->validated()), $request->file('evidence'));
        } catch (WithdrawalReconciliationException $exception) {
            return $this->refused($request, $exception->reason);
        } catch (FinancialException) {
            return $this->refused($request, 'dispatch_identity_or_financial_guard_refused');
        }

        return $request->expectsJson() ? (new WithdrawalReconciliationResource($case))->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store')
            : redirect()->route('admin.withdrawal-reconciliation.show', $case)->with('status', 'Evidence submitted. An independent authorized checker must verify it.');
    }

    public function decide(DecideWithdrawalReconciliationRequest $request, WithdrawalReconciliation $reconciliation)
    {
        try {
            $case = $this->service->decide($request->user(), $reconciliation, $request->validated('decision'),
                $request->validated('note'), $request->boolean('evidence_verified'));
        } catch (WithdrawalReconciliationException $exception) {
            return $this->refused($request, $exception->reason);
        } catch (FinancialException) {
            return $this->refused($request, 'dispatch_identity_or_financial_guard_refused');
        }

        return $request->expectsJson() ? (new WithdrawalReconciliationResource($case))->response()->header('Cache-Control', 'private, no-store')
            : redirect()->route('admin.withdrawal-reconciliation.show', $case)->with('status', 'Decision recorded. No transfer was sent.');
    }

    public function destination(Request $request, WithdrawalReconciliation $reconciliation)
    {
        try {
            $target = $this->service->destinationForReview($request->user(), $reconciliation);
        } catch (WithdrawalReconciliationException $exception) {
            return $this->refused($request, $exception->reason);
        } catch (FinancialException) {
            return $this->refused($request, 'dispatch_identity_or_financial_guard_refused');
        }

        return response()->json($target)->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function download(Request $request, WithdrawalReconciliation $reconciliation)
    {
        $this->access->authorize($request->user(), WithdrawalReconciliationAccess::VIEW);
        try {
            $bytes = $this->evidence->read($reconciliation->evidence_sha256, (int) $reconciliation->evidence_bytes);
        } catch (WithdrawalReconciliationException) {
            abort(409, 'Private evidence is unavailable or failed integrity verification.');
        }

        $audit = new AuditLog;
        $audit->fill(['user_id' => $request->user()->id, 'action' => AuditAction::DataExport,
            'risk_level' => RiskLevel::High, 'auditable_type' => WithdrawalReconciliation::class,
            'auditable_id' => $reconciliation->id, 'description' => 'Authorized private withdrawal evidence download.',
            'metadata' => ['reconciliation_id' => (int) $reconciliation->id, 'evidence_sha256' => $reconciliation->evidence_sha256]]);
        $audit->save();

        return response($bytes, 200, ['Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="withdrawal-evidence-'.$reconciliation->id.'.bin"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function refused(Request $request, string $reason)
    {
        return $request->expectsJson() ? response()->json(['message' => 'Reconciliation refused.', 'reason' => $reason], 409)->header('Cache-Control', 'private, no-store')
            : back()->withErrors(['reconciliation' => 'Reconciliation refused: '.$reason.'. No financial change was committed.']);
    }
}
