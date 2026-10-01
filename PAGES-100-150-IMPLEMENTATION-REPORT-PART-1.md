# Pages 100–150 implementation report — Part 1

Files 1–15 of 19.

Runtime status: `NOT VERIFIED — RUNTIME UNAVAILABLE`.

Every listed file is reproduced in full from its first line to its last line. No file content is omitted.

## FILE 1: `app/Http/Controllers/Admin/LottoFinExecutiveDashboardController.php`

# TYPE: PHP controller
# PURPOSE: Extends the existing authorized admin projection lane with GLO claim/freeze rows and explicit fail-closed states for new operational routes.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Currency;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\GloPrizeClaim;
use App\Models\GloTicketFreeze;
use App\Models\KycDocument;
use App\Models\Wallet;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Account\AccountVerificationService;
use App\Services\Account\AccountVerificationDocumentService;
use App\Services\Finance\FinancialReconciliationService;
use App\Services\Finance\Money;
use App\Support\Admin\AdminAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * Read-only operations projections for the web console.
 *
 * Domain mutations remain in their existing canonical services and API lanes.
 * This controller never invents operational records and does not expose raw
 * model payloads to the browser.
 */
final class LottoFinExecutiveDashboardController extends Controller
{
    public function __construct(
        private readonly FinancialReconciliationService $reconciliationService,
        private readonly AccountVerificationService $accountVerification,
        private readonly AccountVerificationDocumentService $documents,
    ) {
    }

    public function index(Request $request, ?string $reference = null): View
    {
        $panel = $this->panelFor($request);
        $this->authorizePanel($request, $panel);

        $payload = match ($panel) {
            'draws' => ['records' => $this->drawProjection()],
            'bets' => ['records' => $this->betProjection()],
            'wallets' => ['records' => $this->walletProjection()],
            'ledger' => ['records' => $this->transactionProjection()],
            'audits' => ['records' => $this->auditProjection($request)],
            'kyc' => ['records' => $this->kycProjection($request->user())],
            'claims' => ['records' => $this->claimProjection($reference)],
            'freezes' => ['records' => $this->freezeProjection($reference)],
            default => [],
        };

        return view('admin.dashboard', [
            'panel' => $panel,
            'kpis' => $panel === 'dashboard' ? $this->calculateExecutiveKpis() : [],
            'transactions' => $panel === 'dashboard' ? $this->fetchFinancialFeed() : [],
            'records' => $payload['records'] ?? [],
            'state' => in_array($panel, [
                'reconciliation', 'compliance', 'risk', 'payments', 'withdrawals',
                'settlements', 'wallet_operations', 'payment_methods', 'withdrawal_methods',
                'payment_events', 'payment_exceptions', 'draw_lifecycle', 'publication',
                'imports', 'sources', 'lotteries', 'lottery_rules', 'fees', 'account_grades',
                'account_verification', 'responsible_gaming', 'self_exclusion', 'users',
                'user_detail', 'user_finance', 'bet_detail', 'ticket_detail',
                'ticket_verification', 'claim_review', 'commissions', 'health', 'queues',
                'scheduler', 'runtime', 'api_status', 'webhooks', 'security', 'release',
                'cutover',
            ], true)
                ? 'NOT_CONFIGURED'
                : null,
        ]);
    }

    public function analyticsApi(Request $request): JsonResponse
    {
        $this->authorizePanel($request, 'dashboard');
        [$from, $to] = $this->boundedPeriod($request);

        return response()->json([
            'status' => 'success',
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'kpis' => $this->calculateExecutiveKpis($from, $to),
        ]);
    }

    public function reconciliationFeedApi(Request $request): JsonResponse
    {
        $this->authorizePanel($request, 'reconciliation');

        // A bank/provider balance feed is not configured in this application.
        // Returning an explicit state is safer than presenting an internal
        // journal as if it were an external settlement balance.
        return response()->json([
            'status' => 'NOT_CONFIGURED',
            'data' => [],
        ], 200);
    }

    public function runReconciliation(Request $request): JsonResponse
    {
        $this->authorizePanel($request, 'reconciliation');
        [$from, $to] = $this->boundedPeriod($request);
        $operator = $request->user();

        $report = $this->reconciliationService->reconcile(
            from: $from->startOfDay(),
            to: $to->endOfDay(),
            initiatedBy: $operator !== null ? (string) $operator->getAuthIdentifier() : null,
        );

        return response()->json([
            'status' => 'success',
            'report' => $report->toArray(),
        ]);
    }

