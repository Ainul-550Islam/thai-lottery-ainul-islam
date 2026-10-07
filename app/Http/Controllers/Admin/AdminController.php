<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\DTOs\Operations\AdminOperationData;
use App\Enums\AdminOperationType;
use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Admin\AdminOperationService;
use App\Services\Finance\FinancialReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Privileged Administrative Control Plane Controller.
 *
 * Enforces strict role authorization, two-person rule (maker/checker),
 * and immutable audit logging on all system mutations.
 */
class AdminController
{
    public function __construct(
        private readonly AdminOperationService $operations,
        private readonly FinancialReconciliationService $reconciliation,
    ) {}

    /**
     * View summary of operations.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $status = $request->query('status');
        $list = $this->operations->listFor($status);

        return ApiResponse::success(['operations' => $list], 'Admin operations retrieved.');
    }

    /**
     * Propose a sensitive admin operation (Maker action).
     */
    public function propose(Request $request): JsonResponse
    {
        $user = $this->authorizeAdmin($request);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:user_suspend,user_reactivate,compliance_flag'],
            'target_reference' => ['required', 'string', 'max:255'],
            'payload' => ['required', 'array'],
            'evidence_fingerprint' => ['nullable', 'string', 'max:128'],
        ]);

        $data = new AdminOperationData(
            actorUserId: (int) $user->id,
            type: AdminOperationType::from($validated['type']),
            targetReference: (string) $validated['target_reference'],
            payload: $validated['payload'],
            evidenceFingerprint: $validated['evidence_fingerprint'] ?? null,
        );

        $result = $this->operations->propose($data);

        return ApiResponse::success($result, 'Operation proposed successfully.', 201);
    }

    /**
     * Approve and queue an operation (Checker action - must not be maker).
     */
    public function approve(int $id, Request $request): JsonResponse
    {
        $user = $this->authorizeAdmin($request);
        $note = $request->input('note');

        $operation = $this->operations->approve($id, $user, $note);

        return ApiResponse::success(['operation' => $operation], 'Operation approved.');
    }

    /**
     * Execute an approved operation.
     */
    public function execute(int $id, Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $operation = $this->operations->execute($id);

        return ApiResponse::success(['operation' => $operation], 'Operation executed.');
    }

    /**
     * Trigger a system-wide financial reconciliation run.
     */
    public function runReconciliation(Request $request): JsonResponse
    {
        $user = $this->authorizeAdmin($request);

        $report = $this->reconciliation->reconcile(Carbon::today()->subDays(1), Carbon::today());

        AuditLog::create([
            'user_id' => $user->id,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Critical,
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'description' => 'admin_triggered_financial_reconciliation',
            'metadata' => [
                'discrepancies_count' => count($report->discrepancies),
                'reconciled_at' => now()->toIso8601String(),
            ],
        ]);

        return ApiResponse::success(['report' => $report], 'Financial reconciliation completed.');
    }

    private function authorizeAdmin(Request $request): User
    {
        $user = $request->user() ?? Auth::user();

        if ($user === null || ! ($user->isAdmin() || $user->isSuperAdmin())) {
            abort(403, 'Unauthorized administrative access.');
        }

        return $user;
    }
}
