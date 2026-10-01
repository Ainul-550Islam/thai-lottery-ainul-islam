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