    public function downloadKyc(Request $request, string $documentToken): Response
    {
        $this->authorizePanel($request, 'kyc');
        $viewer = $request->user();

        if ($viewer === null) {
            abort(401);
        }

        $document = $this->accountVerification->documentForReviewer($viewer, $documentToken);
        if (! $document instanceof KycDocument) {
            abort(404);
        }

        $file = $this->documents->readForAuthorized($document, $viewer, true);

        return response($file['contents'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$file['name'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function reviewKyc(Request $request, string $documentToken): Response
    {
        $this->authorizePanel($request, 'kyc');
        $decision = str_contains((string) $request->route()?->getName(), '.reject') ? 'reject' : 'approve';
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $viewer = $request->user();

        if ($viewer === null) {
            abort(401);
        }

        $document = $this->accountVerification->documentForReviewer($viewer, $documentToken);
        if (! $document instanceof KycDocument) {
            abort(404);
        }

        $approved = $decision === 'approve';
        if (! in_array($decision, ['approve', 'reject'], true)) {
            abort(404);
        }

        $this->accountVerification->review(
            document: $document,
            reviewer: $viewer,
            approved: $approved,
            reason: isset($validated['reason']) ? (string) $validated['reason'] : null,
        );

        return redirect()->back()->with('status', trans('admin.review_recorded'));
    }

    public function unsupportedMutation(Request $request): JsonResponse
    {
        $panel = $this->panelFor($request);
        $this->authorizePanel($request, $panel);

        return response()->json([
            'status' => 'NOT_CONFIGURED',
            'message' => trans('admin.unsupported_mutation'),
        ], 409);
    }

    /**
     * @return array<string, string|int>
     */
    private function calculateExecutiveKpis(?Carbon $from = null, ?Carbon $to = null): array
    {
        $currency = Currency::tryFrom(strtoupper((string) config('finance.currency.default', Currency::THB->value))) ?? Currency::THB;
        $bets = Bet::query()
            ->where('currency', $currency->value)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->when($from !== null, static fn ($query) => $query->where('placed_at', '>=', $from))
            ->when($to !== null, static fn ($query) => $query->where('placed_at', '<=', $to));

        $hasBets = (clone $bets)->exists();
        $totalWagered = $hasBets ? $this->formatMoney((string) ((clone $bets)->sum('stake_amount')), $currency) : 'NO_DATA';

        $withdrawals = Withdrawal::query()
            ->where('currency', $currency->value)
            ->whereIn('status', ['completed', 'COMPLETED'])
            ->when($from !== null, static fn ($query) => $query->where('completed_at', '>=', $from))
            ->when($to !== null, static fn ($query) => $query->where('completed_at', '<=', $to));

        $activeBets = Bet::query()
            ->where('currency', $currency->value)
            ->whereIn('status', ['active', 'pending'])
            ->count();
        $pendingWithdrawals = Withdrawal::query()
            ->where('currency', $currency->value)
            ->whereIn('status', ['pending', 'under_review', 'kyc_required'])
            ->count();

        return [
            'totalWagered' => $totalWagered,
            'totalWageredTrend' => 'UNAVAILABLE',
            'houseGrossProfit' => 'UNAVAILABLE',
            'houseGrossProfitTrend' => 'UNAVAILABLE',
            'activeInPlayBets' => $activeBets,
            'activeInPlayBetsState' => $activeBets === 0 ? 'NO_DATA' : 'AVAILABLE',
            'pendingWithdrawals' => $pendingWithdrawals,
            'pendingWithdrawalsState' => $pendingWithdrawals === 0 ? 'NO_DATA' : 'AVAILABLE',
            'completedWithdrawalsTotal' => $withdrawals->exists()
                ? $this->formatMoney((string) $withdrawals->sum('amount'), $currency)
                : 'NO_DATA',
        ];
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function fetchFinancialFeed(): array
    {
        return FinancialTransaction::query()
            ->latest('id')
            ->limit(100)
            ->get(['reference_number', 'type', 'status', 'currency', 'amount', 'created_at'])
            ->map(static fn (FinancialTransaction $transaction): array => [
                'reference' => (string) $transaction->reference_number,
                'type' => $transaction->type instanceof \BackedEnum ? (string) $transaction->type->value : (string) $transaction->type,
                'status' => $transaction->status instanceof \BackedEnum ? (string) $transaction->status->value : (string) $transaction->status,
                'currency' => $transaction->currency instanceof \BackedEnum ? (string) $transaction->currency->value : (string) $transaction->currency,
                'amount' => (string) $transaction->amount,
                'created_at' => $transaction->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drawProjection(): array
    {
        return Draw::query()->latest('scheduled_at')->limit(50)->get([
            'draw_number', 'scheduled_at', 'status', 'type',
        ])->map(static fn (Draw $draw): array => [
            'draw' => (string) $draw->draw_number,
            'date' => $draw->scheduled_at?->toIso8601String(),
            'status' => $draw->status instanceof \BackedEnum ? (string) $draw->status->value : (string) $draw->status,
            'type' => $draw->type instanceof \BackedEnum ? (string) $draw->type->value : (string) $draw->type,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function betProjection(): array
    {
        return Bet::query()->latest('id')->limit(100)->get([
            'bet_number', 'stake_amount', 'currency', 'status', 'placed_at',
        ])->map(static fn (Bet $bet): array => [
            'reference' => (string) $bet->bet_number,
            'amount' => (string) $bet->stake_amount,
            'currency' => $bet->currency instanceof \BackedEnum ? (string) $bet->currency->value : (string) $bet->currency,
            'status' => $bet->status instanceof \BackedEnum ? (string) $bet->status->value : (string) $bet->status,
            'created_at' => $bet->placed_at?->toIso8601String(),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function walletProjection(): array
    {
        return Wallet::query()->latest('id')->limit(100)->get([
            'id', 'currency', 'balance', 'locked_balance', 'status',
        ])->map(static fn (Wallet $wallet): array => [
            'reference' => 'UNAVAILABLE',
            'amount' => (string) $wallet->balance,
            'locked_amount' => (string) $wallet->locked_balance,
            'currency' => $wallet->currency instanceof \BackedEnum ? (string) $wallet->currency->value : (string) $wallet->currency,
            'status' => $wallet->status instanceof \BackedEnum ? (string) $wallet->status->value : (string) $wallet->status,
            'created_at' => $wallet->created_at?->toIso8601String(),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function transactionProjection(): array
    {
        return $this->fetchFinancialFeed();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function auditProjection(Request $request): array
    {
        return AuditLog::query()->latest('id')->limit(100)->get([
            'id', 'action', 'risk_level', 'auditable_type', 'auditable_id', 'created_at',
        ])->map(static fn (AuditLog $audit): array => [
            'reference' => 'UNAVAILABLE',
            'type' => $audit->action instanceof \BackedEnum ? (string) $audit->action->value : (string) $audit->action,
            'status' => $audit->risk_level instanceof \BackedEnum ? (string) $audit->risk_level->value : (string) $audit->risk_level,
            'currency' => null,
            'amount' => null,
            'created_at' => $audit->created_at?->toIso8601String(),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function kycProjection(?User $reviewer): array
    {
        if (! $reviewer instanceof User) {
            return [];
        }

        return KycDocument::query()->latest('id')->limit(100)->get([
            'id', 'user_id', 'document_type', 'status', 'created_at',
        ])->map(function (KycDocument $document): array {
            $token = $this->accountVerification->reviewerDocumentToken($document, $reviewer);

            return [
                'reference' => 'UNAVAILABLE',
                'type' => $document->document_type instanceof \BackedEnum ? (string) $document->document_type->value : (string) $document->document_type,
                'status' => $document->status instanceof \BackedEnum ? (string) $document->status->value : (string) $document->status,
                'currency' => null,
                'amount' => null,
                'created_at' => $document->created_at?->toIso8601String(),
                'download_url' => route('admin.kyc.download', ['documentToken' => $token]),
                'approve_url' => route('admin.kyc.approve', ['documentToken' => $token]),
                'reject_url' => route('admin.kyc.reject', ['documentToken' => $token]),
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function claimProjection(?string $reference = null): array
    {
        return GloPrizeClaim::query()
            ->when($reference !== null, static fn ($query) => $query->where('claim_reference', $reference))
            ->latest('id')
            ->limit($reference !== null ? 1 : 100)
            ->get([
                'claim_reference', 'ticket_number', 'draw_id', 'product', 'prize_category',
                'gross_prize', 'stamp_duty', 'net_prize', 'status', 'payment_status',
                'hold_status', 'age_verification_result', 'submitted_at', 'updated_at',
            ])
            ->map(static fn (GloPrizeClaim $claim): array => [
                'reference' => (string) $claim->claim_reference,
                'type' => (string) $claim->prize_category,
                'status' => $claim->status->label(),
                'amount' => (string) $claim->net_prize,
                'currency' => 'THB',
                'created_at' => $claim->submitted_at?->toIso8601String(),
                'ticket' => (string) ($claim->ticket_number ?? 'UNAVAILABLE'),
                'draw' => (string) $claim->draw_id,
                'gross' => (string) $claim->gross_prize,
                'stamp_duty' => (string) $claim->stamp_duty,
                'payment_status' => (string) $claim->payment_status,
                'hold_status' => (string) $claim->hold_status,
                'age_state' => (string) $claim->age_verification_result,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function freezeProjection(?string $reference = null): array
    {
        return GloTicketFreeze::query()
            ->when($reference !== null, static fn ($query) => $query->where('freeze_case_id', $reference))
            ->latest('id')
            ->limit($reference !== null ? 1 : 100)
            ->get([
                'freeze_case_id', 'ticket_number', 'draw_id', 'product', 'status',
                'requesting_authority', 'case_reference', 'evidence_reference',
                'requested_at', 'effective_at', 'expiry_at', 'released_at',
            ])
            ->map(static fn (GloTicketFreeze $freeze): array => [
                'reference' => (string) $freeze->freeze_case_id,
                'type' => (string) $freeze->product,
                'status' => $freeze->status->label(),
                'amount' => 'UNAVAILABLE',
                'currency' => null,
                'created_at' => $freeze->requested_at?->toIso8601String(),
                'ticket' => (string) ($freeze->ticket_number ?? 'UNAVAILABLE'),
                'draw' => (string) $freeze->draw_id,
                'authority' => (string) $freeze->requesting_authority,
                'case_reference' => (string) $freeze->case_reference,
                'evidence_reference' => (string) $freeze->evidence_reference,
                'effective_at' => $freeze->effective_at?->toIso8601String(),
                'expiry_at' => $freeze->expiry_at?->toIso8601String(),
                'released_at' => $freeze->released_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function boundedPeriod(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $to = Carbon::createFromFormat('Y-m-d', (string) ($validated['to'] ?? now()->toDateString()))->endOfDay();
        $from = Carbon::createFromFormat('Y-m-d', (string) ($validated['from'] ?? $to->copy()->subDays(30)->toDateString()))->startOfDay();

        if ($from->greaterThan($to) || $from->diffInDays($to) > 31) {
            abort(422, trans('admin.analytics_range_invalid'));
        }

        return [$from, $to];
    }

    private function panelFor(Request $request): string
    {
        $name = (string) $request->route()?->getName();

        return match (true) {
            str_contains($name, 'draws') => 'draws',
            str_contains($name, 'risk') => 'risk',
            str_contains($name, 'bets.show') => 'bet_detail',
            str_contains($name, 'bets') => 'bets',
            str_contains($name, 'wallets') => 'wallets',
            str_contains($name, 'ledger') => 'ledger',
            str_contains($name, 'reconciliation') => 'reconciliation',
            str_contains($name, 'audits') => 'audits',
            str_contains($name, 'kyc') => 'kyc',
            str_contains($name, 'payments-events') || str_contains($name, 'payment-events') => 'payment_events',
            str_contains($name, 'payments-exceptions') || str_contains($name, 'payment-exceptions') => 'payment_exceptions',
            str_contains($name, 'payments') => 'payments',
            str_contains($name, 'withdrawals') => 'withdrawals',
            str_contains($name, 'compliance') => 'compliance',
            str_contains($name, 'prize-claims') || str_contains($name, 'claim-review') => 'claims',
            str_contains($name, 'ticket-freezes') || str_contains($name, 'ticket-freeze') => 'freezes',
            str_contains($name, 'settlements') => 'settlements',
            str_contains($name, 'wallet-operations') => 'wallet_operations',
            str_contains($name, 'payment-methods') => 'payment_methods',
            str_contains($name, 'withdrawal-methods') => 'withdrawal_methods',
            str_contains($name, 'payment-events') => 'payment_events',
            str_contains($name, 'payment-exceptions') => 'payment_exceptions',
            str_contains($name, 'draw-lifecycle') => 'draw_lifecycle',
            str_contains($name, 'result-publication') => 'publication',
            str_contains($name, 'result-imports') => 'imports',
            str_contains($name, 'result-sources') => 'sources',
            str_contains($name, 'lotteries') => 'lotteries',
            str_contains($name, 'lottery-rules') => 'lottery_rules',
            str_contains($name, 'fees') => 'fees',
            str_contains($name, 'account-grades') => 'account_grades',
            str_contains($name, 'account-verification') => 'account_verification',
            str_contains($name, 'responsible-gaming') => 'responsible_gaming',
            str_contains($name, 'self-exclusion') => 'self_exclusion',
            str_contains($name, 'user-finance') => 'user_finance',
            str_contains($name, 'users.finance') => 'user_finance',
            str_contains($name, 'users.show') => 'user_detail',
            str_contains($name, 'users') => 'users',
            str_contains($name, 'bet-detail') => 'bet_detail',
            str_contains($name, 'ticket-detail') => 'ticket_detail',
            str_contains($name, 'tickets.show') => 'ticket_detail',
            str_contains($name, 'ticket-verification') => 'ticket_verification',
            str_contains($name, 'queues') => 'queues',
            str_contains($name, 'scheduler') => 'scheduler',
            str_contains($name, 'runtime') => 'runtime',
            str_contains($name, 'api-status') => 'api_status',
            str_contains($name, 'webhooks') => 'webhooks',
            str_contains($name, 'security') => 'security',
            str_contains($name, 'release') => 'release',
            str_contains($name, 'cutover') => 'cutover',
            str_contains($name, 'health') => 'health',
            default => 'dashboard',
        };
    }

    private function authorizePanel(Request $request, string $panel): void
    {
        $user = $request->user();
        $permission = match ($panel) {
            'draws' => AdminAccess::VIEW_DRAWS,
            'risk', 'compliance' => AdminAccess::VIEW_RISK_ALERTS,
            'payments', 'withdrawals' => AdminAccess::MANAGE_PAYOUTS,
            'bets' => AdminAccess::VIEW_TRANSACTION_HISTORY,
            'audits' => AdminAccess::VIEW_AUDIT_LOGS,
            'wallets' => AdminAccess::MANAGE_WALLET,
            'ledger' => AdminAccess::VIEW_FINANCIAL_REPORTS,
            'reconciliation' => AdminAccess::RECONCILE_LEDGER,
            'kyc' => AdminAccess::MANAGE_USERS,
            'claims', 'claim_review' => AdminAccess::MANAGE_GLO_PRIZE_CLAIMS,
            'freezes' => AdminAccess::REVIEW_GLO_FREEZES,
            'settlements' => AdminAccess::PROCESS_SETTLEMENTS,
            'wallet_operations' => AdminAccess::MANAGE_WALLET,
            'payment_methods', 'withdrawal_methods', 'payment_events', 'payment_exceptions' => AdminAccess::MANAGE_PAYOUTS,
            'draw_lifecycle' => AdminAccess::MANAGE_DRAWS,
            'publication', 'imports', 'sources' => AdminAccess::VIEW_RESULTS,
            'lotteries', 'lottery_rules', 'fees', 'account_grades' => AdminAccess::MANAGE_SYSTEM_SETTINGS,
            'account_verification', 'responsible_gaming', 'self_exclusion', 'users', 'user_detail', 'user_finance', 'bet_detail', 'ticket_detail', 'ticket_verification' => AdminAccess::MANAGE_USERS,
            'commissions' => AdminAccess::VIEW_COMMISSIONS,
            'queues', 'scheduler', 'runtime', 'api_status', 'webhooks', 'security', 'release', 'cutover', 'health' => AdminAccess::VIEW_AUDIT_LOGS,
            default => AdminAccess::VIEW_DASHBOARD,
        };

        if (! AdminAccess::canAccessPanel($user) || ! AdminAccess::allows($user, $permission)) {
            abort(403, trans('admin.access_denied'));
        }
    }

    private function formatMoney(string $amount, Currency $currency): string
    {
        try {
            return Money::fromDatabase($amount, $currency)->format().' '.$currency->value;
        } catch (\Throwable $exception) {
            report($exception);

            return 'UNAVAILABLE';
        }
    }
}

```

## FILE 2: `app/Http/Controllers/Agent/AgentPortalController.php`

# TYPE: PHP controller
# PURPOSE: Authenticated owner-scoped agent dashboard, commission, settlement, statement, referral, and referral-detail projections.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\User;
use App\Services\Agent\AgentReportingService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Authenticated agent portal projection controller.
 *
 * The authenticated session resolves the agent owner. No route parameter,
 * hidden field, or client-supplied user ID is used to select an agent. This
 * controller is read-only: commission accrual, settlement, referral
 * attribution, and wallet credit remain in their canonical services.
 */
final class AgentPortalController extends Controller
{
    public function __construct(
        private readonly AgentReportingService $reporting,
    ) {
    }

    public function dashboard(Request $request): View
    {
        $agent = $this->agentFor($request);
        $report = $this->reporting->getAgentReport($agent);

        return view('agent.portal', [
            'surface' => 'dashboard',
            'agent' => $agent,
            'report' => $report,
            'records' => [],
            'state' => 'AVAILABLE',
        ]);
    }

    public function commissions(Request $request): View
    {
        $agent = $this->agentFor($request);
        $records = AgentCommission::query()
            ->where('agent_id', $agent->getKey())
            ->latest('id')
            ->limit(100)
            ->get([
                'reference_number', 'status', 'currency', 'base_amount',
                'commission_rate', 'commission_amount', 'accrued_at', 'paid_at',
            ])
            ->map(static fn (AgentCommission $commission): array => [
                'reference' => (string) $commission->reference_number,
                'status' => $commission->status->value,
                'currency' => $commission->currency?->value,
                'basis' => (string) $commission->base_amount,
                'rate' => (string) $commission->commission_rate,
                'amount' => (string) $commission->commission_amount,
                'created_at' => $commission->accrued_at?->toIso8601String(),
                'paid_at' => $commission->paid_at?->toIso8601String(),
            ])
            ->all();

        return view('agent.portal', [
            'surface' => 'commissions',
            'agent' => $agent,
            'report' => null,
            'records' => $records,
            'state' => $records === [] ? 'NO_DATA' : 'AVAILABLE',
        ]);
    }

    public function settlements(Request $request): View
    {
        $agent = $this->agentFor($request);
        $records = AgentCommission::query()
            ->where('agent_id', $agent->getKey())
            ->whereNotNull('paid_at')
            ->latest('paid_at')
            ->limit(100)
            ->get([
                'reference_number', 'base_amount', 'commission_amount',
                'currency', 'status', 'paid_at',
            ])
            ->map(static fn (AgentCommission $commission): array => [
                'reference' => (string) $commission->reference_number,
                'gross' => (string) $commission->base_amount,
                'net' => (string) $commission->commission_amount,
                'currency' => $commission->currency?->value,
                'status' => $commission->status->value,
                'paid_at' => $commission->paid_at?->toIso8601String(),
            ])
            ->all();

        return view('agent.portal', [
            'surface' => 'settlements',
            'agent' => $agent,
            'report' => null,
            'records' => $records,
            'state' => $records === [] ? 'NO_DATA' : 'AVAILABLE',
        ]);
    }

    public function statement(Request $request): View
    {
        $agent = $this->agentFor($request);
        $records = AgentCommission::query()
            ->where('agent_id', $agent->getKey())
            ->latest('id')
            ->limit(100)
            ->get([
                'reference_number', 'status', 'commission_amount', 'currency',
                'created_at', 'paid_at',
            ])
            ->map(static fn (AgentCommission $commission): array => [
                'reference' => (string) $commission->reference_number,
                'type' => 'COMMISSION',
                'amount' => (string) $commission->commission_amount,
                'currency' => $commission->currency?->value,
                'status' => $commission->status->value,
                'created_at' => $commission->created_at?->toIso8601String(),
                'paid_at' => $commission->paid_at?->toIso8601String(),
            ])
            ->all();

        return view('agent.portal', [
            'surface' => 'statement',
            'agent' => $agent,
            'report' => null,
            'records' => $records,
            'state' => $records === [] ? 'NO_DATA' : 'AVAILABLE',
        ]);
    }

    public function referrals(Request $request): View
    {
        $agent = $this->agentFor($request);
        $users = User::query()
            ->whereJsonContains('preferences->referred_by_agent_id', $agent->getKey())
            ->latest('id')
            ->limit(100)
            ->get(['id', 'status', 'created_at'])
            ->map(static fn (User $user): array => [
                'reference' => hash('sha256', 'agent-referral|'.$agent->getKey().'|'.$user->getKey()),
                'status' => $user->status->value,
                'created_at' => $user->created_at?->toIso8601String(),
            ])
            ->all();

        return view('agent.portal', [
            'surface' => 'referrals',
            'agent' => $agent,
            'report' => null,
            'records' => $users,
            'state' => $users === [] ? 'NO_DATA' : 'AVAILABLE',
        ]);
    }

    public function referralDetail(Request $request, string $reference): View
    {
        $agent = $this->agentFor($request);
        $user = User::query()
            ->whereJsonContains('preferences->referred_by_agent_id', $agent->getKey())
            ->get(['id', 'status', 'created_at'])
            ->first(static fn (User $candidate): bool => hash_equals(
                hash('sha256', 'agent-referral|'.$agent->getKey().'|'.$candidate->getKey()),
                $reference,
            ));

        abort_unless($user instanceof User, 404);

        return view('agent.portal', [
            'surface' => 'referral-detail',
            'agent' => $agent,
            'report' => null,
            'records' => [[
                'reference' => $reference,
                'status' => $user->status->value,
                'created_at' => $user->created_at?->toIso8601String(),
            ]],
            'state' => 'AVAILABLE',
        ]);
    }

    private function agentFor(Request $request): Agent
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $agent = Agent::query()
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->first();

        abort_unless($agent instanceof Agent && $agent->canEarnCommission(), 403);

        return $agent;
    }
}

```

## FILE 3: `app/Http/Controllers/NotificationCenterController.php`

# TYPE: PHP controller
# PURPOSE: Read-only authenticated owner-scoped notification center using the existing notification model/API architecture.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Owner-scoped notification center projection.
 *
 * Reads only the authenticated user's notification rows. Mark-as-read remains
 * on the existing API controller; this page does not create a browser-only
 * notification mutation.
 */
final class NotificationCenterController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $records = Notification::query()
            ->where('user_id', $user->getKey())
            ->latest('id')
            ->limit(100)
            ->get([
                'id', 'event_type', 'channel', 'priority', 'status',
                'subject', 'body', 'created_at', 'read_at', 'expires_at',
            ])
            ->map(static fn (Notification $notification): array => [
                'reference' => hash('sha256', 'notification|'.$user->getKey().'|'.$notification->getKey()),
                'type' => $notification->event_type?->value,
                'channel' => $notification->channel?->value,
                'priority' => $notification->priority?->value,
                'status' => $notification->status?->value,
                'subject' => $notification->subject,
                'body' => $notification->body,
                'created_at' => $notification->created_at?->toIso8601String(),
                'read_at' => $notification->read_at?->toIso8601String(),
                'expires_at' => $notification->expires_at?->toIso8601String(),
            ])
            ->all();

        return view('notifications/index', [
            'records' => $records,
            'state' => $records === [] ? 'NO_DATA' : 'AVAILABLE',
        ]);
    }
}

```

## FILE 4: `app/Http/Controllers/Support/SupportPortalController.php`

# TYPE: PHP controller
# PURPOSE: Authenticated support boundary that fails closed because anonymous ContactMessage rows have no owner contract.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Authenticated support portal boundary.
 *
 * The existing contact-message model is intentionally anonymous and has no
 * owner_user_id. It therefore cannot safely be presented as a player's private
 * inbox. These routes fail closed until an owner-scoped support case contract
 * exists; the public contact workflow remains the supported submission lane.
 */
final class SupportPortalController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user() instanceof User, 401);

        return view('support.portal', [
            'surface' => 'index',
            'state' => 'NOT_CONFIGURED',
            'reference' => null,
            'records' => [],
        ]);
    }

    public function show(Request $request, string $reference): View
    {
        abort_unless($request->user() instanceof User, 401);

        return view('support.portal', [
            'surface' => 'detail',
            'state' => 'NOT_CONFIGURED',
            'reference' => $reference,
            'records' => [],
        ]);
    }
}

```

## FILE 5: `routes/web.php`

# TYPE: PHP route file
# PURPOSE: Adds Pages 100–150 operational routes, authenticated agent routes, support routes, and notification center routes without removing existing endpoints.

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\LottoFinExecutiveDashboardController;
use App\Http\Controllers\Agent\AgentPortalController;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\Support\SupportPortalController;
use App\Http\Controllers\GloL6Controller;
use App\Http\Controllers\GloResultsPageController;
use App\Http\Controllers\Player\PlayerSecuritySettingsController;
use App\Http\Controllers\Betting\ThaiLotteryBettingController;
use App\Http\Controllers\AccountGradeController;
use App\Http\Controllers\BingoLotteryController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LottoDiscountController;
use App\Http\Controllers\LotteryHubController;
use App\Http\Controllers\LotteryPurchasePageController;
use App\Http\Controllers\LegacyRedirectController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\NationalLotteryController;
use App\Http\Controllers\PcsoLotteryController;
use App\Http\Controllers\PrizeVerificationController;
use App\Http\Controllers\PublicAccountInfoController;
use App\Http\Controllers\PublicPagesController;
use App\Http\Controllers\PublicServicePagesController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\Auth\MemberAuthController;
use App\Http\Controllers\Verification\AccountVerificationController as MemberAccountVerificationController;
use App\Http\Controllers\Web\BetPurchaseController;
use App\Http\Controllers\Web\PaymentCallbackController;
use App\Http\Controllers\Web\PlayerWebController;
use App\Http\Controllers\WeeklyLotteryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The session-authenticated player web app. Laravel's default web middleware group is
| applied automatically (CSRF, session, cookies), plus the global security headers and
| correlation id middleware registered in bootstrap/app.php.
|
| Every route name here is what the Blade views and the player experience tests already
| reference, so the names are part of the contract:
|   login, login.attempt, register, register.attempt, logout,
|   player.dashboard, player.draws, player.draws.detail, player.bet, player.bets,
|   player.wallet, player.deposit, player.deposit.store, player.withdraw,
|   player.withdraw.store, player.profile, player.profile.update, player.profile.password,
|   player.profile.limits, player.bets.purchase, player.password.update, player.limits.update
|
*/

/*
| Operational endpoints. `/up` is the framework liveness probe registered in
| bootstrap/app.php; the structured health trio and the Prometheus metrics export live
| here against the same HealthController / MetricsController that the observability
| services back.
*/
// P0: /metrics is operator-only telemetry — never financial-public.
Route::middleware(['auth', 'can:access-metrics'])->group(function (): void {
    Route::get('/metrics', [MetricsController::class, 'metrics'])->name('metrics');
});
Route::get('/up/health', [HealthController::class, 'health'])->name('health');
Route::get('/up/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/up/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health', [HealthController::class, 'health'])->name('health.canonical');
Route::get('/ready', [HealthController::class, 'ready'])->name('health.ready.canonical');
Route::get('/live', [HealthController::class, 'live'])->name('health.live.canonical');

Route::middleware('guest')->group(function (): void {
    // PROMPT 3: the member auth surface (login / registration /
    // password recovery) is served by MemberAuthController — thin
    // orchestration over LoginService / RegistrationService /
    // PasswordResetService (+ the server-authoritative CaptchaService
    // gate). Same route names as before, so every existing link,
    // redirect and test keeps resolving.
    Route::get('/login', [MemberAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [MemberAuthController::class, 'login'])->name('login.attempt')->middleware('throttle:login');
    Route::get('/register', [MemberAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [MemberAuthController::class, 'register'])->name('register.attempt')->middleware('throttle:login');

    // Password recovery: account no./email + CAPTCHA request, then the
    // token-gated new-password form. Throttled on both POSTs.
    Route::get('/forgot-password', [MemberAuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [MemberAuthController::class, 'requestReset'])
        ->middleware('throttle:password-reset')
        ->name('password.request.attempt');
    Route::get('/reset-password/{token}', [MemberAuthController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [MemberAuthController::class, 'resetPassword'])
        ->middleware('throttle:password-reset')
        ->name('password.reset.attempt');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [MemberAuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', [PlayerWebController::class, 'dashboard'])->name('player.dashboard');
    Route::get('/draws', [PlayerWebController::class, 'draws'])->name('player.draws');
    Route::get('/draws/{id}', [PlayerWebController::class, 'drawDetail'])->name('player.draws.detail');

    Route::get('/bet', [PlayerWebController::class, 'betSlip'])->name('player.bet');
    Route::post('/bet/purchase', [BetPurchaseController::class, 'store'])
        ->middleware('throttle:bet')
        ->name('player.bets.purchase');
    Route::post('/player/bets/purchase', [BetPurchaseController::class, 'store'])
        ->middleware('throttle:bet')
        ->name('player.bets.purchase.alias');
    Route::get('/bets', [PlayerWebController::class, 'bets'])->name('player.bets');

    Route::get('/wallet', [PlayerWebController::class, 'wallet'])->name('player.wallet');

    Route::get('/deposit', [PlayerWebController::class, 'deposit'])->name('player.deposit');
    Route::get('/deposit/status/{deposit}', [PlayerWebController::class, 'depositStatus'])
        ->where('deposit', '[A-Za-z0-9_\\-]{1,80}')
        ->name('player.deposit.status');
    Route::post('/deposit', [PlayerWebController::class, 'storeDeposit'])
        ->middleware('throttle:deposit')
        ->name('player.deposit.store');

    Route::get('/withdraw', [PlayerWebController::class, 'withdraw'])->name('player.withdraw');
    Route::get('/withdrawal/status/{withdrawal}', [PlayerWebController::class, 'withdrawalStatus'])
        ->where('withdrawal', '[A-Za-z0-9_\\-]{1,80}')
        ->name('player.withdrawal.status');
    Route::post('/withdraw', [PlayerWebController::class, 'storeWithdraw'])
        ->middleware('throttle:withdrawal')
        ->name('player.withdraw.store');

    Route::get('/profile', [PlayerWebController::class, 'profile'])->name('player.profile');
    Route::put('/profile', [PlayerWebController::class, 'updateProfile'])->name('player.profile.update');
    Route::put('/profile/password', [PlayerWebController::class, 'updatePassword'])->name('player.password.update');
    Route::put('/player/profile/password', [PlayerWebController::class, 'updatePassword'])->name('player.profile.password');
    Route::put('/profile/limits', [PlayerWebController::class, 'updateLimits'])->name('player.limits.update');
    Route::put('/player/profile/limits', [PlayerWebController::class, 'updateLimits'])->name('player.profile.limits');
    Route::post('/player/self-exclusion', [PlayerWebController::class, 'storeSelfExclusion'])
        ->middleware('throttle:account-grade')
        ->name('player.self-exclusion.store');
});

/*
|---------------------------------------------------------------------------
| Account services (PROMPT 3): verification + grade — authenticated only
|---------------------------------------------------------------------------
| Ownership is always the session user. Rate limits: account-verification /
| account-grade (registered in AppServiceProvider).
*/
Route::middleware('auth')->group(function (): void {
    // PROMPT 3: the member Account Verify page is served by the
    // Verification controller (policy-authorized, self-scoped, the
    // immutable submission aggregate behind it). The reviewer decision
    // route is policy-walled (AccountVerificationPolicy::decide).
    Route::get('/account/verification', [MemberAccountVerificationController::class, 'show'])
        ->name('account.verification');
    Route::post('/account/verification', [MemberAccountVerificationController::class, 'submit'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.submit');
    Route::get('/account/verification/document/{documentToken}', [MemberAccountVerificationController::class, 'download'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.document');
    Route::post('/account/verification/{verification}/decision', [MemberAccountVerificationController::class, 'decide'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.decide');

    Route::get('/account/grade', [AccountGradeController::class, 'show'])
        ->middleware('throttle:account-grade')
        ->name('account.grade');
    Route::get('/account/grade/history', [AccountGradeController::class, 'history'])
        ->middleware('throttle:account-grade')
        ->name('account.grade.history');
    Route::post('/account/grade/refresh', [AccountGradeController::class, 'refresh'])
        ->middleware('throttle:account-grade')
        ->name('account.grade.refresh');
});

/*
|--------------------------------------------------------------------------
| Public Home + supporting public pages (anonymous by design)
|--------------------------------------------------------------------------
| Results are served from the verified projection only; fixture datasets are
| labeled FIXTURE_ONLY and are never called official.
*/

Route::get('/', [HomeController::class, 'index'])->name('home');

// Legacy aliases deliberately redirect into the authenticated canonical player
// routes. They do not render a second wallet, deposit, withdrawal or dashboard
// implementation and therefore cannot expose presentation-only financial data.
Route::get('/player/dashboard', fn () => redirect()->route('player.dashboard'))->name('player.dashboard.legacy');
Route::get('/player/wallet', fn () => redirect()->route('player.wallet'))->name('player.wallet.legacy');
Route::get('/wallet/deposit', fn () => redirect()->route('player.deposit'))->name('wallet.deposit');
Route::get('/withdrawal', fn () => redirect()->route('player.withdraw'))->name('withdrawal.index');
Route::get('/wallet/withdrawal', fn () => redirect()->route('player.withdraw'))->name('wallet.withdrawal');
Route::get('/betting', [ThaiLotteryBettingController::class, 'index'])->name('betting.index');
Route::get('/lotto/betting', [ThaiLotteryBettingController::class, 'index'])->name('lotto.betting');
// Dedicated GLO L6 home. It uses the canonical public GLO services and is
// intentionally separate from the legacy /results page, whose historical
// controller is not a source for live GLO data.
Route::get('/glo-l6', [GloL6Controller::class, 'index'])
    ->middleware('public.legal')
    ->name('glo-l6.index');
Route::get('/glo-l6/buy', [GloL6Controller::class, 'buy'])
    ->middleware('public.legal')
    ->name('glo-l6.buy');
Route::get('/glo-l6/latest', [GloL6Controller::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('glo-l6.latest');
Route::get('/glo-l6/history', [GloL6Controller::class, 'history'])
    ->middleware('public.legal')
    ->name('glo-l6.history');
Route::get('/glo-l6/year/{year}', [GloL6Controller::class, 'year'])
    ->where('year', '[0-9]{4}')
    ->middleware('public.legal')
    ->name('glo-l6.year');
Route::get('/glo-l6/draw/{draw}', [GloL6Controller::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9_\\-]{1,64}')
    ->middleware('public.legal')
    ->name('glo-l6.draw');
Route::get('/glo-l6/result/{draw}', [GloL6Controller::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9_\\-]{1,64}')
    ->middleware('public.legal')
    ->name('glo-l6.result');

Route::get('/results', [GloResultsPageController::class, 'index'])->name('results.index');

// Account and protection aliases are authenticated. They delegate to the
// canonical player/profile, responsible-gaming and security architecture;
// legacy guest pages are not allowed to invent account state.
Route::middleware('auth')->group(function (): void {
    Route::get('/player/security', [PlayerSecuritySettingsController::class, 'index'])->name('player.security');
    Route::get('/player/settings', [PlayerSecuritySettingsController::class, 'index'])->name('player.settings');
    Route::get('/settings', [PlayerWebController::class, 'responsibleGaming'])->name('settings.index');
    Route::get('/member/settings', [PlayerWebController::class, 'responsibleGaming'])->name('member.settings');
    Route::get('/player/settings-portal', [PlayerWebController::class, 'responsibleGaming'])->name('player.settings.portal');
    Route::get('/member/profile', fn () => redirect()->route('player.profile'))->name('member.profile');
    Route::get('/player/profile-portal', fn () => redirect()->route('player.profile'))->name('player.profile.portal');
});
Route::middleware('auth')->group(function (): void {
    Route::get('/history', fn () => redirect()->route('player.bets'))->name('history.index');
    Route::get('/member/history', fn () => redirect()->route('player.bets'))->name('member.history');
    Route::get('/player/history-portal', fn () => redirect()->route('player.bets'))->name('player.history.portal');
});
Route::get('/results/search', [ResultsController::class, 'search'])->name('results.search');

// Public ticket check UI (primary UX; the JSON API remains at /api/v1/glo/results/check/{n}).
Route::get('/check', [HomeController::class, 'checkForm'])->name('ticket-check');
Route::post('/check', [HomeController::class, 'checkSubmit'])
    ->middleware('throttle:home-check')
    ->name('ticket-check.submit');

// Public sales-point search UI (uses existing GloSalesPointService).
Route::get('/sales-points', [HomeController::class, 'salesPoints'])->name('sales-points');

// Public informational + legal pages (versioned Terms from config/legal.php).
// public.legal = PublicLegalHeaders middleware: safe guest GET cache only.
Route::get('/about', [PublicPagesController::class, 'about'])
    ->middleware('public.legal')
    ->name('about');
Route::get('/vision', [PublicPagesController::class, 'vision'])
    ->middleware('public.legal')
    ->name('vision');
Route::get('/terms', [PublicPagesController::class, 'terms'])
    ->middleware('public.legal')
    ->name('terms');

// Public Fees (PROMPT 3) — anonymous, config-driven, no user-specific fees.
Route::get('/fees', [PublicPagesController::class, 'fees'])
    ->middleware('public.legal')
    ->name('fees');
Route::get('/our-fees', [PublicPagesController::class, 'fees'])
    ->middleware('public.legal')
    ->name('our-fees');

// Public Prize Verification (PROMPT 4) — anonymous ticket / result checker.
Route::get('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('prize-verification');
Route::post('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'verifyApi'])
    ->middleware('throttle:ticket-verification')
    ->name('prize-verification.verify');
Route::post('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'verifyApi'])
    ->middleware('throttle:ticket-verification')
    ->name('prize-verification.submit');

// Public Discount Rules (PROMPT 4) — anonymous product/game matrix.
Route::get('/discounts', [\App\Http\Controllers\PublicLottoDiscountController::class, 'index'])
    ->middleware('public.legal')
    ->name('discounts');
Route::get('/lotto-discount', [\App\Http\Controllers\PublicLottoDiscountController::class, 'index'])
    ->middleware('public.legal')
    ->name('lotto-discount');

// Public How to Play Guide
Route::get('/how-to-play', [\App\Http\Controllers\PublicHowToPlayController::class, 'index'])
    ->middleware('public.legal')
    ->name('how-to-play');

// Public FAQ / Knowledge Base
Route::get('/faq', [\App\Http\Controllers\PublicFaqController::class, 'index'])
    ->middleware('public.legal')
    ->name('faq');

/*
|--------------------------------------------------------------------------
| PROMPT 5: public National Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These four routes serve national_lottery_* data and
| nothing else: not GLO L6/N3, not an operator market, not a lottery provider
| that has not published. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search, /buy, /latest, /history, /year/{year},
| /archive/{year}, /draw/{draw} and /result/{draw} are declared BEFORE /{draw}.
| Reversed, the wildcard would capture a literal page segment and turn it into
| a draw lookup.
|
| PARAMETER PATTERNS ARE HARD BOUNDARIES. {year} is at most four decimal
| digits and {draw} is at most forty characters of an explicit alphabet, so a
| hostile URL is refused by the router before a controller, a validator or a
| query is ever reached.
|
| /search carries throttle:national-result-search (registered in
| AppServiceProvider from config('national_lottery.rate_limit')): IP per
| minute, IP per hour, and a hashed query fingerprint per minute. robots.txt
| asks crawlers to stay out of the same path, but that is a request - this
| limiter is the control.
|
| public.legal = PublicLegalHeaders: short guest-GET cache headers on the
| listed cacheable paths only. The search route is deliberately outside that
| list, so a query-dependent response is never cached at the edge.
*/
Route::get('/lotteries', [LotteryHubController::class, 'index'])
    ->middleware('public.legal')
    ->name('lotteries.index');

Route::get('/national-lottery', [NationalLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('national-lottery.index');

Route::get('/national-lottery/buy', [LotteryPurchasePageController::class, 'national'])
    ->middleware('public.legal')
    ->name('national-lottery.buy');

Route::get('/national-lottery/latest', [NationalLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('national-lottery.latest');

Route::get('/national-lottery/history', [NationalLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('national-lottery.history');

Route::get('/national-lottery/draw/{draw}', [NationalLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.draw-detail');

Route::get('/national-lottery/result/{draw}', [NationalLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.result-detail');

Route::get('/national-lottery/search', [NationalLotteryController::class, 'search'])
    ->middleware('throttle:national-result-search')
    ->name('national-lottery.search');

Route::get('/national-lottery/year/{year}', [NationalLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('national-lottery.year');

Route::get('/national-lottery/archive/{year}', [NationalLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('national-lottery.year-archive');

Route::get('/national-lottery/{draw}', [NationalLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 6: public Weekly Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These canonical and page-specific routes serve
| weekly_lottery_* data and nothing else: not GLO L6/N3, not the National lane,
| not an operator market. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| PARAMETER PATTERNS ARE HARD BOUNDARIES. {year} is at most four decimal
| digits and {draw} is at most forty characters of an explicit alphabet, so a
| hostile URL is refused by the router before a controller, a validator or a
| query is ever reached.
|
| /search carries throttle:weekly-result-search (registered in
| AppServiceProvider from config('weekly_lottery.rate_limit')): IP per minute,
| IP per hour, and a hashed query fingerprint per minute. A public lookup over
| a 1,000,000-value space is an enumeration oracle without it.
|
| public.legal = PublicLegalHeaders: short guest-GET cache headers on the
| listed cacheable paths only. The search route is deliberately outside that
| list, so a query-dependent response is never cached at the edge.
*/
Route::get('/weekly-lottery', [WeeklyLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('weekly-lottery.index');

Route::get('/weekly-lottery/buy', [LotteryPurchasePageController::class, 'weekly'])
    ->middleware('public.legal')
    ->name('weekly-lottery.buy');

Route::get('/weekly-lottery/search', [WeeklyLotteryController::class, 'search'])
    ->middleware('throttle:weekly-result-search')
    ->name('weekly-lottery.search');

Route::get('/weekly-lottery/latest', [WeeklyLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('weekly-lottery.latest');

Route::get('/weekly-lottery/history', [WeeklyLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('weekly-lottery.history');

Route::get('/weekly-lottery/archive/{year}', [WeeklyLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('weekly-lottery.archive');

Route::get('/weekly-lottery/draw/{draw}', [WeeklyLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.draw');

Route::get('/weekly-lottery/result/{draw}', [WeeklyLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.result');

Route::get('/weekly-lottery/year/{year}', [WeeklyLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('weekly-lottery.year');

Route::get('/weekly-lottery/{draw}', [WeeklyLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 8: public Bingo / Mega Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These canonical and page-specific routes serve
| bingo_lottery_* data and nothing else: not GLO L6/N3, not the National lane,
| not the Weekly lane, not an operator market. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| /search carries throttle:bingo-result-search (registered in
| AppServiceProvider from config('bingo_lottery.rate_limit')): IP per minute,
| IP per hour, and a hashed query fingerprint per minute. robots.txt asks
| crawlers to stay out of the same path, but that is a request - this limiter
| is the control.
|
*/
Route::get('/bingo-lottery', [BingoLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('bingo-lottery.index');

Route::get('/bingo-lottery/search', [BingoLotteryController::class, 'search'])
    ->middleware('throttle:bingo-result-search')
    ->name('bingo-lottery.search');

Route::get('/bingo-lottery/buy', [BingoLotteryController::class, 'buy'])
    ->middleware('public.legal')
    ->name('bingo-lottery.buy');

Route::get('/bingo-lottery/latest', [BingoLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('bingo-lottery.latest');

Route::get('/bingo-lottery/history', [BingoLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('bingo-lottery.history');

Route::get('/bingo-lottery/archive/{year}', [BingoLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('bingo-lottery.archive');

Route::get('/bingo-lottery/draw/{draw}', [BingoLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.draw');

Route::get('/bingo-lottery/result/{draw}', [BingoLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.result');

Route::get('/bingo-lottery/year/{year}', [BingoLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('bingo-lottery.year');

Route::get('/bingo-lottery/{draw}', [BingoLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 9: public PCSO Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. Four routes over pcso_lottery_* data: not GLO
| L6/N3, not National, not Weekly, not Mega, not an operator market. They
| read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| The {draw} pattern allows the longer PCSO reference, which carries a draw
| TIME as well as a date (PCSO-20260910-2100) because this lane publishes
| several draws per day.
|
| /search carries throttle:pcso-result-search. robots.txt asks crawlers to
| stay out of the same path, but that is a request - this limiter is the
| control.
|
*/

Route::get('/pcso-lottery', [PcsoLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('pcso-lottery.index');

Route::get('/pcso-lottery/search', [PcsoLotteryController::class, 'search'])
    ->middleware('throttle:pcso-result-search')
    ->name('pcso-lottery.search');

Route::get('/pcso-lottery/buy', [PcsoLotteryController::class, 'buy'])
    ->middleware('public.legal')
    ->name('pcso-lottery.buy');

Route::get('/pcso-lottery/latest', [PcsoLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('pcso-lottery.latest');

Route::get('/pcso-lottery/history', [PcsoLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('pcso-lottery.history');

// /year/{year} is canonical. /archive/{year} is retained as a compatibility
// alias and is declared before both detail wildcards.
Route::get('/pcso-lottery/year/{year}', [PcsoLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('pcso-lottery.year');

Route::get('/pcso-lottery/archive/{year}', [PcsoLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('pcso-lottery.archive');

Route::get('/pcso-lottery/draw/{draw}', [PcsoLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.draw');

Route::get('/pcso-lottery/result/{draw}', [PcsoLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.result');

// Original compatibility route; every named detail route above wins first.
Route::get('/pcso-lottery/{draw}', [PcsoLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.show');

// Static pages used by footer/support CTAs when configured.
Route::get('/privacy', [PublicPagesController::class, 'privacy'])
    ->middleware('public.legal')
    ->name('privacy');

/*
|--------------------------------------------------------------------------
| PROMPT 10: public Contact / Support centre
|--------------------------------------------------------------------------
|
| The GET route KEEPS ITS NAME. About, both footers, the privacy page and the
| terms page all link to route('contact'), and existing tests assert those
| links resolve. Renaming it to something tidier would have broken five
| surfaces to gain nothing.
|
| The POST carries throttle:contact-submit. A public endpoint that sends mail
| is a relay without one. It is also inside the normal web middleware group,
| so Laravel's CSRF protection applies - deliberately not excluded to make an
| AJAX submission simpler.
|
*/

/*
|--------------------------------------------------------------------------
| Public account-programme explainers
|--------------------------------------------------------------------------
|
| SIGNED-OUT INFORMATION, NOT THE ACCOUNT PAGES. /account/grade and
| /account/verification stay behind auth and show a person their own figures.
| These two show the LADDER and the PROCESS to somebody who has not
| registered and therefore cannot see either.
|
| Separate paths on purpose: relaxing auth on the existing routes would have
| meant one URL answering differently depending on who asked, which is how a
| personal figure eventually renders for a guest.
|
*/

Route::get('/account-grades', [\App\Http\Controllers\PublicGradeController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-grades');

Route::get('/account-grade', [\App\Http\Controllers\PublicGradeController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-grade');

Route::get('/account-verification', [\App\Http\Controllers\PublicVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-verification');

Route::get('/account-verification-guide', [\App\Http\Controllers\PublicVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-verification-guide');

Route::get('/contact', [\App\Http\Controllers\ContactController::class, 'show'])
    ->middleware('public.legal')
    ->name('contact');

Route::get('/contact-us', [\App\Http\Controllers\ContactController::class, 'show'])
    ->middleware('public.legal')
    ->name('contact-us');

Route::get('/download', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('download');

Route::get('/download-app', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('download-app');

Route::get('/app', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('app');

// XML sitemap (FINAL AUDIT #15): canonical public URLs only — no auth,
// admin, API, search-form, payment-return or legacy .php duplicates.
// Read-only and cacheable.
Route::get('/sitemap.xml', \App\Http\Controllers\SitemapController::class)
    ->name('sitemap');

/*
|--------------------------------------------------------------------------
| Browser payment-return pages (FINAL AUDIT #2)
|--------------------------------------------------------------------------
|
| Where a gateway drops the player's browser after checkout. PRESENTATION
| ONLY: the landing route is context, the displayed state is always the
| internal payment record (see PaymentCallbackController), and nothing on
| these pages can credit or change money. Paths come from the same
| config/payment.php callback block the gateway drivers build their
| success/cancel URLs from, so they can never drift apart.
|
*/

Route::middleware('auth')->group(function (): void {
    // The config values may be absolute URLs ("${APP_URL}/payment/success")
    // because the gateway drivers hand them to providers; route registration
    // only wants the path component, so normalize once here.
    $callbackPath = static function (string $key, string $default): string {
        $value = (string) config('payment.callback.'.$key, $default);
        $path = parse_url($value, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $default;
    };

    Route::get($callbackPath('success_url', '/payment/success'), [PaymentCallbackController::class, 'success'])
        ->name('payment.callback.success');

    Route::get($callbackPath('failure_url', '/payment/failure'), [PaymentCallbackController::class, 'failure'])
        ->name('payment.callback.failure');

    Route::get($callbackPath('cancel_url', '/payment/cancel'), [PaymentCallbackController::class, 'cancel'])
        ->name('payment.callback.cancel');

    Route::get($callbackPath('pending_url', '/payment/pending'), [PaymentCallbackController::class, 'pending'])
        ->name('payment.callback.pending');
});

// User-facing locale switch route (session & cookie persistence)
Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, ['en', 'th'], true)) {
        session(['locale' => $locale]);
        cookie()->queue(cookie('locale', $locale, 60 * 24 * 365));
    }

    return redirect()->back();
})->name('locale.switch');

Route::post('/contact', [ContactController::class, 'submit'])
    ->middleware('throttle:contact-submit')
    ->name('contact.submit');

/*
|--------------------------------------------------------------------------
| LOTTOFIN ADMIN & Operations Console Routes
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->middleware(['admin.auth', 'can:access-admin'])->group(function (): void {
    Route::get('/', [LottoFinExecutiveDashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [LottoFinExecutiveDashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/api/analytics', [LottoFinExecutiveDashboardController::class, 'analyticsApi'])
        ->middleware('throttle:admin-analytics')
        ->name('api.analytics');
    Route::get('/api/reconciliation', [LottoFinExecutiveDashboardController::class, 'reconciliationFeedApi'])
        ->middleware('throttle:admin-analytics')
        ->name('api.reconciliation');
    Route::post('/api/reconciliation', [LottoFinExecutiveDashboardController::class, 'runReconciliation'])
        ->middleware(['throttle:admin-analytics', 'can:access-admin'])
        ->name('api.reconciliation.run');

    // Operational projections. Each request is permission-checked again in the
    // controller so a route alias cannot widen access to another panel.
    Route::get('/draws', [LottoFinExecutiveDashboardController::class, 'index'])->name('draws.index');
    Route::get('/risk', [LottoFinExecutiveDashboardController::class, 'index'])->name('risk.index');
    Route::get('/bets', [LottoFinExecutiveDashboardController::class, 'index'])->name('bets.index');
    Route::get('/wallets', [LottoFinExecutiveDashboardController::class, 'index'])->name('wallets.index');
    Route::get('/ledger', [LottoFinExecutiveDashboardController::class, 'index'])->name('ledger.index');
    Route::get('/reconciliation', [LottoFinExecutiveDashboardController::class, 'index'])->name('reconciliation.index');
    Route::get('/audits', [LottoFinExecutiveDashboardController::class, 'index'])->name('audits.index');

    // Payment and withdrawal mutations are not implemented by this browser
    // console. They terminate in an explicit NOT_CONFIGURED response rather
    // than silently rendering a GET projection or changing financial state.
    Route::get('/payments', [LottoFinExecutiveDashboardController::class, 'index'])->name('payments.index');
    Route::get('/withdrawals', [LottoFinExecutiveDashboardController::class, 'index'])->name('withdrawals.index');
    Route::post('/withdrawals/{id}/disburse', [LottoFinExecutiveDashboardController::class, 'unsupportedMutation'])->name('withdrawals.disburse');
    Route::post('/withdrawals/{id}/reject', [LottoFinExecutiveDashboardController::class, 'unsupportedMutation'])->name('withdrawals.reject');

    // KYC documents remain on private storage and are streamed only after the
    // controller performs object-level reviewer authorization and audit logging.
    Route::get('/kyc', [LottoFinExecutiveDashboardController::class, 'index'])->name('kyc.index');
    Route::get('/kyc/{documentToken}/download', [LottoFinExecutiveDashboardController::class, 'downloadKyc'])
        ->where('documentToken', '[a-f0-9]{64}')
        ->middleware('throttle:account-verification')
        ->name('kyc.download');
    Route::post('/kyc/{documentToken}/approve', [LottoFinExecutiveDashboardController::class, 'reviewKyc'])
        ->where('documentToken', '[a-f0-9]{64}')
        ->middleware('throttle:account-verification')
        ->name('kyc.approve');
    Route::post('/kyc/{documentToken}/reject', [LottoFinExecutiveDashboardController::class, 'reviewKyc'])
        ->where('documentToken', '[a-f0-9]{64}')
        ->middleware('throttle:account-verification')
        ->name('kyc.reject');
    Route::get('/compliance', [LottoFinExecutiveDashboardController::class, 'index'])->name('compliance.index');

    // Pages 100–150 operational aliases. These remain read-only projections
    // unless an existing canonical service route is already used elsewhere.
    Route::get('/glo/prize-claims', [LottoFinExecutiveDashboardController::class, 'index'])->name('glo.prize-claims.index');
    Route::get('/glo/prize-claims/{claim}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('claim', '[A-Za-z0-9_-]{1,120}')
        ->name('glo.prize-claims.show');
    Route::get('/glo/ticket-freezes', [LottoFinExecutiveDashboardController::class, 'index'])->name('glo.ticket-freezes.index');
    Route::get('/glo/ticket-freezes/{token}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('token', '[A-Za-z0-9_-]{1,120}')
        ->name('glo.ticket-freezes.show');
    Route::get('/glo/settlements', [LottoFinExecutiveDashboardController::class, 'index'])->name('glo.settlements.index');
    Route::get('/wallet-operations', [LottoFinExecutiveDashboardController::class, 'index'])->name('wallet-operations.index');
    Route::get('/payments/{payment}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('payment', '[0-9]+')
        ->name('payments.show');
    Route::get('/payment-methods', [LottoFinExecutiveDashboardController::class, 'index'])->name('payment-methods.index');
    Route::get('/withdrawal-methods', [LottoFinExecutiveDashboardController::class, 'index'])->name('withdrawal-methods.index');
    Route::get('/payment-events', [LottoFinExecutiveDashboardController::class, 'index'])->name('payment-events.index');
    Route::get('/payments/events', [LottoFinExecutiveDashboardController::class, 'index'])->name('payments-events.index');
    Route::get('/payment-exceptions', [LottoFinExecutiveDashboardController::class, 'index'])->name('payment-exceptions.index');
    Route::get('/payments/exceptions', [LottoFinExecutiveDashboardController::class, 'index'])->name('payments-exceptions.index');
    Route::get('/draw-lifecycle', [LottoFinExecutiveDashboardController::class, 'index'])->name('draw-lifecycle.index');
    Route::get('/result-publication', [LottoFinExecutiveDashboardController::class, 'index'])->name('result-publication.index');
    Route::get('/result-imports', [LottoFinExecutiveDashboardController::class, 'index'])->name('result-imports.index');
    Route::get('/result-sources', [LottoFinExecutiveDashboardController::class, 'index'])->name('result-sources.index');
    Route::get('/lotteries', [LottoFinExecutiveDashboardController::class, 'index'])->name('lotteries.index');
    Route::get('/lottery-rules', [LottoFinExecutiveDashboardController::class, 'index'])->name('lottery-rules.index');
    Route::get('/fees', [LottoFinExecutiveDashboardController::class, 'index'])->name('fees.index');
    Route::get('/account-grades', [LottoFinExecutiveDashboardController::class, 'index'])->name('account-grades.index');
    Route::get('/account-verification', [LottoFinExecutiveDashboardController::class, 'index'])->name('account-verification.index');
    Route::get('/responsible-gaming', [LottoFinExecutiveDashboardController::class, 'index'])->name('responsible-gaming.index');
    Route::get('/self-exclusion', [LottoFinExecutiveDashboardController::class, 'index'])->name('self-exclusion.index');
    Route::get('/users', [LottoFinExecutiveDashboardController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('user', '[0-9]+')
        ->name('users.show');
    Route::get('/users/{user}/finance', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('user', '[0-9]+')
        ->name('users.finance');
    Route::get('/bets/{bet}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('bet', '[0-9]+')
        ->name('bets.show');
    Route::get('/tickets/{ticket}', [LottoFinExecutiveDashboardController::class, 'index'])
        ->where('ticket', '[0-9]+')
        ->name('tickets.show');
    Route::get('/ticket-verification', [LottoFinExecutiveDashboardController::class, 'index'])->name('ticket-verification.index');
    Route::get('/prize-claim-review', [LottoFinExecutiveDashboardController::class, 'index'])->name('prize-claim-review.index');
    Route::get('/commissions', [LottoFinExecutiveDashboardController::class, 'index'])->name('commissions.index');
    Route::get('/queues', [LottoFinExecutiveDashboardController::class, 'index'])->name('queues.index');
    Route::get('/scheduler', [LottoFinExecutiveDashboardController::class, 'index'])->name('scheduler.index');
    Route::get('/runtime', [LottoFinExecutiveDashboardController::class, 'index'])->name('runtime.index');
    Route::get('/api-status', [LottoFinExecutiveDashboardController::class, 'index'])->name('api-status.index');
    Route::get('/webhooks', [LottoFinExecutiveDashboardController::class, 'index'])->name('webhooks.index');
    Route::get('/security', [LottoFinExecutiveDashboardController::class, 'index'])->name('security.index');
    Route::get('/release', [LottoFinExecutiveDashboardController::class, 'index'])->name('release.index');
    Route::get('/cutover', [LottoFinExecutiveDashboardController::class, 'index'])->name('cutover.index');
});

/*
|--------------------------------------------------------------------------
| Authenticated support and notification projections
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function (): void {
    Route::get('/support', [SupportPortalController::class, 'index'])->name('support.index');
    Route::get('/support/{reference}', [SupportPortalController::class, 'show'])
        ->where('reference', '[A-Za-z0-9_-]{1,120}')
        ->name('support.show');
    Route::get('/notifications', [NotificationCenterController::class, 'index'])->name('notifications.index');
});

/*
|--------------------------------------------------------------------------
| Agent Portal Routes
|--------------------------------------------------------------------------
*/
Route::prefix('agent')->name('agent.')->middleware('auth')->group(function (): void {
    Route::get('/', [AgentPortalController::class, 'dashboard'])->name('dashboard');
    Route::get('/dashboard', [AgentPortalController::class, 'dashboard'])->name('dashboard.index');
    Route::get('/commissions', [AgentPortalController::class, 'commissions'])->name('commissions');
    Route::get('/settlements', [AgentPortalController::class, 'settlements'])->name('settlements');
    Route::get('/statement', [AgentPortalController::class, 'statement'])->name('statement');
    Route::get('/referrals', [AgentPortalController::class, 'referrals'])->name('referrals');
    Route::get('/referrals/{reference}', [AgentPortalController::class, 'referralDetail'])
        ->where('reference', '[a-f0-9]{64}')
        ->name('referrals.show');
});

/*
|--------------------------------------------------------------------------
| Legacy .php URL compatibility layer (301)
|--------------------------------------------------------------------------
|
| Single home for every public .php URL the replaced site published:
| static pages, member auth surfaces, account explainer pages, the broken
| double-path member URLs, and the per-year archive pages — including the
| "lottoery" typo form search engines indexed. See LegacyRedirectController
| for the map and the rules.
|
| THIS MUST STAY THE LAST ROUTE IN THIS FILE. It only ever sees paths no
| real route claimed, because Laravel matches in registration order, and
| it answers 404 for .php paths it does not know rather than aliasing them.
|
*/

Route::match(['get', 'post'], '/{legacyPath}', [LegacyRedirectController::class, 'resolve'])
    ->where('legacyPath', '.*\.php$')
    ->name('legacy.redirect');

```

## FILE 6: `resources/views/admin/dashboard.blade.php`

# TYPE: Blade view
# PURPOSE: Extends the existing admin projection view with GLO claim/freeze detail columns and truthful state messaging.

```php
@extends('layouts.admin')

@section('title', __('admin.title'))

@section('content')
<div class="flex w-full flex-col gap-6" aria-labelledby="admin-page-title">
    @php
        $panelKey = (string) ($panel ?? 'dashboard');
        $panelTitle = trans('admin.'.$panelKey);
        if ($panelTitle === 'admin.'.$panelKey) {
            $panelTitle = trans('admin.dashboard');
        }
    @endphp

    <header>
        <p class="text-xs uppercase tracking-[0.2em] text-emerald-400">{{ __('admin.title') }}</p>
        <h1 id="admin-page-title" class="lf-page-title">{{ $panelTitle }}</h1>
    </header>

    @if ($panelKey === 'dashboard')
        <section class="lf-kpi-grid" aria-label="{{ __('admin.analytics') }}">
            @foreach ([
                ['label' => __('admin.total_wagered'), 'value' => $kpis['totalWagered'] ?? __('admin.unavailable'), 'trend' => $kpis['totalWageredTrend'] ?? __('admin.unavailable')],
                ['label' => __('admin.house_profit'), 'value' => $kpis['houseGrossProfit'] ?? __('admin.unavailable'), 'trend' => $kpis['houseGrossProfitTrend'] ?? __('admin.unavailable')],
                ['label' => __('admin.active_bets'), 'value' => (string) ($kpis['activeInPlayBets'] ?? __('admin.unavailable')), 'trend' => $kpis['activeInPlayBetsState'] ?? __('admin.unavailable')],
                ['label' => __('admin.pending_withdrawals'), 'value' => (string) ($kpis['pendingWithdrawals'] ?? __('admin.unavailable')), 'trend' => $kpis['pendingWithdrawalsState'] ?? __('admin.unavailable')],
            ] as $metric)
                <article class="lf-kpi-card">
                    <span class="lf-kpi-label">{{ $metric['label'] }}</span>
                    <strong class="lf-kpi-value">{{ $metric['value'] }}</strong>
                    <span class="lf-kpi-trend lf-kpi-trend--neutral">{{ __('admin.state') }}: {{ $metric['trend'] }}</span>
                </article>
            @endforeach
        </section>

        <section class="lf-panel-card" aria-labelledby="admin-feed-title">
            <div class="lf-panel-header">
                <h2 id="admin-feed-title" class="lf-panel-title">{{ __('admin.live_feed') }}</h2>
                <span class="text-xs text-slate-400">{{ count($transactions ?? []) }} {{ __('admin.records') }}</span>
            </div>
            <div class="lf-table-container">
                <table class="lf-table">
                    <caption class="sr-only">{{ __('admin.live_feed') }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.reference') }}</th>
                            <th scope="col">{{ __('admin.type') }}</th>
                            <th scope="col">{{ __('admin.status') }}</th>
                            <th scope="col">{{ __('admin.amount') }}</th>
                            <th scope="col">{{ __('admin.currency') }}</th>
                            <th scope="col">{{ __('admin.created') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($transactions ?? [] as $row)
                            <tr>
                                <td class="lf-tx-id">{{ $row['reference'] }}</td>
                                <td>{{ $row['type'] }}</td>
                                <td>{{ $row['status'] }}</td>
                                <td class="font-mono">{{ $row['amount'] }}</td>
                                <td>{{ $row['currency'] }}</td>
                                <td class="text-slate-400">{{ $row['created_at'] ?? __('admin.no_data') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-8 text-center text-slate-400" role="status">{{ __('admin.no_records') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @elseif (in_array($panelKey, ['reconciliation', 'risk', 'compliance', 'payments', 'withdrawals'], true))
        <section class="lf-panel-card" role="status" aria-live="polite">
            <h2 class="lf-panel-title">{{ __('admin.state') }}: {{ $state ?? __('admin.unavailable') }}</h2>
            <p class="mt-3 text-sm text-slate-400">
                @if ($panelKey === 'reconciliation')
                    {{ __('admin.not_run') }}
                @else
                    {{ __('admin.no_records') }}
                @endif
            </p>
        </section>
    @else
        <section class="lf-panel-card" aria-labelledby="admin-records-title">
            <div class="lf-panel-header">
                <h2 id="admin-records-title" class="lf-panel-title">{{ __('admin.records') }}</h2>
                <span class="text-xs text-slate-400">{{ count($records ?? []) }} {{ __('admin.records') }}</span>
            </div>
            <div class="lf-table-container">
                <table class="lf-table">
                    <caption class="sr-only">{{ $panelTitle }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ __('admin.reference') }}</th>
                            <th scope="col">{{ __('admin.type') }}</th>
                            <th scope="col">{{ __('admin.status') }}</th>
                            <th scope="col">{{ __('admin.amount') }}</th>
                            <th scope="col">{{ __('admin.currency') }}</th>
                            <th scope="col">{{ __('admin.created') }}</th>
                            @if ($panelKey === 'claims')
                                <th scope="col">{{ __('admin.ticket') }}</th>
                                <th scope="col">{{ __('admin.draw') }}</th>
                                <th scope="col">{{ __('admin.gross') }}</th>
                                <th scope="col">{{ __('admin.stamp_duty') }}</th>
                                <th scope="col">{{ __('admin.payment_state') }}</th>
                                <th scope="col">{{ __('admin.hold_state') }}</th>
                                <th scope="col">{{ __('admin.age_state') }}</th>
                            @elseif ($panelKey === 'freezes')
                                <th scope="col">{{ __('admin.ticket') }}</th>
                                <th scope="col">{{ __('admin.draw') }}</th>
                                <th scope="col">{{ __('admin.authority') }}</th>
                                <th scope="col">{{ __('admin.case_reference') }}</th>
                                <th scope="col">{{ __('admin.evidence_reference') }}</th>
                            @endif
                            @if ($panelKey === 'kyc')
                                <th scope="col">{{ __('admin.actions') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($records ?? [] as $row)
                            <tr>
                                <td class="lf-tx-id">{{ $row['reference'] ?? $row['draw'] ?? __('admin.no_data') }}</td>
                                <td>{{ $row['type'] ?? $row['status'] ?? __('admin.no_data') }}</td>
                                <td>{{ $row['status'] ?? __('admin.no_data') }}</td>
                                <td class="font-mono">{{ $row['amount'] ?? __('admin.no_data') }}</td>
                                <td>{{ $row['currency'] ?? __('admin.no_data') }}</td>
                                <td class="text-slate-400">{{ $row['created_at'] ?? $row['date'] ?? __('admin.no_data') }}</td>
                                @if ($panelKey === 'claims')
                                    <td>{{ $row['ticket'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['draw'] ?? __('admin.no_data') }}</td>
                                    <td class="font-mono">{{ $row['gross'] ?? __('admin.no_data') }}</td>
                                    <td class="font-mono">{{ $row['stamp_duty'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['payment_status'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['hold_status'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['age_state'] ?? __('admin.no_data') }}</td>
                                @elseif ($panelKey === 'freezes')
                                    <td>{{ $row['ticket'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['draw'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['authority'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['case_reference'] ?? __('admin.no_data') }}</td>
                                    <td>{{ $row['evidence_reference'] ?? __('admin.no_data') }}</td>
                                @endif
                                @if ($panelKey === 'kyc')
                                    <td>
                                        <div class="flex flex-wrap gap-2">
                                            <a href="{{ $row['download_url'] }}" class="text-xs text-emerald-400 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400">{{ __('admin.download') }}</a>
                                            <form method="POST" action="{{ $row['approve_url'] }}">
                                                @csrf
                                                <button type="submit" class="text-xs text-emerald-400 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-400">{{ __('admin.approve') }}</button>
                                            </form>
                                            <form method="POST" action="{{ $row['reject_url'] }}">
                                                @csrf
                                                <button type="submit" class="text-xs text-rose-400 underline focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-400">{{ __('admin.reject') }}</button>
                                            </form>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="{{ $panelKey === 'kyc' ? 7 : ($panelKey === 'claims' ? 13 : ($panelKey === 'freezes' ? 11 : 6)) }}" class="py-8 text-center text-slate-400" role="status">{{ $state ? __('admin.state').': '.$state : __('admin.no_records') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
@endsection

```

## FILE 7: `resources/views/agent/portal.blade.php`

# TYPE: Blade view
# PURPOSE: Localized responsive agent portal projection with no private player data or browser-side financial mutation.

```php
@extends('layouts.app')

@section('title', trans('agent.title'))
@section('meta_description', trans('agent.meta_description'))
@section('robots', 'noindex, nofollow')

@section('content')
<main class="next-shell next-content" id="agent-main" tabindex="-1" aria-labelledby="agent-title">
    <a class="pp-skip-link" href="#agent-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header">
        <div class="next-shell next-page-header__inner">
            <a class="next-brand" href="{{ route('agent.dashboard') }}" aria-label="{{ trans('agent.home_aria') }}">
                <span class="next-brand__mark">TL</span>
                <span>THAILOTTO<small>{{ trans('agent.brand_subtitle') }}</small></span>
            </a>
            <nav class="next-nav" aria-label="{{ trans('agent.primary_nav') }}">
                <a href="{{ route('agent.dashboard') }}" @class(['is-active' => $surface === 'dashboard'])>{{ trans('agent.nav_dashboard') }}</a>
                <a href="{{ route('agent.commissions') }}" @class(['is-active' => $surface === 'commissions'])>{{ trans('agent.nav_commissions') }}</a>
                <a href="{{ route('agent.settlements') }}" @class(['is-active' => $surface === 'settlements'])>{{ trans('agent.nav_settlements') }}</a>
                <a href="{{ route('agent.statement') }}" @class(['is-active' => $surface === 'statement'])>{{ trans('agent.nav_statement') }}</a>
                <a href="{{ route('agent.referrals') }}" @class(['is-active' => in_array($surface, ['referrals', 'referral-detail'], true)])>{{ trans('agent.nav_referrals') }}</a>
            </nav>
            <span class="next-button">{{ trans('agent.authenticated') }}</span>
        </div>
    </header>

    <section class="next-hero" aria-labelledby="agent-title">
        <div>
            <p class="next-eyebrow">{{ trans('agent.eyebrow') }}</p>
            <h1 id="agent-title">{{ trans('agent.'.($surface === 'referral-detail' ? 'referral_detail_title' : $surface.'_title')) }}</h1>
            <p>{{ trans('agent.description') }}</p>
            <p class="next-note">{{ trans('agent.no_private_data_note') }}</p>
        </div>
        <div class="next-hero-object" aria-hidden="true"><span>AG</span></div>
    </section>

    <section class="next-meta-strip" aria-label="{{ trans('agent.metadata') }}">
        <div><span>{{ trans('agent.agent_reference') }}</span><strong>{{ $agent->agent_code }}</strong></div>
        <div><span>{{ trans('agent.status') }}</span><strong>{{ $state }}</strong></div>
        <div><span>{{ trans('agent.currency') }}</span><strong>{{ $agent->currency?->value ?? trans('agent.not_configured') }}</strong></div>
        <div><span>{{ trans('agent.referrals') }}</span><strong>{{ (string) $agent->total_referrals }}</strong></div>
    </section>

    @if ($surface === 'dashboard' && $report !== null)
        <section class="next-card-grid" aria-label="{{ trans('agent.snapshot') }}">
            <article class="next-card"><h2>{{ trans('agent.referred_players') }}</h2><p>{{ $report->referredPlayers }}</p></article>
            <article class="next-card"><h2>{{ trans('agent.active_players') }}</h2><p>{{ $report->activePlayers }}</p></article>
            <article class="next-card"><h2>{{ trans('agent.turnover') }}</h2><p>{{ $report->totalTurnover }} {{ $report->currency }}</p></article>
            <article class="next-card"><h2>{{ trans('agent.commission_accrued') }}</h2><p>{{ $report->commissionAccrued }} {{ $report->currency }}</p></article>
        </section>
    @endif

    <section class="next-panel" aria-labelledby="agent-records-title">
        <div class="next-panel-header">
            <h2 id="agent-records-title">{{ trans('agent.records') }}</h2>
            <span>{{ count($records) }} {{ trans('agent.records_count') }}</span>
        </div>
        <div class="next-table-wrap">
            <table class="next-table">
                <caption class="sr-only">{{ trans('agent.records') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ trans('agent.reference') }}</th>
                        <th scope="col">{{ trans('agent.type_or_basis') }}</th>
                        <th scope="col">{{ trans('agent.amount_or_status') }}</th>
                        <th scope="col">{{ trans('agent.currency') }}</th>
                        <th scope="col">{{ trans('agent.status') }}</th>
                        <th scope="col">{{ trans('agent.created') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr>
                            <td class="font-mono">{{ $record['reference'] ?? trans('agent.not_configured') }}</td>
                            <td>{{ $record['type'] ?? $record['basis'] ?? trans('agent.not_configured') }}</td>
                            <td class="font-mono">{{ $record['amount'] ?? $record['net'] ?? $record['status'] ?? trans('agent.not_configured') }}</td>
                            <td>{{ $record['currency'] ?? trans('agent.not_configured') }}</td>
                            <td>{{ $record['status'] ?? trans('agent.not_configured') }}</td>
                            <td>{{ $record['created_at'] ?? $record['paid_at'] ?? trans('agent.no_data') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" role="status">{{ trans('agent.no_records') }} · {{ $state }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>
@endsection

```

## FILE 8: `resources/views/support/portal.blade.php`

# TYPE: Blade view
# PURPOSE: Localized support center fail-closed state and safe public contact handoff.

```php
@extends('layouts.app')

@section('title', trans('support.title'))
@section('meta_description', trans('support.meta_description'))
@section('robots', 'noindex, nofollow')

@section('content')
<main class="next-shell next-content" id="support-main" tabindex="-1" aria-labelledby="support-title">
    <a class="pp-skip-link" href="#support-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header">
        <div class="next-shell next-page-header__inner">
            <a class="next-brand" href="{{ route('home') }}" aria-label="{{ trans('support.home_aria') }}">
                <span class="next-brand__mark">TL</span>
                <span>THAILOTTO<small>{{ trans('support.brand_subtitle') }}</small></span>
            </a>
            <nav class="next-nav" aria-label="{{ trans('support.primary_nav') }}">
                <a href="{{ route('player.dashboard') }}">{{ trans('support.nav_dashboard') }}</a>
                <a class="is-active" href="{{ route('support.index') }}" aria-current="page">{{ trans('support.nav_support') }}</a>
                <a href="{{ route('contact') }}">{{ trans('support.nav_contact') }}</a>
            </nav>
        </div>
    </header>

    <section class="next-hero" aria-labelledby="support-title">
        <div>
            <p class="next-eyebrow">{{ trans('support.eyebrow') }}</p>
            <h1 id="support-title">{{ $surface === 'detail' ? trans('support.detail_title') : trans('support.title') }}</h1>
            <p>{{ trans('support.description') }}</p>
        </div>
        <div class="next-hero-object" aria-hidden="true"><span>SUP</span></div>
    </section>

    <section class="next-panel" role="status" aria-live="polite">
        <p class="next-eyebrow">{{ trans('support.state_label') }}</p>
        <h2>{{ trans('support.not_configured_title') }}</h2>
        <p>{{ trans('support.not_configured_body') }}</p>
        @if ($reference !== null)
            <p><strong>{{ trans('support.reference') }}:</strong> <span class="font-mono">{{ $reference }}</span></p>
        @endif
        <p><a class="next-button next-button--gold" href="{{ route('contact') }}">{{ trans('support.contact_route') }}</a></p>
    </section>
</main>
@endsection

```

## FILE 9: `resources/views/notifications/index.blade.php`

# TYPE: Blade view
# PURPOSE: Localized owner-scoped notification projection with empty/state messaging.

```php
@extends('layouts.app')

@section('title', trans('notifications.title'))
@section('meta_description', trans('notifications.meta_description'))
@section('robots', 'noindex, nofollow')

@section('content')
<main class="next-shell next-content" id="notifications-main" tabindex="-1" aria-labelledby="notifications-title">
    <a class="pp-skip-link" href="#notifications-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header">
        <div class="next-shell next-page-header__inner">
            <a class="next-brand" href="{{ route('player.dashboard') }}" aria-label="{{ trans('notifications.home_aria') }}">
                <span class="next-brand__mark">TL</span>
                <span>THAILOTTO<small>{{ trans('notifications.brand_subtitle') }}</small></span>
            </a>
            <nav class="next-nav" aria-label="{{ trans('notifications.primary_nav') }}">
                <a href="{{ route('player.dashboard') }}">{{ trans('notifications.nav_dashboard') }}</a>
                <a class="is-active" href="{{ route('notifications.index') }}" aria-current="page">{{ trans('notifications.nav_notifications') }}</a>
                <a href="{{ route('support.index') }}">{{ trans('notifications.nav_support') }}</a>
            </nav>
        </div>
    </header>

    <section class="next-hero" aria-labelledby="notifications-title">
        <div>
            <p class="next-eyebrow">{{ trans('notifications.eyebrow') }}</p>
            <h1 id="notifications-title">{{ trans('notifications.title') }}</h1>
            <p>{{ trans('notifications.description') }}</p>
        </div>
        <div class="next-hero-object" aria-hidden="true"><span>NTF</span></div>
    </section>

    <section class="next-panel" aria-labelledby="notification-list-title">
        <div class="next-panel-header">
            <h2 id="notification-list-title">{{ trans('notifications.records') }}</h2>
            <span>{{ $state }}</span>
        </div>
        <div class="next-table-wrap">
            <table class="next-table">
                <caption class="sr-only">{{ trans('notifications.records') }}</caption>
                <thead>
                    <tr>
                        <th scope="col">{{ trans('notifications.type') }}</th>
                        <th scope="col">{{ trans('notifications.subject') }}</th>
                        <th scope="col">{{ trans('notifications.status') }}</th>
                        <th scope="col">{{ trans('notifications.created') }}</th>
                        <th scope="col">{{ trans('notifications.read') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($records as $record)
                        <tr>
                            <td>{{ $record['type'] ?? trans('notifications.no_data') }}</td>
                            <td>{{ $record['subject'] ?? trans('notifications.no_data') }}</td>
                            <td>{{ $record['status'] ?? trans('notifications.no_data') }}</td>
                            <td>{{ $record['created_at'] ?? trans('notifications.no_data') }}</td>
                            <td>{{ $record['read_at'] ?? trans('notifications.unread') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" role="status">{{ trans('notifications.no_records') }} · {{ $state }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</main>
@endsection

```

## FILE 10: `lang/en/admin.php`

# TYPE: PHP translation map
# PURPOSE: English Page 100–150 admin panel names, GLO fields, and operational labels.

```php
<?php

return [
    'title' => 'Administration',
    'dashboard' => 'Executive dashboard',
    'draws' => 'Draw operations',
    'risk' => 'Risk console',
    'bets' => 'Bet operations',
    'wallets' => 'Wallet operations',
    'ledger' => 'Ledger',
    'reconciliation' => 'Reconciliation',
    'audits' => 'Audit log',
    'kyc' => 'KYC and verification',
    'payments' => 'Payment operations',
    'withdrawals' => 'Withdrawal operations',
    'compliance' => 'Compliance center',
    'analytics' => 'Analytics',
    'live_feed' => 'Financial transaction projection',
    'total_wagered' => 'Total wagered',
    'house_profit' => 'House gross profit',
    'active_bets' => 'Active bets',
    'pending_withdrawals' => 'Pending withdrawals',
    'completed_withdrawals' => 'Completed withdrawals',
    'trend' => 'Trend',
    'state' => 'State',
    'reference' => 'Reference',
    'type' => 'Type',
    'status' => 'Status',
    'amount' => 'Amount',
    'currency' => 'Currency',
    'created' => 'Created',
    'actions' => 'Actions',
    'download' => 'Download',
    'approve' => 'Approve',
    'reject' => 'Reject',
    'no_records' => 'No records are available for this projection.',
    'no_data' => 'NO_DATA',
    'unavailable' => 'UNAVAILABLE',
    'not_configured' => 'NOT_CONFIGURED',
    'not_run' => 'No reconciliation report has been run for this view.',
    'records' => 'Records',
    'access_denied' => 'Access denied.',
    'status_recorded' => 'The operation was recorded.',
    'review_recorded' => 'KYC review recorded.',
    'unsupported_mutation' => 'This browser action is not configured. Use the canonical operations service.',
    'analytics_range_invalid' => 'The analytics date range must be between zero and 31 days.',
    'claims' => 'GLO prize claims',
    'freezes' => 'GLO ticket freezes',
    'settlements' => 'Prize settlement review',
    'wallet_operations' => 'Wallet operations center',
    'payment_methods' => 'Payment methods management',
    'withdrawal_methods' => 'Withdrawal methods management',
    'payment_events' => 'Payment event audit',
    'payment_exceptions' => 'Payment exceptions',
    'draw_lifecycle' => 'Draw lifecycle operations',
    'publication' => 'Result publication control',
    'imports' => 'Result import and provenance',
    'sources' => 'Result source health',
    'lotteries' => 'Lottery product catalogue',
    'lottery_rules' => 'Lottery rules and pricing',
    'fees' => 'Fee schedule management',
    'account_grades' => 'Account grade administration',
    'account_verification' => 'Account verification operations',
    'responsible_gaming' => 'Responsible gaming operations',
    'self_exclusion' => 'Self-exclusion operations',
    'users' => 'User operations',
    'user_detail' => 'User detail',
    'user_finance' => 'User financial profile',
    'bet_detail' => 'Bet detail',
    'ticket_detail' => 'Ticket detail',
    'ticket_verification' => 'Ticket verification operations',
    'claim_review' => 'Prize claim review queue',
    'commissions' => 'Commission operations',
    'health' => 'System health',
    'queues' => 'Queue and worker health',
    'scheduler' => 'Scheduled tasks',
    'runtime' => 'Runtime operations',
    'api_status' => 'API status center',
    'webhooks' => 'Webhook audit center',
    'security' => 'Security audit center',
    'release' => 'Release and deployment status',
    'cutover' => 'Production cutover control center',
    'ticket' => 'Ticket',
    'draw' => 'Draw',
    'gross' => 'Gross prize',
    'stamp_duty' => 'Stamp duty',
    'payment_state' => 'Payment state',
    'hold_state' => 'Hold state',
    'age_state' => 'Age verification',
    'authority' => 'Authority',
    'case_reference' => 'Case reference',
    'evidence_reference' => 'Evidence reference',
];

```

## FILE 11: `lang/th/admin.php`

# TYPE: PHP translation map
# PURPOSE: Matching Thai-locale admin key set for Page 100–150 operational labels.

```php
<?php

return [
    'title' => 'Administration',
    'dashboard' => 'Executive dashboard',
    'draws' => 'Draw operations',
    'risk' => 'Risk console',
    'bets' => 'Bet operations',
    'wallets' => 'Wallet operations',
    'ledger' => 'Ledger',
    'reconciliation' => 'Reconciliation',
    'audits' => 'Audit log',
    'kyc' => 'KYC and verification',
    'payments' => 'Payment operations',
    'withdrawals' => 'Withdrawal operations',
    'compliance' => 'Compliance center',
    'analytics' => 'Analytics',
    'live_feed' => 'Financial transaction projection',
    'total_wagered' => 'Total wagered',
    'house_profit' => 'House gross profit',
    'active_bets' => 'Active bets',
    'pending_withdrawals' => 'Pending withdrawals',
    'completed_withdrawals' => 'Completed withdrawals',
    'trend' => 'Trend',
    'state' => 'State',
    'reference' => 'Reference',
    'type' => 'Type',
    'status' => 'Status',
    'amount' => 'Amount',
    'currency' => 'Currency',
    'created' => 'Created',
    'actions' => 'Actions',
    'download' => 'Download',
    'approve' => 'Approve',
    'reject' => 'Reject',
    'no_records' => 'No records are available for this projection.',
    'no_data' => 'NO_DATA',
    'unavailable' => 'UNAVAILABLE',
    'not_configured' => 'NOT_CONFIGURED',
    'not_run' => 'No reconciliation report has been run for this view.',
    'records' => 'Records',
    'access_denied' => 'Access denied.',
    'status_recorded' => 'The operation was recorded.',
    'review_recorded' => 'KYC review recorded.',
    'unsupported_mutation' => 'This browser action is not configured. Use the canonical operations service.',
    'analytics_range_invalid' => 'The analytics date range must be between zero and 31 days.',
    'claims' => 'GLO prize claims',
    'freezes' => 'GLO ticket freezes',
    'settlements' => 'Prize settlement review',
    'wallet_operations' => 'Wallet operations center',
    'payment_methods' => 'Payment methods management',
    'withdrawal_methods' => 'Withdrawal methods management',
    'payment_events' => 'Payment event audit',
    'payment_exceptions' => 'Payment exceptions',
    'draw_lifecycle' => 'Draw lifecycle operations',
    'publication' => 'Result publication control',
    'imports' => 'Result import and provenance',
    'sources' => 'Result source health',
    'lotteries' => 'Lottery product catalogue',
    'lottery_rules' => 'Lottery rules and pricing',
    'fees' => 'Fee schedule management',
    'account_grades' => 'Account grade administration',
    'account_verification' => 'Account verification operations',
    'responsible_gaming' => 'Responsible gaming operations',
    'self_exclusion' => 'Self-exclusion operations',
    'users' => 'User operations',
    'user_detail' => 'User detail',
    'user_finance' => 'User financial profile',
    'bet_detail' => 'Bet detail',
    'ticket_detail' => 'Ticket detail',
    'ticket_verification' => 'Ticket verification operations',
    'claim_review' => 'Prize claim review queue',
    'commissions' => 'Commission operations',
    'health' => 'System health',
    'queues' => 'Queue and worker health',
    'scheduler' => 'Scheduled tasks',
    'runtime' => 'Runtime operations',
    'api_status' => 'API status center',
    'webhooks' => 'Webhook audit center',
    'security' => 'Security audit center',
    'release' => 'Release and deployment status',
    'cutover' => 'Production cutover control center',
    'ticket' => 'Ticket',
    'draw' => 'Draw',
    'gross' => 'Gross prize',
    'stamp_duty' => 'Stamp duty',
    'payment_state' => 'Payment state',
    'hold_state' => 'Hold state',
    'age_state' => 'Age verification',
    'authority' => 'Authority',
    'case_reference' => 'Case reference',
    'evidence_reference' => 'Evidence reference'
];

```

## FILE 12: `lang/en/agent.php`

# TYPE: PHP translation map
# PURPOSE: English agent portal labels and explicit states.

```php
<?php

declare(strict_types=1);

return [
    'title' => 'Agent portal',
    'meta_description' => 'Authenticated agent commission and referral projections.',
    'home_aria' => 'Agent portal home',
    'brand_subtitle' => 'Agent operations',
    'primary_nav' => 'Agent portal navigation',
    'nav_dashboard' => 'Dashboard',
    'nav_commissions' => 'Commissions',
    'nav_settlements' => 'Settlements',
    'nav_statement' => 'Statement',
    'nav_referrals' => 'Referrals',
    'authenticated' => 'Authenticated agent',
    'eyebrow' => 'AGENT OPERATIONS',
    'dashboard_title' => 'Agent dashboard',
    'commissions_title' => 'Agent commissions',
    'settlements_title' => 'Agent settlements',
    'statement_title' => 'Agent statement',
    'referrals_title' => 'Referral overview',
    'referral_detail_title' => 'Referral detail',
    'description' => 'This portal shows only server-calculated projections for the authenticated agent.',
    'no_private_data_note' => 'Private player balances, payment history, KYC documents, and operator records are not exposed here.',
    'metadata' => 'Agent metadata',
    'agent_reference' => 'Agent reference',
    'status' => 'Status',
    'currency' => 'Currency',
    'referrals' => 'Referrals',
    'snapshot' => 'Commission snapshot',
    'referred_players' => 'Referred players',
    'active_players' => 'Active players',
    'turnover' => 'Turnover',
    'commission_accrued' => 'Commission accrued',
    'records' => 'Records',
    'records_count' => 'records',
    'reference' => 'Reference',
    'type_or_basis' => 'Type or basis',
    'amount_or_status' => 'Amount or status',
    'created' => 'Created',
    'no_records' => 'No records are available for this projection.',
    'no_data' => 'NO_DATA',
    'not_configured' => 'NOT_CONFIGURED',
];

```

## FILE 13: `lang/th/agent.php`

# TYPE: PHP translation map
# PURPOSE: Matching Thai-locale agent portal key set.

```php
<?php

declare(strict_types=1);

return [
    'title' => 'Agent portal',
    'meta_description' => 'Authenticated agent commission and referral projections.',
    'home_aria' => 'Agent portal home',
    'brand_subtitle' => 'Agent operations',
    'primary_nav' => 'Agent portal navigation',
    'nav_dashboard' => 'Dashboard',
    'nav_commissions' => 'Commissions',
    'nav_settlements' => 'Settlements',
    'nav_statement' => 'Statement',
    'nav_referrals' => 'Referrals',
    'authenticated' => 'Authenticated agent',
    'eyebrow' => 'AGENT OPERATIONS',
    'dashboard_title' => 'Agent dashboard',
    'commissions_title' => 'Agent commissions',
    'settlements_title' => 'Agent settlements',
    'statement_title' => 'Agent statement',
    'referrals_title' => 'Referral overview',
    'referral_detail_title' => 'Referral detail',
    'description' => 'This portal shows only server-calculated projections for the authenticated agent.',
    'no_private_data_note' => 'Private player balances, payment history, KYC documents, and operator records are not exposed here.',
    'metadata' => 'Agent metadata',
    'agent_reference' => 'Agent reference',
    'status' => 'Status',
    'currency' => 'Currency',
    'referrals' => 'Referrals',
    'snapshot' => 'Commission snapshot',
    'referred_players' => 'Referred players',
    'active_players' => 'Active players',
    'turnover' => 'Turnover',
    'commission_accrued' => 'Commission accrued',
    'records' => 'Records',
    'records_count' => 'records',
    'reference' => 'Reference',
    'type_or_basis' => 'Type or basis',
    'amount_or_status' => 'Amount or status',
    'created' => 'Created',
    'no_records' => 'No records are available for this projection.',
    'no_data' => 'NO_DATA',
    'not_configured' => 'NOT_CONFIGURED',
];

```

## FILE 14: `lang/en/support.php`

# TYPE: PHP translation map
# PURPOSE: English support center fail-closed labels.

```php
<?php

declare(strict_types=1);

return [
    'title' => 'Support center',
    'detail_title' => 'Support request detail',
    'meta_description' => 'Authenticated support center projection.',
    'home_aria' => 'Support center home',
    'brand_subtitle' => 'Support center',
    'primary_nav' => 'Support navigation',
    'nav_dashboard' => 'Dashboard',
    'nav_support' => 'Support',
    'nav_contact' => 'Contact',
    'eyebrow' => 'SUPPORT OPERATIONS',
    'description' => 'Support records are available only when the canonical owner-scoped support case contract is configured.',
    'state_label' => 'State',
    'not_configured_title' => 'Support inbox not configured',
    'not_configured_body' => 'The current contact-message architecture does not bind anonymous public messages to an authenticated owner. No private record is displayed. Use the configured public contact route.',
    'reference' => 'Reference',
    'contact_route' => 'Contact support',
];

```

## FILE 15: `lang/th/support.php`

# TYPE: PHP translation map
# PURPOSE: Matching Thai-locale support center key set.

```php
<?php

declare(strict_types=1);

return [
    'title' => 'Support center',
    'detail_title' => 'Support request detail',
    'meta_description' => 'Authenticated support center projection.',
    'home_aria' => 'Support center home',
    'brand_subtitle' => 'Support center',
    'primary_nav' => 'Support navigation',
    'nav_dashboard' => 'Dashboard',
    'nav_support' => 'Support',
    'nav_contact' => 'Contact',
    'eyebrow' => 'SUPPORT OPERATIONS',
    'description' => 'Support records are available only when the canonical owner-scoped support case contract is configured.',
    'state_label' => 'State',
    'not_configured_title' => 'Support inbox not configured',
    'not_configured_body' => 'The current contact-message architecture does not bind anonymous public messages to an authenticated owner. No private record is displayed. Use the configured public contact route.',
    'reference' => 'Reference',
    'contact_route' => 'Contact support',
];

```
