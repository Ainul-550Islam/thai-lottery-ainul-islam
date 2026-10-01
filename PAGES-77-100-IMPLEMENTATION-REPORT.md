# Pages 77–100 implementation report

Runtime status: `NOT VERIFIED — RUNTIME UNAVAILABLE`.

This report includes complete contents for every implementation file changed in this Pages 77–100 pass. The report file itself is a generated delivery artifact and is not included recursively.

## `app/Http/Controllers/GloResultsPageController.php`

# TYPE: PHP controller
# PURPOSE: Canonical public results hub and ticket-check API composition; removes fabricated result payloads.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\GloDealerException;
use App\Services\Lottery\GloPublicResultService;
use App\Services\PublicPages\ResultsPageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public results hub and the JSON read model used by the public results lane.
 *
 * This controller deliberately composes the existing public projections. It does
 * not contain draw numbers, prize amounts, provider claims, or fixture data.
 */
final class GloResultsPageController
{
    public function __construct(
        private readonly ResultsPageService $results,
        private readonly GloPublicResultService $publicResults,
    ) {
    }

    public function index(Request $request): View
    {
        $data = $this->results->resultsData();

        return view('results.index', [
            'currentStatus' => $data['currentStatus'],
            'rows' => $data['rows'],
        ]);
    }

    public function latestDrawApi(): JsonResponse
    {
        $data = $this->results->resultsData();

        return response()->json([
            'status' => $data['rows'] === [] ? 'NO_PUBLIC_DATA' : 'success',
            'data' => array_map(static function (array $row): array {
                return [
                    'draw_number' => $row['draw_number'],
                    'draw_date' => $row['draw_date'],
                    'first_prize' => $row['first_prize'],
                    'second_prize' => $row['second_prize'],
                    'third_prize' => $row['third_prize'],
                    'consolation_prizes' => $row['consolation_prizes'],
                    'source_state' => $row['source_state'],
                ];
            }, $data['rows']),
        ]);
    }

    public function checkTicketApi(Request $request): JsonResponse
    {
        $number = trim((string) $request->input('number', ''));

        if (preg_match('/^\d{6}$/', $number) !== 1) {
            return response()->json([
                'status' => 'INVALID_INPUT',
                'message' => 'Enter exactly six digits.',
            ], 422);
        }

        try {
            $result = $this->publicResults->checkSixDigit($number);
        } catch (GloDealerException $e) {
            return response()->json([
                'status' => 'UNAVAILABLE',
                'message' => 'Ticket checking is temporarily unavailable.',
            ], 503);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'status' => 'UNAVAILABLE',
                'message' => 'Ticket checking is temporarily unavailable.',
            ], 503);
        }

        return response()->json([
            'status' => 'success',
            'data' => $result,
        ]);
    }
}

```

## `app/Http/Controllers/Admin/LottoFinExecutiveDashboardController.php`

# TYPE: PHP controller
# PURPOSE: Authorized admin projections, bounded analytics, configured-currency exact Money formatting with explicit unavailable fallback, canonical reconciliation, and reviewer-bound opaque KYC actions.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Currency;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\FinancialTransaction;
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

    public function index(Request $request): View
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
            default => [],
        };

        return view('admin.dashboard', [
            'panel' => $panel,
            'kpis' => $panel === 'dashboard' ? $this->calculateExecutiveKpis() : [],
            'transactions' => $panel === 'dashboard' ? $this->fetchFinancialFeed() : [],
            'records' => $payload['records'] ?? [],
            'state' => in_array($panel, ['reconciliation', 'compliance', 'risk', 'payments', 'withdrawals'], true)
                ? 'UNAVAILABLE'
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
            str_contains($name, 'bets') => 'bets',
            str_contains($name, 'wallets') => 'wallets',
            str_contains($name, 'ledger') => 'ledger',
            str_contains($name, 'reconciliation') => 'reconciliation',
            str_contains($name, 'audits') => 'audits',
            str_contains($name, 'kyc') => 'kyc',
            str_contains($name, 'payments') => 'payments',
            str_contains($name, 'withdrawals') => 'withdrawals',
            str_contains($name, 'compliance') => 'compliance',
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

## `app/Http/Middleware/Authenticate.php`

# TYPE: PHP middleware
# PURPOSE: Existing authentication middleware with an admin-specific redirect to the Filament login boundary.

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;
use Illuminate\Http\Request;

/**
 * Authentication Middleware with JSON and Active-User Defense.
 */
class Authenticate extends BaseAuthenticate
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        if ($request->is('admin') || $request->is('admin/*')) {
            return url('/admin/login');
        }

        return $request->expectsJson() || $request->is('api/*')
            ? null
            : route('login');
    }

    /**
     * Handle unauthenticated responses for API/JSON calls.
     */
    protected function unauthenticated($request, array $guards)
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            abort(ApiResponse::error(
                code: 'unauthenticated',
                message: 'Authentication is required to access this resource.',
                status: 401
            ));
        }

        parent::unauthenticated($request, $guards);
    }
}

```

## `app/Providers/AuthServiceProvider.php`

# TYPE: PHP provider
# PURPOSE: Admin access gate registration backed by AdminAccess.

```php
<?php

namespace App\Providers;

use App\Models\AccountGradeSnapshot;
use App\Models\Agent;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\GloPrizeClaim;
use App\Models\GradeDiscountSnapshot;
use App\Models\GloTicketFreeze;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Ticket;
use App\Models\Wallet;
use App\Policies\AccountGradePolicy;
use App\Policies\AgentPolicy;
use App\Policies\BetPolicy;
use App\Policies\DrawPolicy;
use App\Policies\GloPrizeClaimPolicy;
use App\Policies\GloTicketFreezePolicy;
use App\Policies\LedgerPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\TicketPolicy;
use App\Policies\WalletPolicy;
use App\Support\Admin\AdminAccess;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    protected array $policies = [
        AccountGradeSnapshot::class => AccountGradePolicy::class,
        GradeDiscountSnapshot::class => AccountGradePolicy::class,
        Wallet::class => WalletPolicy::class,
        LedgerEntry::class => LedgerPolicy::class,
        Bet::class => BetPolicy::class,
        Ticket::class => TicketPolicy::class,
        Draw::class => DrawPolicy::class,
        Payment::class => PaymentPolicy::class,
        Agent::class => AgentPolicy::class,
        \App\Models\AccountVerification::class => \App\Policies\AccountVerificationPolicy::class,
        GloTicketFreeze::class => GloTicketFreezePolicy::class,
        GloPrizeClaim::class => GloPrizeClaimPolicy::class,
    ];

    public function boot(): void
    {
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        Gate::before(function ($user, $ability) {
            return $user->hasRole('super-admin') ? true : null;
        });

        // /metrics operator gate: staff-or-higher only (super-admin short-circuits above).
        Gate::define('access-metrics', function ($user): bool {
            return $user->hasAnyRole(AdminAccess::PANEL_ROLES);
        });

        // Web operations console boundary. The controller still applies the
        // least-privilege permission for each panel; this gate prevents any
        // unauthenticated or non-operator request from reaching that layer.
        Gate::define('access-admin', function ($user): bool {
            return AdminAccess::canAccessPanel($user);
        });
    }
}

```

## `app/Providers/AppServiceProvider.php`

# TYPE: PHP provider
# PURPOSE: Admin analytics/reconciliation rate limiter registration.

```php
<?php

namespace App\Providers;

use App\Http\Responses\ApiResponse;
use App\Http\Support\BetPurchaseErrorMapper;
use App\Services\Lottery\GloDataMatrixParser;
use App\Services\Lottery\GloDataMatrixParserInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // NOTE (Payment phase): the PaymentGatewayInterface binding lives here
        // once App\Services\Payment\Gateway\* classes are implemented. It is
        // intentionally not registered yet so the container stays resolvable.

        // PROMPT 4: the Data Matrix reader is consumed through its interface by
        // App\Services\Lottery\TicketBarcodeService, so the public verification
        // page can be pointed at a real reader later without touching the
        // service. GloDataMatrixParser is the only implementation today and
        // already reports 'not_configured' when no format is authorised.
        $this->app->bind(GloDataMatrixParserInterface::class, GloDataMatrixParser::class);
    }

    public function boot(): void
    {
        // Money is handled with bcmath strings; force a consistent scale.
        if (function_exists('bcscale')) {
            bcscale(2);
        }

        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->registerRateLimiters();

        if ($this->app->environment('local')) {
            DB::whenQueryingForLongerThan(1000, function ($connection, $event): void {
                logger()->warning('Slow query detected.', [
                    'sql' => $event->sql,
                    'time' => $event->time,
                ]);
            });
        }
    }

    /**
     * Register the named rate limiters used by routes/api.php.
     *
     * config/security.php already declared these ceilings in Phase 1 and explicitly noted
     * that "Named limiters are registered from these values in a later phase". Phase 4.4 is
     * that phase. The numbers are read from config, never hard-coded here, so an operator
     * changes a limit through the existing RATE_LIMIT_* environment variables rather than by
     * editing application code.
     *
     * WHY THE `bet` LIMITER IS KEYED ON THE USER, NOT THE IP
     * Betting is an authenticated action. Keying on the IP would punish every player behind
     * one mobile carrier NAT for the behaviour of one of them, and would let a single
     * account bypass its own ceiling by rotating IPs. The user id is the only key that
     * matches what the limit is actually protecting. This is also exactly what
     * config('security.rate_limits.bet.by') already specified: 'user'.
     */
    private function registerRateLimiters(): void
    {
        $this->registerLoginLimiter();

        $apiPerMinute = (int) config('security.rate_limits.api.max_per_minute', 60);
        $betPerMinute = (int) config('security.rate_limits.bet.max_per_minute', 10);
        $webhookPerMinute = (int) config('security.rate_limits.webhook.max_per_minute', 120);
        $depositPerHour = (int) config('security.rate_limits.deposit.max_per_hour', 5);
        $withdrawalPerDay = (int) config('security.rate_limits.withdrawal.max_per_day', 3);

        // Money-entry ceilings, keyed on the authenticated user exactly as
        // config('security.rate_limits.deposit.by') / withdrawal.by declare. Deposits and
        // withdrawals are the two write surfaces where a per-hour / per-day ceiling matters
        // beyond the per-minute api limiter, so both are registered here even though only
        // the deposit route carries the deposit limiter today.
        // The key passed to ->by() is suffixed with the limiter name by the throttle
        // middleware, so ->by('user:1') yields the cache key 'deposit:user:1' — the exact
        // key that hardening consumers clear with RateLimiter::clear('deposit:user:N').
        RateLimiter::for('deposit', function (Request $request) use ($depositPerHour): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perHour($depositPerHour)
                ->by($identifier === null ? 'ip:'.$request->ip() : 'user:'.$identifier)
                ->response($this->throttleResponse());
        });

        RateLimiter::for('withdrawal', function (Request $request) use ($withdrawalPerDay): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perDay($withdrawalPerDay)
                ->by($identifier === null ? 'ip:'.$request->ip() : 'user:'.$identifier)
                ->response($this->throttleResponse());
        });

        // Two further named limiters that hardening consumers reference by name:
        // 'player-bet-placement' is the tighter per-minute ceiling for the wager write
        // surface, and 'financial-critical' guards every endpoint that can move money.
        // Both key on the authenticated user, falling back to the IP for unauthenticated
        // traffic so a hostile host cannot exhaust a real user's allowance.
        RateLimiter::for('player-bet-placement', function (Request $request) use ($betPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute($betPerMinute)
                ->by($identifier === null ? 'bet:ip:'.$request->ip() : 'bet:user:'.$identifier)
                ->response($this->throttleResponse());
        });

        RateLimiter::for('financial-critical', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(30)
                ->by($identifier === null ? 'financial-critical:ip:'.$request->ip() : 'financial-critical:user:'.$identifier)
                ->response($this->throttleResponse());
        });

        // 'player-api' mirrors the broad per-minute API ceiling; registered under its own
        // name so the hardening surface can reference it independently of 'api'.
        RateLimiter::for('player-api', function (Request $request) use ($apiPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute($apiPerMinute)
                ->by($identifier === null ? 'ip:'.$request->ip() : 'user:'.$identifier)
                ->response($this->throttleResponse());
        });

        RateLimiter::for('api', function (Request $request) use ($apiPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            // 'user_or_ip' per config: an authenticated caller is limited as themselves, and
            // an unauthenticated one - which on this surface means a request that will be
            // rejected by auth middleware anyway - is limited by IP so that unauthenticated
            // traffic cannot be used to exhaust a real user's allowance.
            return Limit::perMinute($apiPerMinute)
                ->by($identifier === null ? 'ip:'.$request->ip() : 'user:'.$identifier)
                ->response($this->throttleResponse());
        });

        RateLimiter::for('bet', function (Request $request) use ($betPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            if ($identifier === null) {
                // Should not occur behind auth:sanctum. Falling back to the IP is the safe
                // direction: an unkeyed limiter would be no limiter at all.
                return Limit::perMinute($betPerMinute)
                    ->by('bet:ip:'.$request->ip())
                    ->response($this->throttleResponse());
            }

            return Limit::perMinute($betPerMinute)
                ->by('bet:user:'.$identifier)
                ->response($this->throttleResponse());
        });

        // The webhook limiter is keyed on the IP, exactly as
        // config('security.rate_limits.webhook.by') declares. Incoming gateway
        // notifications are unauthenticated by nature of being server-to-server
        // callbacks; the IP is the only key that can pin a hostile host without
        // punishing legitimately high-volume providers.
        RateLimiter::for('glo.public', function (Request $request): Limit {
            $perMinute = (int) config('glo.public_status.rate_limit_per_minute', 30);

            return Limit::perMinute($perMinute)
                ->by('glo-public:'.$request->ip())
                ->response($this->throttleResponse());
        });

        RateLimiter::for('webhook', function (Request $request) use ($webhookPerMinute): Limit {
            return Limit::perMinute($webhookPerMinute)
                ->by('ip:'.$request->ip())
                ->response($this->throttleResponse());
        });

        // Public Home ticket-check UI — anonymous IP-keyed ceiling.
        RateLimiter::for('home-check', function (Request $request): Limit {
            return Limit::perMinute(10)
                ->by('home-check:'.$request->ip())
                ->response($this->throttleResponse());
        });

        // Public prize/ticket verification (PROMPT 4) — anonymous and
        // enumerable by nature: six digits is a 1,000,000-value space, so an
        // unthrottled checker is a free oracle for "does this ticket exist".
        //
        // TWO CEILINGS, ONE LIMITER. Laravel evaluates every Limit returned
        // here, so the IP ceiling bounds sweeping a range while the second
        // ceiling - keyed on a HASH of the submitted value - bounds hammering
        // one value. The hash means the limiter never stores the number that
        // was checked, matching the evidence policy in
        // config('ticket_verification.evidence').
        RateLimiter::for('ticket-verification', function (Request $request): array {
            $perMinute = max(1, (int) config('ticket_verification.rate_limit.per_minute', 12));
            $perHour = max($perMinute, (int) config('ticket_verification.rate_limit.per_hour', 120));
            $fingerprintPerMinute = max(1, (int) config('ticket_verification.rate_limit.fingerprint_per_minute', 4));

            $value = (string) $request->input('value', '');
            $fingerprint = $value === ''
                ? 'empty'
                : substr(hash_hmac('sha256', $value, (string) config('app.key', '')), 0, 32);

            return [
                Limit::perMinute($perMinute)
                    ->by('ticket-verification:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perHour($perHour)
                    ->by('ticket-verification:ip-hour:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perMinute($fingerprintPerMinute)
                    ->by('ticket-verification:q:'.$fingerprint)
                    ->response($this->throttleResponse()),
            ];
        });

        // PROMPT 5: public National Lottery search.
        //
        // Same three-ceiling shape as 'ticket-verification', for the same
        // reason: the six-digit space is enumerable, so an IP ceiling alone
        // lets a distributed client walk it while each address stays polite.
        // The third ceiling is keyed on a HASHED query, so repeating one term
        // is cheap for a human refreshing a page and expensive for a script
        // grinding the space.
        //
        // The fingerprint is an HMAC of the term with the app key, never the
        // term itself: a rate-limiter cache entry should not become a
        // plaintext record of what the public searched for.
        RateLimiter::for('national-result-search', function (Request $request): array {
            $perMinute = max(1, (int) config('national_lottery.rate_limit.per_minute', 20));
            $perHour = max($perMinute, (int) config('national_lottery.rate_limit.per_hour', 200));
            $fingerprintPerMinute = max(1, (int) config('national_lottery.rate_limit.fingerprint_per_minute', 6));

            $term = trim((string) $request->query('number', ''));

            if ($term === '') {
                $term = trim((string) $request->query('date', ''));
            }

            $fingerprint = $term === ''
                ? 'empty'
                : substr(hash_hmac('sha256', $term, (string) config('app.key', '')), 0, 32);

            return [
                Limit::perMinute($perMinute)
                    ->by('national-result-search:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perHour($perHour)
                    ->by('national-result-search:ip-hour:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perMinute($fingerprintPerMinute)
                    ->by('national-result-search:q:'.$fingerprint)
                    ->response($this->throttleResponse()),
            ];
        });

        // PROMPT 6: public Weekly Lottery search.
        //
        // Same three-ceiling shape as 'ticket-verification' and
        // 'national-result-search', for the same reason: the six-digit space
        // is enumerable, so an IP ceiling alone lets a distributed client walk
        // it while each address stays polite. The third ceiling is keyed on a
        // HASHED query, so repeating one term is cheap for a human refreshing
        // a page and expensive for a script grinding the space.
        //
        // The fingerprint is an HMAC of type+term with the app key, never the
        // term itself: a rate-limiter cache entry must not become a plaintext
        // record of what the public searched for.
        RateLimiter::for('weekly-result-search', function (Request $request): array {
            $perMinute = max(1, (int) config('weekly_lottery.rate_limit.per_minute', 20));
            $perHour = max($perMinute, (int) config('weekly_lottery.rate_limit.per_hour', 200));
            $fingerprintPerMinute = max(1, (int) config('weekly_lottery.rate_limit.fingerprint_per_minute', 6));

            $term = trim((string) $request->query('type', '')).'|'.trim((string) $request->query('term', ''));

            $fingerprint = trim($term, '|') === ''
                ? 'empty'
                : substr(hash_hmac('sha256', $term, (string) config('app.key', '')), 0, 32);

            return [
                Limit::perMinute($perMinute)
                    ->by('weekly-result-search:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perHour($perHour)
                    ->by('weekly-result-search:ip-hour:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perMinute($fingerprintPerMinute)
                    ->by('weekly-result-search:q:'.$fingerprint)
                    ->response($this->throttleResponse()),
            ];
        });

        // PROMPT 8: the Bingo/Mega search space is the same size as the
        // Weekly one, so it gets the same three ceilings. The query
        // fingerprint is HMAC'd, never stored raw: a limiter key is not a
        // place to keep a record of what visitors searched for.
        RateLimiter::for('bingo-result-search', function (Request $request): array {
            $perMinute = max(1, (int) config('bingo_lottery.rate_limit.per_minute', 20));
            $perHour = max($perMinute, (int) config('bingo_lottery.rate_limit.per_hour', 200));
            $fingerprintPerMinute = max(1, (int) config('bingo_lottery.rate_limit.fingerprint_per_minute', 6));

            $term = trim((string) $request->query('type', '')).'|'.trim((string) $request->query('term', ''));

            $fingerprint = trim($term, '|') === ''
                ? 'empty'
                : substr(hash_hmac('sha256', $term, (string) config('app.key', '')), 0, 32);

            return [
                Limit::perMinute($perMinute)
                    ->by('bingo-result-search:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perHour($perHour)
                    ->by('bingo-result-search:ip-hour:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perMinute($fingerprintPerMinute)
                    ->by('bingo-result-search:q:'.$fingerprint)
                    ->response($this->throttleResponse()),
            ];
        });

        // PROMPT 9: PCSO searches four widths rather than three, so the
        // space a crawler could walk is larger, not smaller. Same three
        // ceilings, same HMAC'd query fingerprint - a limiter key is not a
        // place to keep a record of what visitors searched for.
        // PROMPT 10: public contact submissions.
        //
        // A public POST that sends mail is an open relay without a ceiling.
        // Two dimensions here, both keyed on the REQUEST: the service applies
        // a third, hashed-email dimension it can only compute after
        // validation. Neither an address nor an IP is ever a cache key in
        // clear - a limiter store is a cache, and caches get dumped.
        RateLimiter::for('contact-submit', function (Request $request): array {
            $perMinute = max(1, (int) config('contact.anti_spam.per_minute', 3));
            // Not clamped to the per-minute figure: a lower hourly ceiling is
            // a real instruction, not a mistake to correct.
            $perHour = max(1, (int) config('contact.anti_spam.per_hour', 20));

            $sender = hash_hmac('sha256', 'contact|ip|'.$request->ip(), (string) config('app.key', ''));

            return [
                Limit::perMinute($perMinute)->by('contact-submit:min:'.$sender),
                Limit::perHour($perHour)->by('contact-submit:hour:'.$sender),
            ];
        });

        RateLimiter::for('pcso-result-search', function (Request $request): array {
            $perMinute = max(1, (int) config('pcso_lottery.rate_limit.per_minute', 20));
            $perHour = max($perMinute, (int) config('pcso_lottery.rate_limit.per_hour', 200));
            $fingerprintPerMinute = max(1, (int) config('pcso_lottery.rate_limit.fingerprint_per_minute', 6));

            $term = trim((string) $request->query('type', '')).'|'.trim((string) $request->query('term', ''));

            $fingerprint = trim($term, '|') === ''
                ? 'empty'
                : substr(hash_hmac('sha256', $term, (string) config('app.key', '')), 0, 32);

            return [
                Limit::perMinute($perMinute)
                    ->by('pcso-result-search:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perHour($perHour)
                    ->by('pcso-result-search:ip-hour:'.$request->ip())
                    ->response($this->throttleResponse()),
                Limit::perMinute($fingerprintPerMinute)
                    ->by('pcso-result-search:q:'.$fingerprint)
                    ->response($this->throttleResponse()),
            ];
        });

        // Account verification submit/upload — user-keyed, moderate ceiling
        // so legitimate multi-file uploads work but scraping cannot.
        $verifyPerMinute = (int) config('account.rate_limits.verification_submit_per_minute', 5);
        // PROMPT 3: password-recovery surfaces (request + reset POSTs).
        // Keyed on the normalized identifier hash AND the IP: an
        // attacker must not slow one victim's recovery, and one IP must
        // not enumerate identifiers. Thresholds read at REQUEST time
        // (config overridable) so policy can be tuned without a reboot.
        RateLimiter::for('password-reset', function (Request $request): array {
            $identifier = mb_strtolower(trim((string) $request->input('identifier', (string) $request->input('email', ''))));
            $maxPerMinute = max(1, (int) config('auth_security.password_reset.max_requests_per_minute', 5));

            return [
                Limit::perMinute($maxPerMinute)
                    ->by('password-reset:id:'.sha1($identifier))
                    ->response($this->throttleResponse()),
                Limit::perMinute(max(1, $maxPerMinute * 5))
                    ->by('password-reset:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
            ];
        });

        RateLimiter::for('account-verification', function (Request $request) use ($verifyPerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(max(1, $verifyPerMinute))
                ->by($identifier === null ? 'account-verification:ip:'.$request->ip() : 'account-verification:user:'.$identifier)
                ->response($this->throttleResponse());
        });

        // Grade page + history + refresh — user-keyed, cheap reads but not free-for-all.
        $gradePerMinute = (int) config('account.rate_limits.grade_history_per_minute', 30);
        RateLimiter::for('account-grade', function (Request $request) use ($gradePerMinute): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(max(1, $gradePerMinute))
                ->by($identifier === null ? 'account-grade:ip:'.$request->ip() : 'account-grade:user:'.$identifier)
                ->response($this->throttleResponse());
        });

        // Admin analytics and reconciliation reads are bounded independently
        // from player traffic. The authenticated operator id is part of the
        // key so one operator cannot consume another operator's allowance.
        RateLimiter::for('admin-analytics', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(max(1, (int) config('admin.rate_limits.analytics_per_minute', 60)))
                ->by($identifier === null ? 'admin-analytics:ip:'.$request->ip() : 'admin-analytics:user:'.$identifier)
                ->response($this->throttleResponse());
        });
    }

    /**
     * The `login` limiter used by the public token route.
     *
     * config/security.php already declared `rate_limits.login` with max_attempts,
     * decay_minutes and `by => 'email_and_ip'`, and nothing was reading it because the
     * project had no login route. Both halves of that key are used: the submitted
     * identifier (lower-cased so casing cannot multiply an attacker's allowance) and the
     * client IP. Keying on the identifier alone would let one host attack thousands of
     * accounts; keying on the IP alone would let a botnet attack one account.
     */
    private function registerLoginLimiter(): void
    {
        $maxAttempts = (int) config('security.rate_limits.login.max_attempts', 5);
        $decayMinutes = (int) config('security.rate_limits.login.decay_minutes', 15);

        RateLimiter::for('login', function (Request $request) use ($maxAttempts, $decayMinutes): array {
            $identifier = mb_strtolower(trim((string) $request->input('login', '')));

            return [
                Limit::perMinutes($decayMinutes, $maxAttempts)
                    ->by('login:id:'.sha1($identifier))
                    ->response($this->throttleResponse()),
                Limit::perMinutes($decayMinutes, $maxAttempts * 5)
                    ->by('login:ip:'.$request->ip())
                    ->response($this->throttleResponse()),
            ];
        });
    }

    /**
     * The throttled response, in the project's API envelope.
     *
     * Without this, Laravel returns its own plain `{"message": "Too Many Attempts."}` body,
     * which would be the one response on the whole surface that did not match the documented
     * envelope - so a client's error handling would break precisely when it is being rate
     * limited. The Retry-After header that the throttle middleware adds is preserved.
     */
    private function throttleResponse(): callable
    {
        return function (Request $request, array $headers = []): Response {
            return ApiResponse::error(
                BetPurchaseErrorMapper::CODE_RATE_LIMITED,
                'Too many requests. Please slow down and retry shortly.',
                429,
                [],
                $headers,
            );
        };
    }
}

```

## `app/Services/Payment/PaymentCallbackService.php`

# TYPE: PHP service
# PURPOSE: Read-only authoritative browser payment projection with bounded references.

```php
<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\PaymentCallbackData;
use App\Enums\AuditAction;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionStatus;
use App\Enums\RiskLevel;
use App\Exceptions\PaymentReconciliationException;
use App\Models\AuditLog;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Withdrawal;
use App\Services\Finance\DepositCompletionService;
use App\Services\Finance\WithdrawalCompletionService;
use Illuminate\Support\Facades\DB;

/**
 * Payment callback normalization + application.
 *
 * TRUST DISCIPLINE
 *   1. NOTHING FROM THE CLIENT BODY — this lane ingests ONLY provider
 *      envelopes already signature-verified. The caller of apply()
 *      must pass facts from PaymentCallbackData built off verified
 *      evidence.
 *   2. FACTS CHECKED, NOT ACCEPTED — the external reference resolves
 *      against the payments paper under the (gateway, reference) unique
 *      pair; amount and currency must agree by EXACT decimal;
 *      disagreements are pronounced refusals (reconciliation
 *      vocabulary), never guesses.
 *   3. MONEY NEVER MOVES HERE TWICE — the wallet-facing completion
 *      runs through the estate's OWN completion services with their
 *      own idempotency keys; a re-applied callback is arithmetic-free.
 *   4. INTERNAL STATUS IS THE ONLY STATUS — provider dialect is
 *      normalized ONCE (fromProviderWord) at the boundary and the
 *      dialect never leaks further.
 */
final class PaymentCallbackService
{
    public function __construct(
        private readonly DepositCompletionService $depositCompletion,
        private readonly WithdrawalCompletionService $withdrawalCompletion,
    ) {
    }

    /**
     * READ-ONLY status projection for browser-return pages (FINAL AUDIT #2).
     *
     * The browser return is a VIEW, never a state transition: this method
     * resolves the payments paper using only SAFE references (our own PAY-
     * reference, our own DP-/WD- document reference, or the gateway session
     * reference) and reports the state already recorded by verified
     * webhook processing or manual operations. It must not and cannot
     * credit, confirm, fail or cancel anything.
     *
     * A reference that belongs to somebody else is reported as not found:
     * existence of another player's payment is not disclosed.
     *
     * @return array{
     *     found: bool,
     *     reason?: string,
     *     payment?: \App\Models\Payment,
     *     document_reference?: string,
     *     state?: string,
     *     paid?: bool
     * }
     */
    public function browserReturnProjection(
        ?string $paymentReference,
        ?string $documentReference,
        ?string $gatewayReference,
        ?int $viewerId,
    ): array {
        $payment = null;
        $paymentReference = self::safeReference($paymentReference);
        $documentReference = self::safeReference($documentReference);
        $gatewayReference = self::safeReference($gatewayReference);

        if ($paymentReference !== null && preg_match('/^PAY-[A-Za-z0-9_-]{1,100}$/i', $paymentReference) === 1) {
            $payment = \App\Models\Payment::query()->where('reference_number', $paymentReference)->first();
        } elseif ($documentReference !== null && preg_match('/^(?:DP|WD)-[A-Za-z0-9_-]{1,100}$/i', $documentReference) === 1) {
            $deposit = \App\Models\Deposit::query()->where('reference_number', $documentReference)->first();

            if ($deposit instanceof \App\Models\Deposit) {
                $payment = \App\Models\Payment::query()
                    ->where('payable_type', \App\Models\Deposit::class)
                    ->where('payable_id', $deposit->getKey())
                    ->first();
            } else {
                $withdrawal = \App\Models\Withdrawal::query()->where('reference_number', $documentReference)->first();

                if ($withdrawal instanceof \App\Models\Withdrawal) {
                    $payment = \App\Models\Payment::query()
                        ->where('payable_type', \App\Models\Withdrawal::class)
                        ->where('payable_id', $withdrawal->getKey())
                        ->first();
                }
            }
        } elseif ($gatewayReference !== null && preg_match('/^[A-Za-z0-9._:-]{1,120}$/', $gatewayReference) === 1) {
            $payment = \App\Models\Payment::query()->where('gateway_reference', $gatewayReference)->first();
        }

        if (! $payment instanceof \App\Models\Payment) {
            return ['found' => false, 'reason' => 'reference_not_found'];
        }

        // Ownership: another player's payment is indistinguishable from a
        // nonexistent one as far as this viewer is concerned.
        if ($viewerId !== null && (int) $payment->user_id !== $viewerId) {
            return ['found' => false, 'reason' => 'reference_not_found'];
        }

        $state = match ($payment->status) {
            \App\Enums\PaymentStatus::Captured => 'confirmed',
            \App\Enums\PaymentStatus::Failed => 'failed',
            \App\Enums\PaymentStatus::Cancelled => 'cancelled',
            \App\Enums\PaymentStatus::Refunded, \App\Enums\PaymentStatus::PartiallyRefunded, \App\Enums\PaymentStatus::Disputed => 'updated',
            \App\Enums\PaymentStatus::Pending, \App\Enums\PaymentStatus::Authorized => 'pending',
        };

        return [
            'found' => true,
            'payment' => $payment,
            'document_reference' => (is_array($payment->metadata) ? ($payment->metadata['deposit_reference'] ?? $payment->metadata['withdrawal_reference'] ?? null) : null)
                ?? $payment->reference_number,
            'state' => $state,
            // "Paid" is proven ONLY by the internal captured status - never
            // by the URL, a query flag, or the route the browser landed on.
            'paid' => $payment->status === \App\Enums\PaymentStatus::Captured,
        ];
    }

    private static function safeReference(?string $reference): ?string
    {
        if (! is_string($reference)) {
            return null;
        }

        $reference = trim($reference);

        return $reference !== '' && strlen($reference) <= 120 ? $reference : null;
    }

    /* ------------------------------------------- normalization ------ */

    /**
     * Normalize a verified provider payload into a callback fact-set.
     * Reads the standard dialect shapes (flat and `data.object` nested)
     * WITHOUT trusting any of them — the caller re-proves identity by
     * resolving the external reference on the payments paper.
     *
     * @throws PaymentReconciliationException
     */
    public function normalizeFromPayload(string $providerCode, array $payload): PaymentCallbackData
    {
        $facts = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : $payload;

        $reference = $facts['reference'] ?? $facts['gateway_reference'] ?? $facts['payment_reference'] ?? $facts['id'] ?? null;
        $amount = $facts['amount'] ?? null;
        $currency = $facts['currency'] ?? null;
        $statusWord = $facts['status'] ?? $payload['status'] ?? null;

        if (! is_string($reference) || trim($reference) === '') {
            throw PaymentReconciliationException::malformed('the payload carries no external reference');
        }

        if ($amount === null || ! (is_int($amount) || is_float($amount) || is_string($amount)) || ! preg_match('/^\d+(\.\d{1,2})?$/', is_string($amount) ? trim($amount) : number_format((float) $amount, 2, '.', ''))) {
            throw PaymentReconciliationException::amountMismatch(trim($reference), '(on paper)', (string) $amount, (string) ($currency ?? '??'));
        }

        $normalizedAmount = is_string($amount) ? trim($amount) : number_format((float) $amount, 2, '.', '');

        if (! is_string($currency) || trim($currency) === '') {
            throw PaymentReconciliationException::malformed('the payload carries no currency');
        }

        if (! is_string($statusWord) || trim($statusWord) === '') {
            throw PaymentReconciliationException::malformed('the payload carries no provider status word');
        }

        return PaymentCallbackData::fromInput(
            providerCode: $providerCode,
            externalReference: trim($reference),
            amount: $normalizedAmount,
            currency: trim($currency),
            providerStatus: $statusWord,
            failureReason: is_string($facts['failure_reason'] ?? null) ? $facts['failure_reason'] : null,
            signature: is_string($payload['signature'] ?? null) ? $payload['signature'] : null,
            payloadFingerprint: (string) ($payload['payload_fingerprint'] ?? \App\DTOs\Payment\PaymentWebhookData::fingerprintOf($providerCode, (string) ($payload['id'] ?? $payload['event_id'] ?? ''), $payload)),
            providerEventId: is_string($payload['id'] ?? null) ? $payload['id'] : (is_string($payload['event_id'] ?? null) ? $payload['event_id'] : null),
        );
    }

    /* ---------------------------------------------- application ----- */

    /**
     * Apply a normalized, verified callback to internal state.
     * Idempotent at every layer: repeated application of the same
     * verified facts changes nothing (exactly-once by existing
     * determinism of the completion lanes).
     *
     * @return array{payment: Payment, replayed: bool, applied_at: ?string}
     *
     * @throws PaymentReconciliationException
     */
    public function apply(PaymentCallbackData $callback): array
    {
        return DB::transaction(function () use ($callback): array {
            /** @var Payment|null $payment */
            $payment = Payment::query()
                ->lockForUpdate()
                ->where('gateway', strtolower($callback->providerCode))
                ->where('gateway_reference', $callback->externalReference)
                ->first();

            if (! $payment instanceof Payment) {
                throw PaymentReconciliationException::missingTransaction($callback->providerCode, $callback->externalReference);
            }

            // FACTS PROVEN AGAINST THE PAPER, not against the caller.
            if (bccomp(self::moneyOf((string) $payment->amount), self::moneyOf($callback->amount), 2) !== 0) {
                throw PaymentReconciliationException::amountMismatch(
                    $callback->externalReference,
                    self::moneyOf((string) $payment->amount),
                    self::moneyOf($callback->amount),
                    ($payment->currency instanceof \BackedEnum ? (string) $payment->currency->value : (string) $payment->currency),
                );
            }

            if (strtoupper(($payment->currency instanceof \BackedEnum ? (string) $payment->currency->value : (string) $payment->currency)) !== $callback->currency) {
                throw PaymentReconciliationException::malformed(
                    sprintf('currency fork: paper speaks %s, callback speaks %s', ($payment->currency instanceof \BackedEnum ? (string) $payment->currency->value : (string) $payment->currency), $callback->currency),
                    ['reference' => $callback->externalReference],
                );
            }

            $replayed = in_array($payment->status instanceof PaymentStatus ? $payment->status : PaymentStatus::tryFrom((string) $payment->status), [PaymentStatus::Captured, PaymentStatus::Refunded], true)
                && in_array($callback->providerStatus, [PaymentTransactionStatus::Succeeded, PaymentTransactionStatus::Reversed], true);

            // Apply the provider's verdict — money through the estate's
            // own completion lanes, NEVER raw arithmetic.
            if ($callback->providerStatus === PaymentTransactionStatus::Succeeded && $payment->status !== PaymentStatus::Captured) {
                $payable = $payment->payable;

                if ($payable instanceof Deposit) {
                    $this->depositCompletion->complete($payable, $callback->callbackKey());
                } elseif ($payable instanceof Withdrawal) {
                    $this->withdrawalCompletion->complete($payable, $callback->callbackKey());
                }

                $payment->status = PaymentStatus::Captured;
                $payment->authorized_at = $payment->authorized_at ?? now();
                $payment->captured_at = now();
            } elseif ($callback->providerStatus === PaymentTransactionStatus::Failed && ! ($payment->status instanceof PaymentStatus ? $payment->status : PaymentStatus::tryFrom((string) $payment->status))->isFinal()) {
                $payment->status = PaymentStatus::Failed;
                $payment->failure_reason = ($callback->failureReason ?? PaymentFailureReason::Unknown)->value;
                $payment->failed_at = now();
            } elseif ($callback->providerStatus === PaymentTransactionStatus::Reversed && $payment->status === PaymentStatus::Captured) {
                $payment->status = PaymentStatus::Refunded;
            } elseif ($callback->providerStatus === PaymentTransactionStatus::Processing && $payment->status === PaymentStatus::Pending) {
                $payment->status = PaymentStatus::Authorized;
                $payment->authorized_at = now();
            } else {
                // No state change required — recorded as evidence only.
            }

            $metadata = is_array($payment->metadata) ? $payment->metadata : [];
            $metadata['provider_normalized'] = [
                'status' => $callback->providerStatus->value,
                'reason' => $callback->failureReason?->value,
                'fingerprint' => $callback->payloadFingerprint,
                'at' => now()->toIso8601String(),
            ];
            $payment->metadata = $metadata;
            $payment->save();

            $this->recordAudit($payment, sprintf(
                'Callback applied: [%s/%s] → %s%s',
                $callback->providerCode,
                $callback->externalReference,
                $callback->providerStatus->value,
                $callback->failureReason instanceof PaymentFailureReason ? ' ('.$callback->failureReason->value.')' : '',
            ), RiskLevel::High);

            return ['payment' => $payment, 'replayed' => $replayed, 'applied_at' => $metadata['provider_normalized']['at']];
        });
    }

    /* ------------------------------------------------- internals ---- */

    public static function moneyOf(string $amount): string
    {
        if (extension_loaded('bcmath')) {
            return bcadd($amount, '0', 2);
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private function recordAudit(Payment $payment, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => (int) $payment->user_id,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => Payment::class,
            'auditable_id' => (int) $payment->getKey(),
            'description' => $description,
            'metadata' => [
                'payment_id' => (int) $payment->id,
                'gateway' => (string) $payment->gateway,
                'gateway_reference' => (string) ($payment->gateway_reference ?? ''),
                'lane' => 'payment-callback',
            ],
        ]);

        $log->save();
    }
}

```

## `app/Services/PublicPages/ResultsPageService.php`

# TYPE: PHP service
# PURPOSE: Public results rows limited to published/completed draws whose scheduled time has passed.

```php
<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

use App\Enums\GloSourceState;
use App\Models\Draw;
use App\Models\DrawResult;

/**
 * Service providing projection data for the public Results landing page.
 *
 * Ensures no direct database queries are run in Blade views, and provides
 * safe, explicit allowlist-based mapping for result source provenance.
 */
final class ResultsPageService
{
    /**
     * @return array{
     *     currentStatus: ?Draw,
     *     rows: list<array{
     *         draw_number: string,
     *         draw_date: ?string,
     *         first_prize: ?string,
     *         second_prize: mixed,
     *         third_prize: mixed,
     *         consolation_prizes: mixed,
     *         source_state: string,
     *         fixture_sample: bool,
     *         result_version: string,
     *     }>
     * }
     */
    public function resultsData(): array
    {
        $currentStatus = Draw::query()->orderByDesc('scheduled_at')->first();

        $latestQuery = Draw::query()
            ->whereIn('status', ['result_published', 'completed'])
            ->where('scheduled_at', '<=', now())
            ->orderByDesc('scheduled_at')
            ->limit(12);

        $rows = [];
        foreach ($latestQuery->get() as $draw) {
            $result = DrawResult::query()->where('draw_id', $draw->getKey())->first();
            if ($result === null) {
                continue;
            }

            $meta = is_array($result->metadata) ? $result->metadata : [];
            $lane = is_array($meta['glo'] ?? null) ? $meta['glo'] : [];
            $provider = strtolower(trim((string) ($lane['import_provider'] ?? '')));

            // Explicit allowlist mapping: unknown never degrades to official
            $sourceState = match ($provider) {
                'official', 'official_api', 'official_scraper', 'glo_official' => GloSourceState::OfficialSourceVerified,
                'fixture', 'fixture_only' => GloSourceState::FixtureOnly,
                'internal', 'manual', 'admin_entry' => GloSourceState::InternalReconciled,
                'not_configured' => GloSourceState::NotConfigured,
                default => GloSourceState::Unavailable,
            };

            $rows[] = [
                'draw_number' => (string) $draw->draw_number,
                'draw_date' => $draw->scheduled_at?->toDateString(),
                'first_prize' => $result->first_prize,
                'second_prize' => $result->second_prize ?? [],
                'third_prize' => $result->third_prize ?? [],
                'consolation_prizes' => $result->consolation_prizes ?? [],
                'source_state' => $sourceState->value,
                'fixture_sample' => $sourceState === GloSourceState::FixtureOnly,
                'result_version' => (string) ($lane['import_fingerprint'] ?? ('pub-'.$result->getKey())),
            ];
        }

        return [
            'currentStatus' => $currentStatus,
            'rows' => $rows,
        ];
    }
}

```

## `app/Services/Account/AccountVerificationService.php`

# TYPE: PHP service
# PURPOSE: Bounded opaque reviewer document-token resolution bound to the authenticated reviewer.

```php
<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Enums\AuditAction;
use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use App\Models\AccountVerification;
use App\Models\AccountVerificationDocument;
use App\Models\AuditLog;
use App\Models\KycDocument;
use App\Models\User;
use App\Services\Security\KycVerificationService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Account Verification FACADE over the existing canonical KYC stack.
 *
 * ONE identity state: this service never invents a second status
 * vocabulary — it maps KycStatus / KycVerificationStatus to the public
 * NOT_SUBMITTED…EXPIRED words for the account page only.
 *
 * Submissions compose App\Services\Security\KycVerificationService
 * (private storage + audit) and Compliance KycVerificationService
 * (derived verdicts). Duplicate open requests are rejected; clients
 * can never supply status=approved.
 */
final class AccountVerificationService
{
    public function __construct(
        private readonly KycVerificationService $kycUpload,
        private readonly AccountVerificationDocumentService $documents,
    ) {
    }

    /**
     * Public status for the logged-in user (server-derived).
     */
    public function publicStatus(User $user): string
    {
        $kyc = $user->kycStatus();

        return AccountVerification::publicStatusFromKycStatus($kyc);
    }

    /**
     * Account information block safe for the owner only.
     *
     * @return array<string, mixed>
     */
    public function accountInfo(User $user): array
    {
        $joined = $user->created_at?->format('Y-m-d') ?? '';
        // Renewal: yearly anniversary of join when tracked; else NOT_CONFIGURED.
        $renew = $joined !== ''
            ? $user->created_at?->copy()->addYear()->format('Y-m-d')
            : null;

        return [
            // The internal numeric user key is never an account number and is
            // not exposed on an owner-facing page.
            'account_number' => null,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'join_date' => $joined,
            'renew_date' => $renew ?? 'NOT_CONFIGURED',
            'verification_status' => $this->publicStatus($user),
            'method' => (string) config('account.verification.method', 'DOCUMENT_UPLOAD_VERIFICATION'),
            'phone_verification' => (bool) config('account.verification.phone.otp_enabled', false)
                ? 'CONFIGURED'
                : 'PHONE_VERIFICATION_NOT_CONFIGURED',
            'phone' => $user->phone !== null && $user->phone !== ''
                ? $this->maskPhone((string) $user->phone)
                : null,
            'phone_verified' => $user->phone_verified_at !== null,
        ];
    }

    /**
     * Whether an open verification conversation blocks a new submit.
     */
    public function hasOpenRequest(User $user): bool
    {
        $openDoc = KycDocument::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [KycStatus::Pending, KycStatus::UnderReview])
            ->exists();

        if ($openDoc) {
            return true;
        }

        return AccountVerification::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ['pending', 'under_review'])
            ->exists();
    }

    /**
     * Submit a verification package: optional phone capture (server
     * normalized, never trusted as verified) + one primary document.
     *
     * Client-supplied status / phone_verified / approved are ignored.
     *
     * @param  array{
     *     country_code?: string|null,
     *     mobile?: string|null,
     *     document_type: string,
     *     document_number?: string|null,
     *     document: UploadedFile,
     *     document_back?: UploadedFile|null,
     * }  $payload
     *
     * @return array{status: string, document: AccountVerificationDocument, open_request: bool}
     *
     * @throws InvalidArgumentException
     */
    public function submit(User $user, array $payload, ?string $ipAddress = null): array
    {
        if ($this->hasOpenRequest($user)) {
            throw new InvalidArgumentException(
                (string) trans('account_services.verification_error_duplicate'),
            );
        }

        $typeValue = (string) ($payload['document_type'] ?? '');
        $type = KycDocumentType::tryFrom($typeValue);
        if (! $type instanceof KycDocumentType) {
            throw new InvalidArgumentException(
                (string) trans('account_services.verification_error_invalid_type'),
            );
        }

        // Phone: normalize + store contact only; NEVER set phone_verified.
        $mobile = $this->normalizeMobile(
            (string) ($payload['country_code'] ?? ''),
            (string) ($payload['mobile'] ?? ''),
        );
        if ($mobile !== null) {
            // Explicit attribute write — phone is fillable but verified_at is not.
            if ($user->phone !== $mobile) {
                $user->phone = $mobile;
                $user->save();
            }
        }

        $front = $payload['document'] ?? null;
        if (! $front instanceof UploadedFile) {
            throw new InvalidArgumentException('A document file is required.');
        }

        // Hardened document path (MIME/ext/size/path-traversal/fingerprint).
        $document = $this->documents->storeDocument(
            user: $user,
            type: $type,
            file: $front,
            documentNumber: isset($payload['document_number']) ? (string) $payload['document_number'] : null,
            ipAddress: $ipAddress,
        );

        // Optional second file (back / support) under the same rules.
        $back = $payload['document_back'] ?? null;
        if ($back instanceof UploadedFile) {
            $this->documents->storeDocument(
                user: $user,
                type: KycDocumentType::Other,
                file: $back,
                documentNumber: null,
                ipAddress: $ipAddress,
            );
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => AuditAction::Create,
            'auditable_type' => AccountVerification::class,
            'auditable_id' => $document->id,
            'ip_address' => $ipAddress,
            'metadata' => [
                'action_type' => 'account_verification_submitted',
                'document_id' => $document->id,
                'document_type' => $type->value,
                'method' => (string) config('account.verification.method'),
            ],
        ]);

        return [
            'status' => 'PENDING',
            'document' => $document,
            'open_request' => true,
        ];
    }

    /**
     * Owner-scoped document list (metadata only — never storage URLs).
     *
     * @return list<array<string, mixed>>
     */
    public function documentsFor(User $user): array
    {
        return AccountVerificationDocument::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (AccountVerificationDocument $doc): array => [
                ...$doc->toPublicArray(),
                'download_token' => $this->documentDownloadToken($user, $doc),
            ])
            ->all();
    }

    /**
     * Resolve an owner-scoped document from its opaque download token.
     * The token contains no database key; ownership is still checked by
     * the query before the private storage service is called.
     */
    public function documentForDownload(User $user, string $token): ?AccountVerificationDocument
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        foreach (AccountVerificationDocument::query()
            ->where('user_id', $user->id)
            ->get() as $document) {
            if (hash_equals($this->documentDownloadToken($user, $document), $token)) {
                return $document;
            }
        }

        return null;
    }

    /**
     * Opaque reviewer token for an admin document action. The token contains
     * no database key and is resolved through a bounded canonical KYC query.
     */
    public function reviewerDocumentToken(KycDocument $document, User $reviewer): string
    {
        return hash_hmac(
            'sha256',
            'reviewer:'.(string) $reviewer->getKey().':'.(string) $document->getKey(),
            (string) config('app.key'),
        );
    }

    public function documentForReviewer(User $reviewer, string $token): ?KycDocument
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $limit = max(1, min(10000, (int) config('account.verification.admin_document_lookup_limit', 1000)));
        foreach (KycDocument::query()->limit($limit)->get() as $document) {
            if (hash_equals($this->reviewerDocumentToken($document, $reviewer), $token)) {
                return $document;
            }
        }

        return null;
    }

    /**
     * Reviewer-only decision path (authorization enforced by controller/policy).
     * Delegates to the canonical Security service — four-eyes + audit included.
     */
    public function review(
        KycDocument $document,
        User $reviewer,
        bool $approved,
        ?string $reason = null,
    ): void {
        $this->kycUpload->reviewDocument($document, $reviewer, $approved, $reason);

        AuditLog::create([
            'user_id' => $reviewer->id,
            'action' => AuditAction::Update,
            'auditable_type' => AccountVerification::class,
            'auditable_id' => $document->id,
            'metadata' => [
                'action_type' => $approved ? 'account_verification_approved' : 'account_verification_rejected',
                'target_user_id' => $document->user_id,
            ],
        ]);
    }

    private function documentDownloadToken(User $user, AccountVerificationDocument $document): string
    {
        return hash_hmac(
            'sha256',
            (string) $user->getKey().':'.(string) $document->getKey(),
            (string) config('app.key'),
        );
    }

    private function normalizeMobile(string $countryCode, string $mobile): ?string
    {
        $cc = trim($countryCode);
        $num = trim($mobile);
        if ($cc === '' && $num === '') {
            return null;
        }
        if ($num === '') {
            return null;
        }

        $defaultCc = (string) config('account.verification.phone.default_country_code', '+66');
        if ($cc === '') {
            $cc = $defaultCc;
        }
        // Country code: + and 1–4 digits.
        if (! preg_match('/^\+\d{1,4}$/', $cc)) {
            throw new InvalidArgumentException('Invalid country code.');
        }
        // Digits only in the national number (allow spaces/dashes stripped).
        $digits = preg_replace('/[\s\-().]/', '', $num) ?? '';
        if ($digits === '' || ! preg_match('/^\d{5,15}$/', $digits)) {
            throw new InvalidArgumentException('Invalid mobile number.');
        }

        $max = (int) config('account.verification.phone.max_length', 20);
        $combined = $cc.$digits;
        if (strlen($combined) > $max) {
            throw new InvalidArgumentException('Mobile number too long.');
        }

        return $combined;
    }

    private function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }

        return str_repeat('•', $len - 4).substr($phone, -4);
    }
}

```

## `bootstrap/app.php`

# TYPE: PHP bootstrap
# PURPOSE: Explicit admin authentication middleware alias registration.

```php
<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withCommands([
        // Registered explicitly rather than relying on discovery, so the console
        // surface of this application is something you can read in one place.
        __DIR__.'/../app/Console/Commands',
    ])
    ->withSchedule(function (Schedule $schedule): void {
        // ---------------------------------------------------------------------
        // Draw automation
        // ---------------------------------------------------------------------
        //
        // WHAT THIS FIXES
        // Phases 1 to 5.1 built a complete draw lifecycle - open, close, await
        // result, publish, settle - and nothing invoked it. config('lottery') had
        // declared draws on the 1st and 16th at 15:00 and an auto-close five minutes
        // before the draw since Phase 1, and no code read either value. A draw
        // existed only if somebody inserted a row and moved only if somebody called
        // a service by hand. This schedule is what makes the declared calendar real.
        //
        // ONE TASK, NOT FIVE
        // lottery:tick runs the five steps in order inside one process, because the
        // order matters within a single minute: a draw provisioned at 14:54 must be
        // able to open, and a draw whose cut-off is 14:55 must close, in the same
        // tick. Five separate scheduled tasks would spread that across five minutes
        // and make the effective cut-off drift.
        //
        // WHAT IS DELIBERATELY NOT SCHEDULED
        // Result publication. The official numbers come from outside this system and
        // config('lottery.results.require_admin_confirmation') says a human confirms
        // them, so publication is the operator command lottery:publish-result and
        // appears nowhere in this file.
        //
        // THE KILL SWITCH IS HONOURED HERE TOO
        // With config('lottery.automation.enabled') false, NO task is registered at
        // all - not a task that returns early. The commands carry the same guard, so
        // a manual run is also refused unless it is forced.
        if ((bool) config('lottery.automation.enabled', false) === true) {
            $expression = config('lottery.automation.tick_cron');
            $expression = is_string($expression) && trim($expression) !== '' ? trim($expression) : '* * * * *';

            $schedule->command('lottery:tick')
                ->cron($expression)
                // A tick that overruns must never run twice at once: two ticks could
                // both see the same draw as due. The lifecycle would refuse the
                // second transition anyway, but overlapping runs would fill the log
                // with refusals that look like defects.
                ->withoutOverlapping(10)
                // The scheduler must stay responsive for other work, and a tick that
                // settles a large draw is not instant.
                ->runInBackground()
                ->onOneServer()
                ->timezone(config('lottery.timezone', 'UTC'))
                ->appendOutputTo(storage_path('logs/lottery-tick.log'))
                ->description('Advance every draw that is due to its next lifecycle state');
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'admin.auth' => \App\Http\Middleware\Authenticate::class,
            'wallet.active' => \App\Http\Middleware\EnsureWalletIsActive::class,
            'draw.open' => \App\Http\Middleware\EnsureDrawIsOpen::class,
            'webhook.signature' => \App\Http\Middleware\VerifyWebhookSignature::class,
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
            'glo.permission' => \App\Http\Middleware\GloEnsurePermission::class,
            // Safe public-cache headers for /about, /vision, /terms only (guest GETs).
            'public.legal' => \App\Http\Middleware\PublicLegalHeaders::class,
        ]);

        // Applied globally: security headers, and a correlation id pinned on the way in and
        // echoed back out on the response so the whole request is traceable. Global append
        // (rather than web/api group append) is deliberate: the framework's /up health route
        // carries no middleware group at all, and an operator liveness probe is exactly the
        // kind of request that must still present security headers and a trace id.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->append(\App\Http\Middleware\CorrelationIdMiddleware::class);
        $middleware->append(\App\Http\Middleware\SetLocale::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // ---------------------------------------------------------------------
        // API exception rendering (Phase 4.4)
        // ---------------------------------------------------------------------
        //
        // Every exception that escapes an /api/* route is rendered through the same
        // envelope the controllers use, and through the same mapper, so a client never
        // has to parse two different error shapes.
        //
        // WHY THIS EXISTS AT ALL, GIVEN THE CONTROLLERS ALREADY CATCH
        // The purchase controller catches Throwable around the purchase call, so domain
        // failures are already mapped there. This handler covers what a controller
        // cannot: a failure BEFORE the controller runs (auth middleware, throttle
        // middleware, route resolution, request validation) and anything genuinely
        // unexpected. Without it, those cases would return Laravel's own default shapes -
        // and in a misconfigured environment, a stack trace.
        //
        // WHAT IS NEVER IN THE RESPONSE
        // No stack trace, no file path, no line number, no SQL, no exception class name,
        // no wallet id, no balance. The mapper builds the body from a fixed set of codes
        // and a whitelist of safe context keys; it never forwards an exception message it
        // did not author. This holds regardless of APP_DEBUG - a production-shaped
        // response is what the API returns even when a developer has debug enabled
        // locally, because an API client should never receive a body whose shape depends
        // on a server setting.
        //
        // The detail is not lost. It goes to the log, where operators can read it and
        // players cannot.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            // An HttpResponseException CARRIES a response that an earlier layer built
            // deliberately - the rate limiter's own 429, for example, which the limiter
            // registered in AppServiceProvider already emits in this project's envelope.
            // Re-mapping it here would discard that response and replace a correct 429
            // with a generic 500, so it is passed through untouched.
            if ($e instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                return null;
            }

            $mapper = app(\App\Http\Support\BetPurchaseErrorMapper::class);
            $mapped = $mapper->map($e);

            if ($mapped['status'] >= 500) {
                Log::error('api.unhandled_exception', [
                    'exception' => $e,
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'user_id' => $request->user()?->getAuthIdentifier(),
                ]);
            }

            $headers = [];

            // Preserve Retry-After and X-RateLimit-* headers that the throttle
            // middleware attached, so a well-behaved client still learns when it may
            // retry even though the body has been reshaped.
            if ($e instanceof HttpExceptionInterface) {
                $headers = $e->getHeaders();
                unset($headers['Content-Type']);
            }

            return \App\Http\Responses\ApiResponse::error(
                $mapped['code'],
                $mapped['message'],
                $mapped['status'],
                $mapped['details'],
                $headers,
            );
        });

        // An unauthenticated API request must not be redirected to a login route.
        // Laravel's default is a redirect for non-JSON requests, and this project has no
        // web login route at all, so that default would turn a missing token into a
        // route-not-defined error instead of a clean 401.
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e): bool {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();

```

## `routes/web.php`

# TYPE: PHP route file
# PURPOSE: Public results/privacy and secured admin/KYC/payment route definitions.

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\LottoFinExecutiveDashboardController;
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
});

/*
|--------------------------------------------------------------------------
| Agent Portal Routes
|--------------------------------------------------------------------------
*/
Route::prefix('agent')->name('agent.')->group(function (): void {
    Route::get('/', function () { return view('agent.dashboard'); })->name('dashboard');
    Route::get('/dashboard', function () { return view('agent.dashboard'); })->name('dashboard.index');
    Route::get('/commissions', function () { return view('agent.commissions'); })->name('commissions');
    Route::get('/settlements', function () { return view('agent.settlements'); })->name('settlements');
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

## `resources/views/results/index.blade.php`

# TYPE: Blade view
# PURPOSE: Canonical public results hub with explicit source states.

```php
@extends('layouts.app')

@section('title', __('results.meta_title'))
@section('meta_description', __('results.meta_description'))

@section('content')
<main class="min-h-screen bg-[#0B0904] text-white pt-24 pb-20 px-4 sm:px-6 lg:px-8" data-results-hub>
    <div class="max-w-6xl mx-auto space-y-10">
        <section class="space-y-4 text-center" aria-labelledby="results-title">
            <p class="text-xs uppercase tracking-[0.22em] text-[#D4AF37]">{{ __('results.published_results') }}</p>
            <h1 id="results-title" class="text-4xl sm:text-5xl font-black tracking-tight text-[#F5E6B8]">
                {{ __('results.title') }}
            </h1>
            <p class="max-w-3xl mx-auto text-base text-gray-300 leading-relaxed">
                {{ __('results.lead') }}
            </p>
        </section>

        <section class="flex flex-col sm:flex-row justify-center gap-3" aria-label="{{ __('results.title') }} actions">
            <a href="{{ route('ticket-check') }}" class="rounded-xl bg-[#D4AF37] px-5 py-3 text-center text-sm font-bold text-[#0B0904] focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                {{ __('results.check_link') }}
            </a>
            <a href="{{ route('results.search') }}" class="rounded-xl border border-[#D4AF37]/40 px-5 py-3 text-center text-sm font-semibold text-[#F5E6B8] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#D4AF37]">
                {{ __('results.search_link') }}
            </a>
        </section>

        @if (empty($rows))
            <section class="rounded-3xl border border-white/10 bg-[#141007] p-8 text-center" aria-live="polite">
                <h2 class="text-xl font-bold text-[#F5E6B8]">{{ __('results.no_public_data') }}</h2>
                <p class="mt-2 text-sm text-gray-400">{{ __('results.unavailable') }}</p>
            </section>
        @else
            <section aria-labelledby="results-list-title">
                <h2 id="results-list-title" class="mb-5 text-2xl font-black text-[#F5E6B8]">{{ __('results.published_results') }}</h2>
                <div class="overflow-x-auto rounded-3xl border border-[#D4AF37]/20 bg-[#141007] shadow-2xl">
                    <table class="min-w-[860px] w-full text-left text-sm">
                        <caption class="sr-only">{{ __('results.published_results') }}</caption>
                        <thead class="border-b border-white/10 text-xs uppercase tracking-wider text-gray-400">
                            <tr>
                                <th scope="col" class="px-5 py-4">{{ __('results.draw') }}</th>
                                <th scope="col" class="px-5 py-4">{{ __('results.date') }}</th>
                                <th scope="col" class="px-5 py-4">{{ __('results.first_prize') }}</th>
                                <th scope="col" class="px-5 py-4">{{ __('results.second_prize') }}</th>
                                <th scope="col" class="px-5 py-4">{{ __('results.third_prize') }}</th>
                                <th scope="col" class="px-5 py-4">{{ __('results.source') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            @foreach ($rows as $row)
                                @php
                                    $source = (string) ($row['source_state'] ?? 'unavailable');
                                    $sourceLabel = match ($source) {
                                        'OFFICIAL_SOURCE_VERIFIED' => __('results.official_source_verified'),
                                        'INTERNAL_RECONCILED' => __('results.internal_reconciled'),
                                        'FIXTURE_ONLY' => __('results.fixture_only'),
                                        'NOT_CONFIGURED' => __('results.not_configured'),
                                        default => __('results.unavailable_source'),
                                    };
                                    $list = static function (mixed $value): string {
                                        if (is_array($value)) {
                                            return implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
                                        }
                                        return is_scalar($value) && $value !== null && $value !== '' ? (string) $value : __('results.no_prize_data');
                                    };
                                @endphp
                                <tr class="align-top">
                                    <td class="px-5 py-4 font-semibold text-white">{{ $row['draw_number'] ?: __('results.not_published') }}</td>
                                    <td class="px-5 py-4 text-gray-300">{{ $row['draw_date'] ?: __('results.not_published') }}</td>
                                    <td class="px-5 py-4 font-mono text-[#F5E6B8]">{{ $row['first_prize'] ?: __('results.no_prize_data') }}</td>
                                    <td class="px-5 py-4 font-mono text-gray-200">{{ $list($row['second_prize'] ?? null) }}</td>
                                    <td class="px-5 py-4 font-mono text-gray-200">{{ $list($row['third_prize'] ?? null) }}</td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full border border-[#D4AF37]/30 px-3 py-1 text-xs font-semibold text-[#F5E6B8]" data-source-state="{{ $source }}" aria-label="{{ __('results.source') }}: {{ $sourceLabel }}">
                                            {{ $sourceLabel }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</main>
@endsection

```

## `resources/views/admin/dashboard.blade.php`

# TYPE: Blade view
# PURPOSE: No-fabrication admin dashboard and panel projections.

```php
@extends('layouts.admin')

@section('title', __('admin.title'))

@section('content')
<div class="flex w-full flex-col gap-6" aria-labelledby="admin-page-title">
    @php
        $panelKey = (string) ($panel ?? 'dashboard');
        $panelTitle = match ($panelKey) {
            'draws' => __('admin.draws'),
            'risk' => __('admin.risk'),
            'bets' => __('admin.bets'),
            'wallets' => __('admin.wallets'),
            'ledger' => __('admin.ledger'),
            'reconciliation' => __('admin.reconciliation'),
            'audits' => __('admin.audits'),
            'kyc' => __('admin.kyc'),
            'payments' => __('admin.payments'),
            'withdrawals' => __('admin.withdrawals'),
            'compliance' => __('admin.compliance'),
            default => __('admin.dashboard'),
        };
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
                            <tr><td colspan="{{ $panelKey === 'kyc' ? 7 : 6 }}" class="py-8 text-center text-slate-400" role="status">{{ __('admin.no_records') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
@endsection

```

## `resources/views/home/check.blade.php`

# TYPE: Blade view
# PURPOSE: Translated server-side ticket checking UI.

```php
@extends('layouts.app')

@section('title', $home['text']['check_title'] ?? __('home.check_title'))

@section('content')
    <div class="home-page home-page--narrow">
        <h1 class="home-page__title">{{ $home['text']['check_title'] ?? __('home.check_title') }}</h1>
        <p class="home-muted">{{ $home['text']['check_help'] ?? __('home.check_help') }}</p>

        @if (!empty($error))
            <p class="home-error" role="alert">{{ $error }}</p>
        @endif

        <form class="home-check-form home-check-form--page"
              method="POST"
              action="{{ route('ticket-check.submit') }}"
              accept-charset="UTF-8">
            @csrf
            <div class="home-field">
                <label for="check-number">{{ $home['text']['check_label'] ?? __('home.check_label') }}</label>
                <input
                    id="check-number"
                    name="number"
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    pattern="\d{6}"
                    maxlength="6"
                    minlength="6"
                    required
                    value="{{ old('number', $number) }}"
                    placeholder="{{ $home['text']['check_placeholder'] ?? __('home.check_placeholder') }}"
                    class="home-input home-input--digits"
                    aria-describedby="check-help"
                >
                <p id="check-help" class="home-help">{{ $home['text']['check_help'] ?? '' }}</p>
                @error('number')
                    <p class="home-error" role="alert">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit" class="home-btn home-btn--primary">{{ $home['text']['check_button'] ?? __('home.check_button') }}</button>
        </form>

        @if (is_array($result))
            <section class="home-card home-check-result" aria-live="polite" aria-labelledby="check-result-title">
                <div class="home-card__head">
                    <h2 id="check-result-title">{{ $home['text']['check_result_title'] ?? __('home.check_result_title') }}</h2>
                    @if (!empty($result['source_state']))
                        <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($result['source_state'], '-') }}">
                            {{ $result['source_state'] }}
                        </span>
                    @endif
                </div>

                <p>
                    {{ $home['text']['check_ticket_label'] ?? __('home.check_ticket_label') }}
                    <strong class="home-digits" data-ticket-digit>{{ $result['ticket_number'] ?? $number }}</strong>
                    @if (!empty($result['draw_number']))
                        · {{ $home['text']['check_draw_label'] ?? __('home.check_draw_label') }} {{ $result['draw_number'] }}
                        @if (!empty($result['draw_date'])) · {{ $result['draw_date'] }} @endif
                    @endif
                </p>

                @if (!empty($result['won']))
                    <p class="home-check-result__won" role="status">
                        {{ $home['text']['check_matched_label'] ?? __('home.check_matched_label') }}
                        <strong><span data-money-thb>{{ $result['total_prize'] ?? '0.00' }}</span> THB</strong>
                    </p>
                    @if (!empty($result['matches']) && is_array($result['matches']))
                        <ul class="home-check-result__matches" role="list">
                            @foreach ($result['matches'] as $match)
                                <li>
                                    @if (is_array($match))
                                        {{ json_encode($match, JSON_UNESCAPED_UNICODE) }}
                                    @else
                                        {{ $match }}
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @else
                    <p class="home-muted" role="status">
                        @if (!empty($result['message']))
                            {{ $result['message'] }}
                        @else
                            {{ $result['claim_hint'] ?? ($home['text']['check_no_match'] ?? __('home.check_no_match')) }}
                        @endif
                    </p>
                @endif

                @if (!empty($result['claim_hint']) && !empty($result['won']))
                    <p class="home-help">{{ $result['claim_hint'] }}</p>
                @endif
            </section>
        @endif

        <p class="home-muted">
            <a href="{{ route('home') }}">{{ $home['text']['meta_title'] ?? __('home.meta_title') }}</a>
            ·
            <a href="{{ route('results.index') }}">{{ $home['text']['footer_results'] ?? __('home.footer_results') }}</a>
        </p>

        <x-home.footer
            :text="$home['text']"
            :appName="$home['hero']['app_name'] ?? config('app.name')"
        />
    </div>
@endsection

```

## `resources/views/home/sales-points.blade.php`

# TYPE: Blade view
# PURPOSE: Translated bounded sales-point search UI.

```php
@extends('layouts.app')

@section('title', $home['text']['footer_sales'] ?? __('home.footer_sales'))

@section('content')
    <div class="home-page">
        <h1 class="home-page__title">{{ $home['text']['sales_title'] ?? __('home.sales_title') }}</h1>
        <p class="home-muted">{{ $home['text']['sales_help'] ?? __('home.sales_help') }}</p>

        @if (!empty($error))
            <p class="home-error" role="alert">{{ $error }}</p>
        @endif

        <form class="home-check-form" method="GET" action="{{ route('sales-points') }}" role="search" aria-label="{{ $home['text']['sales_search_label'] ?? __('home.sales_search_label') }}">
            <div class="home-field">
                <label for="sp-q">{{ $home['text']['sales_name_address_label'] ?? __('home.sales_name_address_label') }}</label>
                <input id="sp-q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" class="home-input" maxlength="120">
            </div>
            <div class="home-field">
                <label for="sp-province">{{ $home['text']['sales_province_label'] ?? __('home.sales_province_label') }}</label>
                <input id="sp-province" name="province" type="text" value="{{ $filters['province'] ?? '' }}" class="home-input" maxlength="80">
            </div>
            <button type="submit" class="home-btn home-btn--primary">{{ $home['text']['sales_search_button'] ?? __('home.sales_search_button') }}</button>
        </form>

        <section class="home-card" aria-labelledby="sp-results-title">
            <div class="home-card__head">
                <h2 id="sp-results-title">{{ $home['text']['sales_results_title'] ?? __('home.sales_results_title') }}</h2>
                @if ($page instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    <span class="home-badge home-badge--VERIFIED">{{ trans('home.sales_found_count', ['count' => $page->total()]) }}</span>
                @endif
            </div>

            @if (! ($page instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator))
                <p class="home-empty">{{ $home['text']['sales_unavailable'] ?? __('home.sales_unavailable') }}</p>
            @elseif ($page->isEmpty())
                <p class="home-empty" role="status">{{ $home['text']['sales_no_match'] ?? __('home.sales_no_match') }}</p>
            @else
                <ul class="home-sales-list" role="list">
                    @foreach ($page as $point)
                        <li class="home-sales-point">
                            <h3>{{ $point['display_name'] ?? ($point['name'] ?? ($home['text']['sales_point_default'] ?? __('home.sales_point_default'))) }}</h3>
                            @if (!empty($point['address']))
                                <p>{{ $point['address'] }}</p>
                            @endif
                            <p class="home-muted">
                                @if (!empty($point['province'])) {{ $point['province'] }} @endif
                                @if (!empty($point['distance_km']))
                                    · {{ number_format((float) $point['distance_km'], 1) }} km
                                @endif
                                @if (!empty($point['status']))
                                    · <span class="home-badge home-badge--neutral">{{ $point['status'] }}</span>
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>

                @if ($page->hasPages())
                    <nav class="home-pagination" aria-label="{{ $home['text']['sales_results_title'] ?? __('home.sales_results_title') }}">
                        @if ($page->onFirstPage())
                            <span aria-disabled="true">{{ $home['text']['sales_previous'] ?? __('home.sales_previous') }}</span>
                        @else
                            <a href="{{ $page->previousPageUrl() }}" rel="prev">{{ $home['text']['sales_previous'] ?? __('home.sales_previous') }}</a>
                        @endif
                        <span>{{ trans('home.sales_page', ['current' => $page->currentPage(), 'last' => $page->lastPage()]) }}</span>
                        @if ($page->hasMorePages())
                            <a href="{{ $page->nextPageUrl() }}" rel="next">{{ $home['text']['sales_next'] ?? __('home.sales_next') }}</a>
                        @else
                            <span aria-disabled="true">{{ $home['text']['sales_next'] ?? __('home.sales_next') }}</span>
                        @endif
                    </nav>
                @endif
            @endif
        </section>

        <x-home.footer
            :text="$home['text']"
            :appName="$home['hero']['app_name'] ?? config('app.name')"
        />
    </div>
@endsection

```

## `resources/views/privacy/index.blade.php`

# TYPE: Blade view
# PURPOSE: Page 80 policy surface with translated navigation, metadata, search, unavailable, and support labels.

```php
@extends('layouts.app')

@php
    $sections = is_array($privacy['sections'] ?? null) ? $privacy['sections'] : [];
    $available = (string) ($privacy['status'] ?? 'UNAVAILABLE') === 'AVAILABLE';
@endphp

@section('title', (string) ($meta['title'] ?? $privacy['meta_title'] ?? trans('public_pages.privacy_meta_title')))
@section('meta_description', (string) ($meta['description'] ?? $privacy['meta_description'] ?? trans('public_pages.privacy_meta_description')))
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/privacy')))
@section('meta_og_title', (string) ($meta['og_title'] ?? $privacy['meta_title'] ?? trans('public_pages.privacy_meta_title')))
@section('meta_og_description', (string) ($meta['og_description'] ?? $privacy['meta_description'] ?? trans('public_pages.privacy_meta_description')))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@section('meta_og_url', (string) ($meta['og_url'] ?? url('/privacy')))

@push('styles')
    @vite('resources/css/pages/privacy.css')
@endpush

@section('content')
<div class="next-public-page next-public-page--privacy" data-next-public-page="privacy" data-pp-page="privacy">
    <a class="pp-skip-link" href="#privacy-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header"><div class="next-shell next-page-header__inner">
        <a class="next-brand" href="{{ route('home') }}" aria-label="{{ trans('public_pages.privacy_home_aria') }}"><span class="next-brand__mark">TL</span><span>THAILOTTO<small>{{ trans('public_pages.privacy_brand_subtitle') }}</small></span></a>
        <nav class="next-nav" aria-label="{{ trans('public_pages.privacy_primary_nav') }}"><a href="{{ route('home') }}">{{ trans('public_pages.privacy_nav_home') }}</a><a href="{{ route('about') }}">{{ trans('public_pages.privacy_nav_about') }}</a><a href="{{ route('vision') }}">{{ trans('public_pages.privacy_nav_vision') }}</a><a class="is-active" href="{{ route('privacy') }}" aria-current="page">{{ trans('public_pages.privacy_nav_privacy') }}</a><a href="{{ route('terms') }}">{{ trans('public_pages.privacy_nav_terms') }}</a><a href="{{ route('contact') }}">{{ trans('public_pages.privacy_nav_contact') }}</a></nav>
        @guest<a class="next-button next-button--gold" href="{{ route('login') }}">{{ trans('public_pages.privacy_login') }}</a>@else<a class="next-button next-button--gold" href="{{ route('player.dashboard') }}">{{ trans('public_pages.privacy_dashboard') }}</a>@endguest
    </div></header>

    <main id="privacy-main" class="next-shell next-content" tabindex="-1">
        <section class="next-hero" aria-labelledby="privacy-title"><div><p class="next-eyebrow">{{ trans('public_pages.privacy_eyebrow') }}</p><h1 id="privacy-title">{{ $privacy['title'] ?? trans('public_pages.privacy_title') }}</h1><p>{{ $privacy['meta_description'] ?? trans('public_pages.privacy_meta_description') }}</p><p class="next-note">{{ trans('public_pages.privacy_public_note') }}</p></div><div class="next-hero-object next-hero-object--privacy" aria-hidden="true"><span>§</span></div></section>
        <section class="next-meta-strip" aria-label="{{ trans('public_pages.privacy_metadata_aria') }}"><div><span>{{ $privacy['version_label'] ?? trans('public_pages.privacy_version_label') }}</span><strong>{{ $privacy['version'] ?? ($privacy['not_configured'] ?? trans('public_pages.privacy_not_configured')) }}</strong></div><div><span>{{ $privacy['effective_label'] ?? trans('public_pages.privacy_effective_label') }}</span><strong>{{ $privacy['effective_at'] ?? ($privacy['not_configured'] ?? trans('public_pages.privacy_not_configured')) }}</strong></div><div><span>{{ $privacy['updated_label'] ?? trans('public_pages.privacy_updated_label') }}</span><strong>{{ $privacy['updated_at'] ?? ($privacy['not_configured'] ?? trans('public_pages.privacy_not_configured')) }}</strong></div><div><span>{{ trans('public_pages.privacy_status_label') }}</span><strong>{{ $available ? trans('public_pages.privacy_available') : trans('public_pages.privacy_unavailable') }}</strong></div></section>

        @if (! $available)
            <section class="next-panel" role="status"><h2>{{ trans('public_pages.privacy_unavailable_title') }}</h2><p>{{ trans('public_pages.privacy_unavailable_body') }}</p></section>
        @else
            <div class="next-layout">
                <aside class="next-sidebar" aria-label="{{ trans('public_pages.privacy_contents_aria') }}"><p class="next-eyebrow">{{ trans('public_pages.privacy_document_map') }}</p>@foreach ($sections as $section)<a href="#{{ $section['id'] ?? 'privacy-'.$loop->iteration }}" data-content-link="{{ $section['id'] ?? 'privacy-'.$loop->iteration }}">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} · {{ $section['title'] ?? trans('public_pages.privacy_section_fallback') }}</a>@endforeach</aside>
                <div>
                    <div class="next-search" role="search"><label for="privacy-search">{{ trans('public_pages.privacy_search_label') }}</label><div class="next-search__row"><input id="privacy-search" type="search" data-local-search placeholder="{{ trans('public_pages.privacy_search_placeholder') }}" autocomplete="off"><button class="next-button" type="button" data-clear-search>{{ trans('public_pages.privacy_clear') }}</button></div><p class="next-search__status" data-search-status aria-live="polite">{{ trans('public_pages.privacy_search_status', ['count' => count($sections)]) }}</p></div>
                    @foreach ($sections as $section)
                        @php $sectionId = (string) ($section['id'] ?? 'privacy-'.$loop->iteration); @endphp
                        <section class="next-section" id="{{ $sectionId }}" data-content-section data-search-item data-search-text="{{ ($section['title'] ?? '').' '.($section['body'] ?? '') }}" aria-labelledby="{{ $sectionId }}-title"><div class="next-section__heading"><span class="next-section__number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><div><p class="next-eyebrow">{{ trans('public_pages.privacy_section_eyebrow') }}</p><h2 id="{{ $sectionId }}-title">{{ $section['title'] ?? trans('public_pages.privacy_section_fallback') }}</h2></div></div><div class="next-section__body"><p>{{ $section['body'] ?? '' }}</p></div></section>
                    @endforeach
                </div>
            </div>
            <section class="next-bottom"><div><p class="next-eyebrow">{{ trans('public_pages.privacy_data_questions') }}</p><h2>{{ trans('public_pages.privacy_support_heading') }}</h2><p>{{ trans('public_pages.privacy_support_body') }}</p></div><div class="next-bottom__links"><button class="next-button next-button--gold" type="button" data-print-page>{{ trans('public_pages.privacy_print_save') }}</button><a class="next-button" href="{{ route('contact') }}">{{ trans('public_pages.privacy_contact_support') }}</a><a class="next-button" href="{{ route('terms') }}">{{ trans('public_pages.privacy_nav_terms') }}</a></div></section>
        @endif
    </main>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/privacy.js')
@endpush

```

## `resources/views/download/index.blade.php`

# TYPE: Blade view
# PURPOSE: Page 82 configured app-destination surface with translated safety, integrity, and unavailable states.

```php
@extends('layouts.app')

@php
    $links = is_array($download['links'] ?? null) ? $download['links'] : [];
    $status = (string) ($links['status'] ?? 'NOT_CONFIGURED');
@endphp

@section('title', (string) ($meta['title'] ?? $download['meta_title'] ?? trans('public_pages.download_meta_title')))
@section('meta_description', (string) ($meta['description'] ?? $download['meta_description'] ?? trans('public_pages.download_meta_description')))
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/download')))
@section('meta_og_title', (string) ($meta['og_title'] ?? $download['meta_title'] ?? trans('public_pages.download_meta_title')))
@section('meta_og_description', (string) ($meta['og_description'] ?? $download['meta_description'] ?? trans('public_pages.download_meta_description')))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@section('meta_og_url', (string) ($meta['og_url'] ?? url('/download')))

@push('styles')
    @vite('resources/css/pages/download.css')
@endpush

@section('content')
<div class="next-public-page next-public-page--download" data-next-public-page="download">
    <a class="pp-skip-link" href="#download-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header"><div class="next-shell next-page-header__inner"><a class="next-brand" href="{{ route('home') }}" aria-label="{{ trans('public_pages.download_home_aria') }}"><span class="next-brand__mark">TL</span><span>THAILOTTO<small>{{ trans('public_pages.download_brand_subtitle') }}</small></span></a><nav class="next-nav" aria-label="{{ trans('public_pages.download_primary_nav') }}"><a href="{{ route('home') }}">{{ trans('public_pages.download_nav_home') }}</a><a class="is-active" href="{{ route('download') }}" aria-current="page">{{ trans('public_pages.download_nav_download') }}</a><a href="{{ route('how-to-play') }}">{{ trans('public_pages.download_nav_how_to_play') }}</a><a href="{{ route('faq') }}">{{ trans('public_pages.download_nav_faq') }}</a><a href="{{ route('contact') }}">{{ trans('public_pages.download_nav_contact') }}</a></nav>@guest<a class="next-button next-button--gold" href="{{ route('login') }}">{{ trans('public_pages.download_login') }}</a>@else<a class="next-button next-button--gold" href="{{ route('player.dashboard') }}">{{ trans('public_pages.download_dashboard') }}</a>@endguest</div></header>
    <main id="download-main" class="next-shell next-content" tabindex="-1">
        <section class="next-hero" aria-labelledby="download-title"><div><p class="next-eyebrow">{{ trans('public_pages.download_eyebrow') }}</p><h1 id="download-title">{{ $download['title'] ?? trans('public_pages.download_title') }}</h1><p>{{ $download['meta_description'] ?? trans('public_pages.download_meta_description') }}</p><p class="next-note">{{ trans('public_pages.download_public_note') }}</p></div><div class="next-hero-object next-hero-object--download" aria-hidden="true"><span>↓</span></div></section>
        <section class="next-meta-strip" aria-label="{{ trans('public_pages.download_metadata_aria') }}"><div><span>{{ trans('public_pages.download_link_status') }}</span><strong>{{ $status }}</strong></div><div><span>{{ trans('public_pages.download_android') }}</span><strong>{{ !empty($links['android']) ? trans('public_pages.download_ready') : trans('public_pages.download_not_configured') }}</strong></div><div><span>{{ trans('public_pages.download_ios') }}</span><strong>{{ !empty($links['ios']) ? trans('public_pages.download_ready') : trans('public_pages.download_not_configured') }}</strong></div><div><span>{{ trans('public_pages.download_pwa') }}</span><strong>{{ !empty($links['pwa']) ? trans('public_pages.download_ready') : trans('public_pages.download_not_configured') }}</strong></div></section>
        <div class="next-layout"><aside class="next-sidebar" aria-label="{{ trans('public_pages.download_destination_map') }}"><p class="next-eyebrow">{{ trans('public_pages.download_destination_map') }}</p><a href="#download-destinations" data-content-link="download-destinations">01 · {{ trans('public_pages.download_destinations') }}</a><a href="#download-safety" data-content-link="download-safety">02 · {{ trans('public_pages.download_safety_check') }}</a><a href="#download-integrity" data-content-link="download-integrity">03 · {{ trans('public_pages.download_integrity_status') }}</a></aside><div>
            <section class="next-section" id="download-destinations" data-content-section aria-labelledby="download-destinations-title"><div class="next-section__heading"><span class="next-section__number">01</span><div><p class="next-eyebrow">{{ trans('public_pages.download_validated_links') }}</p><h2 id="download-destinations-title">{{ trans('public_pages.download_available_destinations') }}</h2></div></div><div class="next-card-grid"><div class="next-card"><h3>{{ trans('public_pages.download_android') }}</h3>@if (!empty($links['android']))<p><a class="next-button next-button--gold" href="{{ $links['android'] }}" rel="noopener noreferrer" target="_blank">{{ trans('public_pages.download_open_store') }}</a></p>@else<p>{{ trans('public_pages.download_not_configured') }}</p>@endif</div><div class="next-card"><h3>{{ trans('public_pages.download_ios') }}</h3>@if (!empty($links['ios']))<p><a class="next-button next-button--gold" href="{{ $links['ios'] }}" rel="noopener noreferrer" target="_blank">{{ trans('public_pages.download_open_store') }}</a></p>@else<p>{{ trans('public_pages.download_not_configured') }}</p>@endif</div><div class="next-card"><h3>{{ trans('public_pages.download_pwa') }}</h3>@if (!empty($links['pwa']))<p><a class="next-button next-button--gold" href="{{ $links['pwa'] }}" rel="noopener noreferrer">{{ trans('public_pages.download_open_web_app') }}</a></p>@else<p>{{ trans('public_pages.download_not_configured') }}</p>@endif</div></div>@if ($status === 'NOT_CONFIGURED')<p class="next-empty" role="status">{{ trans('public_pages.download_empty_body') }}</p>@endif</section>
            <section class="next-section" id="download-safety" data-content-section aria-labelledby="download-safety-title"><div class="next-section__heading"><span class="next-section__number">02</span><div><p class="next-eyebrow">{{ trans('public_pages.download_browser_safety') }}</p><h2 id="download-safety-title">{{ trans('public_pages.download_check_before_install') }}</h2></div></div><div class="next-section__body"><p>{{ trans('public_pages.download_safety_body') }}</p><ul><li>{{ trans('public_pages.download_safety_unknown_domain') }}</li><li>{{ trans('public_pages.download_safety_credentials') }}</li><li>{{ trans('public_pages.download_safety_account_route') }}</li></ul></div></section>
            <section class="next-section" id="download-integrity" data-content-section aria-labelledby="download-integrity-title"><div class="next-section__heading"><span class="next-section__number">03</span><div><p class="next-eyebrow">{{ trans('public_pages.download_integrity_eyebrow') }}</p><h2 id="download-integrity-title">{{ trans('public_pages.download_build_verification') }}</h2></div></div><div class="next-section__body"><p>{{ trans('public_pages.download_integrity_body') }}</p><p class="next-empty">{{ trans('public_pages.download_checksum') }}</p></div></section>
        </div></div>
        <section class="next-bottom"><div><p class="next-eyebrow">{{ trans('public_pages.download_no_link_eyebrow') }}</p><h2>{{ trans('public_pages.download_support_heading') }}</h2><p>{{ trans('public_pages.download_support_body') }}</p></div><div class="next-bottom__links"><button class="next-button next-button--gold" type="button" data-print-page>{{ trans('public_pages.download_print_save') }}</button><a class="next-button" href="{{ route('contact') }}">{{ trans('public_pages.download_contact_support') }}</a></div></section>
    </main>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/download.js')
@endpush

```

## `resources/views/account-grade/index.blade.php`

# TYPE: Blade view
# PURPOSE: Page 83 public grade explainer with translated navigation, configured-tier labels, private-state copy, and no fabricated account state.

```php
@extends('layouts.app')

@php
    $tiers = is_array($grades['tiers'] ?? null) ? $grades['tiers'] : [];
    $status = (string) ($grades['status'] ?? trans('public_pages.not_configured'));
@endphp

@section('title', (string) ($meta['title'] ?? $grades['meta_title'] ?? trans('account_info.grades_meta_title')))
@section('meta_description', (string) ($meta['description'] ?? $grades['meta_description'] ?? trans('account_info.grades_meta_description')))
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/account-grade')))
@section('meta_og_title', (string) ($meta['og_title'] ?? $grades['meta_title'] ?? trans('account_info.grades_meta_title')))
@section('meta_og_description', (string) ($meta['og_description'] ?? $grades['meta_description'] ?? trans('account_info.grades_meta_description')))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@section('meta_og_url', (string) ($meta['og_url'] ?? url('/account-grade')))

@push('styles')
    @vite('resources/css/pages/account-grades.css')
@endpush

@section('content')
<div class="next-public-page next-public-page--grade" data-next-public-page="grades">
    <a class="pp-skip-link" href="#grade-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header"><div class="next-shell next-page-header__inner"><a class="next-brand" href="{{ route('home') }}" aria-label="{{ trans('account_info.grade_home_aria') }}"><span class="next-brand__mark">TL</span><span>THAILOTTO<small>{{ trans('account_info.grade_brand_subtitle') }}</small></span></a><nav class="next-nav" aria-label="{{ trans('account_info.grade_primary_nav') }}"><a href="{{ route('home') }}">{{ trans('account_info.grade_nav_home') }}</a><a href="{{ route('about') }}">{{ trans('account_info.grade_nav_about') }}</a><a href="{{ route('account.verification') }}">{{ trans('account_info.grade_nav_verification') }}</a><a class="is-active" href="{{ route('account.grade') }}" aria-current="page">{{ trans('account_info.grade_nav_grades') }}</a><a href="{{ route('contact') }}">{{ trans('account_info.grade_nav_contact') }}</a></nav>@guest<a class="next-button next-button--gold" href="{{ route('login') }}">{{ trans('account_info.grade_login') }}</a>@else<a class="next-button next-button--gold" href="{{ route('account.grade') }}">{{ trans('account_info.grade_my_grade') }}</a>@endguest</div></header>
    <main id="grade-main" class="next-shell next-content" tabindex="-1">
        <section class="next-hero" aria-labelledby="grade-title"><div><p class="next-eyebrow">{{ trans('account_info.grade_eyebrow') }}</p><h1 id="grade-title">{{ trans('account_info.grades_title') }}</h1><p>{{ $grades['meta_description'] ?? 'Configured grade rules and published benefits.' }}</p><p class="next-note">{{ trans('account_info.grade_public_note') }}</p></div><div class="next-hero-object next-hero-object--grade" aria-hidden="true"><span>◆</span></div></section>
        <section class="next-meta-strip" aria-label="{{ trans('account_info.grade_metadata_aria') }}"><div><span>{{ trans('account_info.grade_rule_version') }}</span><strong>{{ $grades['rule_version'] ?? trans('public_pages.not_configured') }}</strong></div><div><span>{{ trans('account_info.grade_review_period') }}</span><strong>{{ ($grades['period_days'] ?? 0) > 0 ? trans('account_info.grade_days', ['days' => $grades['period_days']]) : trans('public_pages.not_configured') }}</strong></div><div><span>{{ trans('account_info.grade_currency') }}</span><strong>{{ $grades['currency'] ?? trans('public_pages.not_configured') }}</strong></div><div><span>{{ trans('account_info.grade_public_tiers') }}</span><strong>{{ count($tiers) }}</strong></div></section>
        <section class="next-panel" aria-labelledby="grade-summary-title"><p class="next-eyebrow">{{ trans('account_info.grade_ladder_summary') }}</p><h2 id="grade-summary-title">{{ trans('account_info.ladder_heading') }}</h2><div class="next-table-wrap"><table class="next-table"><caption class="sr-only">{{ trans('account_info.ladder_caption') }}</caption><thead><tr><th scope="col">{{ trans('account_info.col_sl') }}</th><th scope="col">{{ trans('account_info.col_grade') }}</th><th scope="col">{{ trans('account_info.col_min_spend') }}</th><th scope="col">{{ trans('account_info.col_discount') }}</th><th scope="col">{{ trans('account_info.col_discount_of_game') }}</th></tr></thead><tbody>@foreach ($tiers as $tier)<tr data-grade-tier="{{ $tier['key'] ?? $loop->iteration }}"><th scope="row">{{ $tier['sl'] ?? trans('public_pages.not_configured') }}</th><td>{{ $tier['name'] ?? trans('public_pages.not_configured') }}</td><td>{{ $tier['min_spend'] ?? trans('public_pages.not_configured') }}</td><td>{{ $tier['discount_display'] ?? trans('public_pages.not_configured') }}</td><td>{{ $tier['eligible_game_count'] ?? trans('public_pages.not_configured') }}</td></tr>@endforeach</tbody></table></div></section>
        <div class="next-layout"><aside class="next-sidebar" aria-label="{{ trans('account_info.grade_ladder_map') }}"><p class="next-eyebrow">{{ trans('account_info.grade_ladder_map') }}</p>@foreach ($tiers as $tier)<a href="#grade-{{ $tier['key'] ?? $loop->iteration }}" data-content-link="grade-{{ $tier['key'] ?? $loop->iteration }}">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} · {{ $tier['name'] ?? trans('account_info.grade_tier_fallback') }}</a>@endforeach<a href="#grade-private" data-content-link="grade-private">{{ trans('account_info.grade_private_account') }}</a></aside><div>
            @forelse ($tiers as $tier)
                @php $tierId = 'grade-'.($tier['key'] ?? $loop->iteration); @endphp
                <section class="next-section" id="{{ $tierId }}" data-content-section data-grade-tier="{{ $tier['key'] ?? $loop->iteration }}" data-search-item data-search-text="{{ ($tier['name'] ?? '').' '.($tier['discount_display'] ?? '').' '.($tier['scope'] ?? '') }}" aria-labelledby="{{ $tierId }}-title"><div class="next-section__heading"><span class="next-section__number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><div><p class="next-eyebrow">{{ trans('account_info.grade_configured_tier') }}</p><h2 id="{{ $tierId }}-title">{{ $tier['name'] ?? trans('account_info.grade_tier_fallback') }}</h2></div></div><div class="next-card-grid"><div class="next-card"><h3>{{ trans('account_info.grade_minimum_spend') }}</h3><p>{{ $tier['min_spend'] ?? trans('public_pages.not_configured') }} {{ $grades['currency'] ?? '' }}</p></div><div class="next-card"><h3>{{ trans('account_info.grade_published_discount') }}</h3><p>{{ $tier['discount_display'] ?? trans('public_pages.not_configured') }}</p></div><div class="next-card"><h3>{{ trans('account_info.grade_scope') }}</h3><p>{{ $tier['scope'] ?? trans('public_pages.not_configured') }}</p></div></div><div class="next-section__body"><p>{{ trans('account_info.grade_eligible_games', ['count' => $tier['eligible_game_count'] ?? 0]) }}</p>@if (!empty($tier['eligible_games']))<ul>@foreach ($tier['eligible_games'] as $game)<li>{{ $game['label'] ?? $game['key'] ?? trans('public_pages.not_configured') }}</li>@endforeach</ul>@endif</div></section>
            @empty
                <section class="next-panel" role="status"><h2>{{ trans('account_info.grade_unavailable_title') }}</h2><p>{{ trans('account_info.grade_unavailable_body') }}</p></section>
            @endforelse
            @php $matrix = is_array($matrix ?? null) ? $matrix : []; $matrixLotteries = is_array($matrix['lotteries'] ?? null) ? $matrix['lotteries'] : []; @endphp
            <section class="next-section" id="grade-game-matrix" data-content-section aria-labelledby="grade-game-matrix-title"><div class="next-section__heading"><span class="next-section__number">Σ</span><div><p class="next-eyebrow">{{ trans('account_info.col_discount_of_game') }}</p><h2 id="grade-game-matrix-title">{{ trans('account_info.col_discount_of_game') }}</h2></div></div><p class="next-section__body">{{ trans('account_info.discount_scope_note') }}</p>@foreach ($tiers as $tier)<button class="next-button" type="button" data-grade-games-toggle aria-expanded="false" aria-controls="grade-games-{{ $tier['key'] ?? $loop->iteration }}">{{ $tier['name'] ?? trans('account_info.grade_tier_fallback') }}</button><div id="grade-games-{{ $tier['key'] ?? $loop->iteration }}" data-grade-game-count="{{ $tier['eligible_game_count'] ?? 0 }}" hidden><p class="next-muted">{{ trans('account_info.col_sl') }} {{ $tier['sl'] ?? trans('public_pages.not_configured') }} · {{ trans('account_info.col_discount') }} {{ $tier['discount_display'] ?? trans('public_pages.not_configured') }}</p><ul>@foreach ((array) ($tier['eligible_games'] ?? []) as $game)<li>{{ $game['label'] ?? $game['key'] ?? trans('public_pages.not_configured') }}</li>@endforeach</ul></div>@endforeach@if ($matrixLotteries !== [])<div class="next-table-wrap"><table class="next-table"><thead><tr><th>{{ trans('account_info.col_grade') }}</th><th>{{ trans('account_info.col_discount') }}</th><th>{{ trans('account_info.col_discount_of_game') }}</th></tr></thead><tbody>@foreach ($matrixLotteries as $lottery)<tr data-grade-games-lottery="{{ $lottery['key'] ?? '' }}"><td>{{ $lottery['label'] ?? trans('public_pages.not_configured') }}</td><td>{{ $lottery['affiliate_commission_percent'] ?? trans('public_pages.not_configured') }}</td><td>{{ count((array) ($lottery['games'] ?? [])) }}</td></tr>@endforeach</tbody></table></div>@else<p class="next-empty">{{ trans('public_pages.not_configured') }}</p>@endif</section>
            <section class="next-section" id="grade-private" data-content-section aria-labelledby="grade-private-title"><div class="next-section__heading"><span class="next-section__number">{{ trans('account_info.grade_private_marker') }}</span><div><p class="next-eyebrow">{{ trans('account_info.grade_private_eyebrow') }}</p><h2 id="grade-private-title">{{ trans('account_info.grade_private_title') }}</h2></div></div><div class="next-section__body"><p>{{ trans('account_info.grade_private_body') }}</p><p class="next-muted">{{ trans('account_info.grade_private_note') }}</p></div></section>
        </div></div>
        <section class="next-bottom"><div><p class="next-eyebrow">{{ trans('account_info.grade_check_account') }}</p><h2>{{ trans('account_info.grade_open_authenticated') }}</h2><p>{{ trans('account_info.grade_authenticated_body') }}</p></div><div class="next-bottom__links">@guest<a class="next-button next-button--gold" href="{{ route('login') }}">{{ trans('account_info.grade_sign_in') }}</a>@else<a class="next-button next-button--gold" href="{{ route('account.grade') }}">{{ trans('account_info.grade_open_my_grade') }}</a>@endguest<a class="next-button" href="{{ route('contact') }}">{{ trans('account_info.grade_contact_support') }}</a></div></section>
    </main>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/account-grades.js')
@endpush

```

## `lang/en/results.php`

# TYPE: PHP translation map
# PURPOSE: English results keys.

```php
<?php

return [
    'meta_title' => 'Public Results Hub',
    'meta_description' => 'Published lottery results from the application public results projection.',
    'title' => 'Public Results Hub',
    'lead' => 'Review published results supplied by the verified public results projection.',
    'published_results' => 'Published results',
    'no_public_data' => 'No public result data is available.',
    'unavailable' => 'Result data is temporarily unavailable.',
    'draw' => 'Draw',
    'date' => 'Date',
    'first_prize' => 'First prize',
    'second_prize' => 'Second prize',
    'third_prize' => 'Third prize',
    'other_prizes' => 'Other published prizes',
    'source' => 'Source state',
    'official_source_verified' => 'Official source verified',
    'internal_reconciled' => 'Internal reconciled',
    'fixture_only' => 'Fixture only',
    'unavailable_source' => 'Unavailable',
    'not_configured' => 'Not configured',
    'check_link' => 'Check a six-digit ticket',
    'search_link' => 'Search published results',
    'not_published' => 'Not published',
    'no_prize_data' => 'No prize data published',
];

```

## `lang/th/results.php`

# TYPE: PHP translation map
# PURPOSE: Thai-locale results key parity.

```php
<?php

return [
    'meta_title' => 'Public Results Hub',
    'meta_description' => 'Published lottery results from the application public results projection.',
    'title' => 'Public Results Hub',
    'lead' => 'Review published results supplied by the verified public results projection.',
    'published_results' => 'Published results',
    'no_public_data' => 'No public result data is available.',
    'unavailable' => 'Result data is temporarily unavailable.',
    'draw' => 'Draw',
    'date' => 'Date',
    'first_prize' => 'First prize',
    'second_prize' => 'Second prize',
    'third_prize' => 'Third prize',
    'other_prizes' => 'Other published prizes',
    'source' => 'Source state',
    'official_source_verified' => 'Official source verified',
    'internal_reconciled' => 'Internal reconciled',
    'fixture_only' => 'Fixture only',
    'unavailable_source' => 'Unavailable',
    'not_configured' => 'Not configured',
    'check_link' => 'Check a six-digit ticket',
    'search_link' => 'Search published results',
    'not_published' => 'Not published',
    'no_prize_data' => 'No prize data published',
];

```

## `lang/en/admin.php`

# TYPE: PHP translation map
# PURPOSE: English admin keys, error states, and KYC action copy.

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
];

```

## `lang/th/admin.php`

# TYPE: PHP translation map
# PURPOSE: Thai-locale admin key parity.

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
    'analytics_range_invalid' => 'The analytics date range must be between zero and 31 days.'
];

```

## `lang/en/home.php`

# TYPE: PHP translation map
# PURPOSE: English Page 78–79 labels and placeholders.

```php
<?php

return [
    'meta_title' => 'ThaiLotto Club - Premier 3D Luxury Thai Lottery & iGaming Terminal',
    'meta_description' => 'Play Smart. Win Big. Thailand\'s official licensed online lottery platform with 150,000,000 THB prize pools, 900x payouts, and instant automated PromptPay settlements.',
    'hero_title' => 'Government Lottery Results & Wagering Terminal',
    'hero_lead' => 'Experience next-generation lottery entertainment with real-time GLO L6 live broadcast integration, 88 daily speed rounds, regional 4D markets, instant automated payouts, and bank-grade cryptographic security.',
    'cta_results' => 'Latest Results',
    'cta_check' => 'Check Your Ticket',
    'cta_sales' => 'Find a Sales Point',
    'cta_sign_in' => 'Login',
    'cta_register' => 'Register',
    'lane_results_title' => 'Latest Official Lottery Results',
    'lane_results_none' => 'No published result yet.',
    'lane_results_field_none' => 'Not published',
    'current_result_title' => 'Current Verified Result',
    'current_result_none' => 'No verified result available',
    'next_draw_title' => 'Next Scheduled Draw',
    'next_draw_none' => 'No draw scheduled yet',
    'live_title' => 'Live Broadcast Feed',
    'check_title' => 'Quick Ticket Verifier',
    'check_label' => '6-digit ticket number',
    'check_placeholder' => 'e.g. 012345',
    'check_button' => 'Check Result',
    'check_help' => 'Enter exactly six digits. Leading zeros are preserved.',
    'sales_title' => 'Official Sales Points',
    'sales_help' => 'Find published GLO sales points near you.',
    'sales_search_label' => 'Sales point search',
    'sales_name_address_label' => 'Name or address',
    'sales_province_label' => 'Province',
    'sales_search_button' => 'Search',
    'sales_results_title' => 'Results',
    'sales_found_count' => ':count found',
    'sales_unavailable' => 'Sales point list unavailable.',
    'sales_no_match' => 'No sales points match this search.',
    'sales_point_default' => 'Sales point',
    'sales_previous' => 'Previous',
    'sales_next' => 'Next',
    'sales_page' => 'Page :current of :last',
    'check_result_title' => 'Check result',
    'check_ticket_label' => 'Ticket',
    'check_draw_label' => 'Draw',
    'check_matched_label' => 'Matched — total prize',
    'check_no_match' => 'No matching prize category.',
    'products_title' => 'GLO Lottery Products',
    'prize_title' => 'Prize Pool Highlight',
    'prize_unavailable' => 'Prize summary unavailable',
    'stats_title' => 'Platform Statistics',
    'stats_unavailable' => 'Statistics unavailable',
    'trust_title' => 'Institutional Trust & Security',
    'bonuses_title' => 'Bonuses & VIP Campaigns',
    'bonuses_empty' => 'No active promotions at this time',
    'payments_title' => 'Payment Methods & Settlement',
    'payments_empty' => 'No payment methods are publicly available yet',
    'support_title' => 'VIP Customer Support',
    'support_help' => 'Our 24/7 dedicated support team is here to assist you.',
    'app_title' => 'Mobile Applications',
    'source_label' => 'Source',
    'draw_label' => 'Draw',
    'draw_status_label' => 'Draw Status',
    'prize_1st_label' => '1st Prize (6 Digits)',
    'prize_2nd_label' => '2nd Prize',
    'prize_last2_label' => 'Last 2 Digits',
    'time_remaining_label' => 'Time Remaining',
    'timezone_label' => 'Timezone',
    'email_label' => 'Email',
    'phone_label' => 'Phone',
    'hours_label' => 'Operating Hours',
    'support_contact' => 'Contact Support',
    'generated_label' => 'Generated At',
    'stats_database' => 'DATABASE',
    'live_not_configured' => 'LIVE DRAW NOT CONFIGURED',
    'replay_not_configured' => 'REPLAY NOT CONFIGURED',
    'footer_results' => 'Results Hub',
    'footer_checker' => 'Ticket Checker',
    'footer_sales' => 'Sales Points',
    'footer_terms' => 'Terms of Service',
    'footer_fees' => 'Fee Schedule',
    'footer_contact' => 'Contact Us',
    'footer_privacy' => 'Privacy Policy',

    // Detailed structured keys for Hero, Countdown, etc.
    'hero' => [
        'badge' => 'Official GLO Thailand & Asia Licensed Platform',
        'title_prefix' => 'The Premier',
        'title_highlight' => '3D Luxury Thai',
        'title_suffix' => 'Lottery & iGaming Terminal',
        'description' => 'Experience next-generation lottery entertainment with real-time GLO L6 live broadcast integration, 88 daily speed rounds, regional 4D markets, instant automated payouts, and bank-grade cryptographic security.',
        'btn_play_now' => 'Play Thai Lottery Now',
        'btn_check_results' => 'Check Live Results',
        'btn_download_app' => 'Download App',
        'stats' => [
            'active_players' => 'Active Players',
            'daily_payout' => 'Daily Payouts',
            'uptime' => 'GLO Sync Uptime',
            'support' => 'VIP Support',
        ],
    ],
    'countdown' => [
        'title' => 'Next Official Thai GLO Draw',
        'subtitle' => 'Draw closes 30 minutes prior to official televised broadcast',
        'days' => 'Days',
        'hours' => 'Hours',
        'minutes' => 'Minutes',
        'seconds' => 'Seconds',
        'status_open' => 'BETTING OPEN',
        'status_closing_soon' => 'CLOSING SOON',
        'status_closed' => 'BETTING CLOSED',
        'btn_bet_now' => 'Place Your Numbers',
    ],
    'quick_check' => [
        'title' => 'Quick Ticket Verifier',
        'subtitle' => 'Instant cryptographic matching against verified GLO prize feeds',
        'placeholder' => 'Enter 2, 3, or 6-digit number...',
        'btn_verify' => 'Verify Prize',
        'recent_draw' => 'Latest Draw',
    ],
    'latest_results' => [
        'title' => 'Official Latest Draw Results',
        'subtitle' => 'Live verified broadcast records with complete prize breakdown',
        'first_prize' => '1st Prize (6 Digits)',
        'first_3_digits' => 'First 3 Digits',
        'last_3_digits' => 'Last 3 Digits',
        'last_2_digits' => 'Last 2 Digits',
        'draw_date' => 'Draw Date',
        'draw_number' => 'Draw #',
        'btn_all_results' => 'View Historical Archives',
    ],
    'games' => [
        'title' => 'Explore Premium Lottery Markets',
        'subtitle' => 'Government national draws, rapid 15-minute speed rounds, and regional multi-state 4D markets',
        'tab_all' => 'All Markets',
        'tab_national' => 'Thai GLO L6',
        'tab_speed' => '88 Daily Rounds',
        'tab_regional' => 'Lao & Hanoi 4D',
        'tab_pcso' => 'PCSO & 6D',
        'btn_play' => 'Play Now',
        'max_prize' => 'Top Prize',
        'next_draw' => 'Next Draw',
    ],
    'trust' => [
        'title' => 'Institutional Trust & Unrivaled Security',
        'subtitle' => 'Built on bank-grade encryption, provably fair mechanics, and 24/7 automated settlement rails',
        'feature_1_title' => 'GLO Live Provable Provenance',
        'feature_1_desc' => 'Direct synchronized feed integration with the Government Lottery Office Thailand with immutable hash verification.',
        'feature_2_title' => 'Instant Payout Guarantee',
        'feature_2_desc' => 'Automated PromptPay, Thai QR, and bank wire settlement within seconds with zero withdrawal commission.',
        'feature_3_title' => 'ISO 27001 Certified Security',
        'feature_3_desc' => 'End-to-end 256-bit SSL encryption, automated DDoS mitigation, and continuous penetration audits.',
        'feature_4_title' => '24/7 VIP Concierge',
        'feature_4_desc' => 'Dedicated live support specialists available around the clock via Live Chat, Telegram, and LINE Official.',
    ],
    'app' => [
        'title' => 'Experience ThaiLotto Anywhere, Anytime',
        'subtitle' => 'Download our native iOS & Android applications for real-time draw notifications, instant biometric keypad betting, and seamless cashout.',
        'badge' => 'Mobile First Gaming',
        'ios_btn' => 'Download for iOS',
        'android_btn' => 'Download for Android',
        'pwa_btn' => 'Instant Web App',
    ],
    'payments' => [
        'title' => 'Seamless & Instant Payment Rails',
        'subtitle' => 'Instant zero-fee deposits and withdrawals supported by all major Thai and international banks',
    ],
];

```

## `lang/th/home.php`

# TYPE: PHP translation map
# PURPOSE: Thai-locale Page 78–79 key and placeholder parity.

```php
<?php

return [
    'meta_title' => 'ThaiLotto Club - แพลตฟอร์มลอตเตอรี่ออนไลน์ระดับพรีเมียม มาตรฐานสากล',
    'meta_description' => 'แทงหวยออนไลน์ระดับพรีเมียม สลากกินแบ่งรัฐบาลไทย ยี่กี 88 รอบ หวยลาว หวยฮานอย จ่ายสูงสุด บาทละ 900 ฝากถอนออโต้รวดเร็ว ปลอดภัย 100%',
    'hero_title' => 'ระบบแทงหวยและตรวจผลสลากกินแบ่งรัฐบาล',
    'hero_lead' => 'สัมผัสประสบการณ์การแทงหวยระดับพรีเมียม ถ่ายทอดสดผลสลากกินแบ่งรัฐบาลไทย หวยยี่กี 88 รอบต่อวัน หวยลาว หวยฮานอย จ่ายจริง จ่ายไว การเงินมั่นคง 100%',
    'cta_results' => 'ผลสลากล่าสุด',
    'cta_check' => 'ตรวจสลากกินแบ่ง',
    'cta_sales' => 'จุดจำหน่ายสลาก',
    'cta_sign_in' => 'เข้าสู่ระบบ',
    'cta_register' => 'สมัครสมาชิก',
    'lane_results_title' => 'ผลการออกรางวัลหวยยอดนิยม',
    'lane_results_none' => 'ยังไม่มีผลรางวัลที่เผยแพร่',
    'lane_results_field_none' => 'ยังไม่ออกผล',
    'current_result_title' => 'ผลสลากกินแบ่งรัฐบาลล่าสุด',
    'current_result_none' => 'ไม่มีผลรางวัลที่ตรวจสอบแล้ว',
    'next_draw_title' => 'นับถอยหลังงวดถัดไป',
    'next_draw_none' => 'ยังไม่มีกำหนดการออกรางวัล',
    'live_title' => 'ถ่ายทอดสดผลสลากกินแบ่ง',
    'check_title' => 'ตรวจผลรางวัลด่วน',
    'check_label' => 'กรอกเลขสลาก 6 หลัก',
    'check_placeholder' => 'เช่น 012345',
    'check_button' => 'ตรวจผลรางวัล',
    'check_help' => 'กรอกตัวเลข 6 หลักให้ครบถ้วน (ระบบเก็บเลข 0 นำหน้าอย่างถูกต้อง)',
    'sales_title' => 'Official Sales Points',
    'sales_help' => 'Find published GLO sales points near you.',
    'sales_search_label' => 'Sales point search',
    'sales_name_address_label' => 'Name or address',
    'sales_province_label' => 'Province',
    'sales_search_button' => 'Search',
    'sales_results_title' => 'Results',
    'sales_found_count' => ':count found',
    'sales_unavailable' => 'Sales point list unavailable.',
    'sales_no_match' => 'No sales points match this search.',
    'sales_point_default' => 'Sales point',
    'sales_previous' => 'Previous',
    'sales_next' => 'Next',
    'sales_page' => 'Page :current of :last',
    'check_result_title' => 'Check result',
    'check_ticket_label' => 'Ticket',
    'check_draw_label' => 'Draw',
    'check_matched_label' => 'Matched — total prize',
    'check_no_match' => 'No matching prize category.',
    'products_title' => 'ผลิตภัณฑ์สลากกินแบ่ง',
    'prize_title' => 'รางวัลไฮไลท์',
    'prize_unavailable' => 'ยังไม่มีข้อมูลสรุปรางวัล',
    'stats_title' => 'สถิติการใช้งานแพลตฟอร์ม',
    'stats_unavailable' => 'ยังไม่มีข้อมูลสถิติ',
    'trust_title' => 'ความปลอดภัยและความน่าเชื่อถือ',
    'bonuses_title' => 'โปรโมชั่นและสิทธิพิเศษ VIP',
    'bonuses_empty' => 'ไม่มีโปรโมชั่นที่เปิดใช้งานในขณะนี้',
    'payments_title' => 'ช่องทางการฝาก-ถอนเงิน',
    'payments_empty' => 'ยังไม่มีช่องทางการชำระเงินที่เผยแพร่',
    'support_title' => 'ศูนย์บริการลูกค้า VIP',
    'support_help' => 'ทีมงานพร้อมดูแลคุณตลอด 24 ชั่วโมง ทุกวัน',
    'app_title' => 'แอปพลิเคชันบนมือถือ',
    'source_label' => 'แหล่งที่มา',
    'draw_label' => 'งวดที่',
    'draw_status_label' => 'สถานะงวด',
    'prize_1st_label' => 'รางวัลที่ 1 (6 หลัก)',
    'prize_2nd_label' => 'รางวัลที่ 2',
    'prize_last2_label' => 'เลขท้าย 2 ตัว',
    'time_remaining_label' => 'เวลาที่เหลือ',
    'timezone_label' => 'เขตเวลา',
    'email_label' => 'อีเมล',
    'phone_label' => 'โทรศัพท์',
    'hours_label' => 'เวลาทำการ',
    'support_contact' => 'ติดต่อเจ้าหน้าที่',
    'generated_label' => 'สร้างเมื่อ',
    'stats_database' => 'ฐานข้อมูลสด',
    'live_not_configured' => 'ยังไม่ได้ตั้งค่าการถ่ายทอดสด',
    'replay_not_configured' => 'ยังไม่มีวิดีโอย้อนหลัง',
    'footer_results' => 'ศูนย์ผลรางวัล',
    'footer_checker' => 'ตรวจผลสลาก',
    'footer_sales' => 'จุดจำหน่าย',
    'footer_terms' => 'ข้อกำหนดการใช้งาน',
    'footer_fees' => 'อัตราค่าธรรมเนียม',
    'footer_contact' => 'ติดต่อเรา',
    'footer_privacy' => 'นโยบายความเป็นส่วนตัว',

    'hero' => [
        'badge' => 'แพลตฟอร์มลอตเตอรี่ออนไลน์ระดับพรีเมียม มาตรฐานสากล',
        'title_prefix' => 'สุดยอดแพลตฟอร์ม',
        'title_highlight' => 'สลากกินแบ่งรัฐบาล 3D',
        'title_suffix' => 'และหวยออนไลน์ชั้นนำ',
        'description' => 'สัมผัสประสบการณ์การแทงหวยระดับพรีเมียม ถ่ายทอดสดผลสลากกินแบ่งรัฐบาลไทย หวยยี่กี 88 รอบต่อวัน หวยลาว หวยฮานอย จ่ายจริง จ่ายไว การเงินมั่นคง 100%',
        'btn_play_now' => 'แทงหวยรัฐบาลทันที',
        'btn_check_results' => 'ตรวจผลรางวัลสด',
        'btn_download_app' => 'ดาวน์โหลดแอปพลิเคชัน',
        'stats' => [
            'active_players' => 'ผู้ใช้งานจริง',
            'daily_payout' => 'ยอดจ่ายต่อวัน',
            'uptime' => 'ความเสถียรของระบบ',
            'support' => 'บริการดูแลตลอด 24 ชม.',
        ],
    ],
    'countdown' => [
        'title' => 'นับถอยหลังงวดสลากกินแบ่งรัฐบาลไทย',
        'subtitle' => 'ปิดรับแทงก่อนเวลาออกรางวัล 30 นาที',
        'days' => 'วัน',
        'hours' => 'ชั่วโมง',
        'minutes' => 'นาที',
        'seconds' => 'วินาที',
        'status_open' => 'เปิดรับแทง',
        'status_closing_soon' => 'ใกล้ปิดรับแทง',
        'status_closed' => 'ปิดรับแทงแล้ว',
        'btn_bet_now' => 'เลือกตัวเลขนำโชค',
    ],
    'quick_check' => [
        'title' => 'ตรวจผลรางวัลด่วน',
        'subtitle' => 'ตรวจสอบความถูกต้องของสลากด้วยระบบ Real-time',
        'placeholder' => 'กรอกเลข 2, 3 หรือ 6 หลัก...',
        'btn_verify' => 'ตรวจรางวัล',
        'recent_draw' => 'งวดล่าสุด',
    ],
    'latest_results' => [
        'title' => 'ผลการออกรางวัลล่าสุด',
        'subtitle' => 'ข้อมูลผลรางวัลอย่างเป็นทางการ ตรวจสอบได้ทันที',
        'first_prize' => 'รางวัลที่ 1 (6 หลัก)',
        'first_3_digits' => 'เลขหน้า 3 ตัว',
        'last_3_digits' => 'เลขท้าย 3 ตัว',
        'last_2_digits' => 'เลขท้าย 2 ตัว',
        'draw_date' => 'งวดวันที่',
        'draw_number' => 'งวดที่',
        'btn_all_results' => 'ดูผลย้อนหลังทั้งหมด',
    ],
    'games' => [
        'title' => 'ประเภทหวยยอดนิยม',
        'subtitle' => 'สลากกินแบ่งรัฐบาลไทย หวยยี่กี 88 รอบ หวยลาวพรีเมียม และหวยฮานอยพิเศษ',
        'tab_all' => 'หวยทั้งหมด',
        'tab_national' => 'หวยรัฐบาลไทย',
        'tab_speed' => 'หวยยี่กี 88 รอบ',
        'tab_regional' => 'หวยลาว & ฮานอย',
        'tab_pcso' => 'หวย PCSO & 6D',
        'btn_play' => 'เข้าแทงหวย',
        'max_prize' => 'รางวัลสูงสุด',
        'next_draw' => 'งวดถัดไป',
    ],
    'trust' => [
        'title' => 'มั่นคง ปลอดภัย มาตรฐานสถาบันการเงิน',
        'subtitle' => 'ระบบความปลอดภัยระดับสูง การันตีการจ่ายเงินรวดเร็วแม่นยำ',
        'feature_1_title' => 'ผลสลากตรงจากกองสลาก',
        'feature_1_desc' => 'เชื่อมต่อผลรางวัลจากสำนักงานสลากกินแบ่งรัฐบาลโดยตรง มั่นใจได้ 100%',
        'feature_2_title' => 'ฝาก-ถอน อัตโนมัติ รวดเร็ว',
        'feature_2_desc' => 'รองรับพร้อมเพย์และธนาคารชั้นนำทุกแห่ง ไม่มีค่าธรรมเนียม เงินเข้าทันที',
        'feature_3_title' => 'ความปลอดภัยระดับสากล',
        'feature_3_desc' => 'เข้ารหัสข้อมูลมาตรฐาน 256-bit SSL ปกป้องความเป็นส่วนตัวสูงสุด',
        'feature_4_title' => 'ทีมงานบริการ 24 ชั่วโมง',
        'feature_4_desc' => 'เจ้าหน้าที่ระดับ VIP พร้อมดูแลและตอบคำถามทุกข้อสงสัยตลอดเวลา',
    ],
    'app' => [
        'title' => 'สัมผัสประสบการณ์ ThaiLotto ได้ทุกที่ ทุกเวลา',
        'subtitle' => 'ดาวน์โหลดแอปพลิเคชันสำหรับ iOS และ Android เพื่อรับการแจ้งเตือนผลและแทงหวยได้สะดวกรวดเร็วยิ่งขึ้น',
        'badge' => 'รองรับมือถือเต็มรูปแบบ',
        'ios_btn' => 'ดาวน์โหลดสำหรับ iOS',
        'android_btn' => 'ดาวน์โหลดสำหรับ Android',
        'pwa_btn' => 'ใช้งานผ่านเว็บแอป',
    ],
    'payments' => [
        'title' => 'ระบบการชำระเงินที่สะดวกสบาย',
        'subtitle' => 'รองรับทุกธนาคารชั้นนำในประเทศไทยและพร้อมเพย์ ฝากถอนไม่มีขั้นต่ำ',
    ],
];

```

## `lang/en/public_pages.php`

# TYPE: PHP translation map
# PURPOSE: English Page 80 and Page 82 visible interface labels.

```php
<?php

/*
|--------------------------------------------------------------------------
| Public pages copy — English (/about, /vision, /terms)
|--------------------------------------------------------------------------
| Neutral wording only. Never present the platform as the Government
| Lottery Office, an official agent, or a government portal. Official GLO
| prize facts come from config/glo.php via TermsPageService — not hard-coded
| here as business rules. Keep keys identical to lang/th/public_pages.php.
|
*/

return [

    // ------------------------------------------------------------------ meta
    'about_meta_title' => 'About',
    'about_meta_description' => 'How this GLO-compatible lottery platform works: choose a draw, buy securely, follow verified results, and claim under clear rules.',
    'vision_meta_title' => 'Vision & Mission',
    'vision_meta_description' => 'Our platform vision, mission, core values, and the governance controls we actually implement for lottery results, settlement, and player protection.',
    'terms_meta_title' => 'Terms of Use',
    'terms_meta_description' => 'Versioned terms of use covering products, prizes, stamp duty, accounts, age rules, claims, responsible gaming, and legal disclaimers.',
    'og_type' => 'website',

    // ------------------------------------------------------------------ about
    'about_title' => 'About this platform',
    'about_lead' => 'An independent, GLO-compatible lottery information and wagering platform. We are not the Government Lottery Office, not an official GLO agent, and not a government portal.',

    'how_it_works_title' => 'How it works',
    'how_it_works_choose_label' => 'Choose',
    'how_it_works_choose_text' => 'Browse published draws and results, then choose a product: GLO-style L6 or N3 on this platform, or operator markets such as 3D, TOD, 2D, and Run.',
    'how_it_works_buy_label' => 'Buy',
    'how_it_works_buy_text' => 'Create an account or sign in, fund your wallet through configured payment methods, and place your purchase. Ticket numbers keep leading zeros.',
    'how_it_works_result_label' => 'Result',
    'how_it_works_result_text' => 'Follow published draw results with explicit source-status labels. Fixture or sample data is never presented as an official government announcement.',
    'how_it_works_claim_label' => 'Claim',
    'how_it_works_claim_text' => 'If a ticket wins, claim follows verification, eligibility checks, any applicable holds, KYC gates, stamp duty calculation, and the approved payout path — never an automatic promise of instant payment.',

    'history_title' => 'Our approach',
    'history_text' => 'This platform exists to make lottery results, ticket checking, and account tooling clear and auditable. We publish results only with honest source labels, calculate prizes from configured GLO-compatible rules, and keep an append-only trail for money movements. We do not claim a founding date, government affiliation, or certification that is not published here.',

    'useful_links_title' => 'Useful links',
    'useful_links_text' => 'Public pages on this site — no account required for results, ticket checks, or sales-point search.',

    'contact_title' => 'Contact',
    'contact_text' => 'Questions about the platform, your account, or these pages? Use the contact page when support details are configured.',

    'about_hero_eyebrow' => '02 · ABOUT US',
    'about_hero_title' => 'A clearer way to understand the platform',
    'about_hero_visual_label' => 'PUBLIC INFORMATION ARCHIVE',
    'about_story_eyebrow' => 'THE STORY BEHIND THE NUMBERS',
    'about_story_source' => 'Historical reference adapted from an observed public About page. It describes Thailand lottery history, not the identity or legal status of this platform.',
    'about_timeline_eyebrow' => 'HISTORICAL REFERENCE',
    'about_timeline_title' => 'Lottery history in Thailand',
    'about_timeline_text' => 'A concise, source-labelled timeline of milestones described by the observed public reference. Historical context is kept separate from this platform’s independent identity.',
    'about_timeline_source_label' => 'SOURCE-LABELLED HISTORY',
    'about_timeline_empty' => 'Historical milestones are temporarily unavailable. The rest of the About page remains available.',
    'about_values_eyebrow' => 'HOW WE WORK',
    'about_values_title' => 'Principles reflected in the application',
    'about_values_text' => 'These principles describe capabilities and controls visible in the application. They are not certifications, government endorsements, or a promise of a particular outcome.',
    'about_trust_eyebrow' => 'TRUST, WITHOUT OVERCLAIMING',
    'about_trust_title' => 'What the platform can show clearly',
    'about_trust_text' => 'Public result pages use source-status labels, financial actions use server-side authorization, and public content avoids private records and unsupported institutional claims.',
    'about_cta_title' => 'Continue with the information you need',
    'about_cta_text' => 'Read the rules, check published results, verify a ticket, or contact support through the application’s real public routes.',
    'about_cta_results' => 'View results',
    'about_cta_check' => 'Check a ticket',
    'about_cta_contact' => 'Contact support',
    'about_source_note' => 'This page is informational. Lottery history is presented as historical reference and does not imply that this platform is a government body, government portal, or official agent.',

    'about_timeline.1874.title' => 'First recorded lottery reference',
    'about_timeline.1874.description' => 'The observed reference describes a lottery issued in 1874 during the reign of King Chulalongkorn, connected with a royal birthday celebration and an exhibition.',
    'about_timeline.1917.title' => 'A wartime fundraising lottery',
    'about_timeline.1917.description' => 'The reference describes a 1917 lottery fundraising context during the First World War, with royal permission recorded in that historical account.',
    'about_timeline.1923.title' => 'The “Tiger Scout Million Baht Lottery”',
    'about_timeline.1923.description' => 'The source describes a 1923 lottery printed in one million copies at one baht each to raise funds for the Tiger Scout volunteer organisation.',
    'about_timeline.1933.title' => 'The Siamese Government Lottery',
    'about_timeline.1933.description' => 'The source describes a 1933 government lottery policy context following changes to the former head-tax system, with four draws per year in the cited account.',
    'about_timeline.1935.title' => 'Municipal support lottery activity',
    'about_timeline.1935.description' => 'The historical reference records a municipal-support lottery first sold in November 1935 and continued alongside government lottery activity.',
    'about_timeline.1939.title' => 'Formal government lottery administration',
    'about_timeline.1939.description' => 'The source records a 1939 administrative transfer to the Ministry of Finance and identifies 5 April 1939 as the founding date of the Government Lottery Office.',
    'about_timeline.1974.title' => 'Statutory public-enterprise status',
    'about_timeline.1974.description' => 'The source records the 1974 Government Lottery Office Act, describing the office as a juristic person and state enterprise under the Ministry of Finance.',
    'about_timeline.2000.title' => 'International lottery associations',
    'about_timeline.2000.description' => 'The observed reference says the Government Lottery Office joined the World Lottery Association and the Asia Pacific Lottery Association in 2000.',

    // ------------------------------------------------------------------ vision
    'vision_title' => 'Vision & Mission',
    'vision_lead' => 'A trustworthy, GLO-compatible lottery platform where every result, prize, and payout is verifiable.',

    'vision_section_title' => 'Vision',
    'vision_section_text' => 'To be the most transparent GLO-compatible lottery platform: players can verify every published result, understand every prize calculation, and follow every payout decision with clear source labels and audit evidence.',

    'mission_section_title' => 'Mission',
    'mission_section_text' => 'Operate lottery information and wagering features with verified result publication, configured prize settlement, sales reconciliation, immutable audit trails, KYC-gated withdrawals, and privacy-respecting public pages.',

    'core_values_title' => 'Core values',
    'core_values_transparency_label' => 'Transparency',
    'core_values_transparency_text' => 'Results carry explicit source-status labels; prize rules come from published configuration, not hidden tables.',
    'core_values_security_label' => 'Security',
    'core_values_security_text' => 'Server-side authorization, signed webhooks, and transactional ledger writes guard every financial action.',
    'core_values_accountability_label' => 'Accountability',
    'core_values_accountability_text' => 'Append-only audit records make money movements and privileged actions reviewable after the fact.',
    'core_values_fairness_label' => 'Fairness',
    'core_values_fairness_text' => 'Prize engines follow configured rules; N3-style pools are draw-calculated, never fixed historical payouts.',
    'core_values_privacy_label' => 'Privacy',
    'core_values_privacy_text' => 'Public pages stay anonymous; personal data never appears on shared result or sales-point views.',
    'core_values_reliability_label' => 'Reliability',
    'core_values_reliability_text' => 'Idempotent operations and concurrency locks keep double-pays and race conditions out of the system.',

    'governance_title' => 'Governance & controls',
    'governance_text' => 'Controls we actually implement in this platform:',
    'governance_idempotency' => 'Idempotency keys on wallet and payout operations to prevent duplicate execution.',
    'governance_transactional_ledger' => 'Transactional ledger writes with row locks under database transactions.',
    'governance_immutable_audit' => 'Append-only audit trail for financial and privileged actions.',
    'governance_source_status_labels' => 'Source-status labels on results (verified, reconciled, fixture, not configured, unavailable).',
    'governance_server_authorization' => 'Server-side authorization on every financial action — never trust client-supplied roles or amounts.',
    'governance_kyc_gates' => 'KYC gates on withdrawals above the configured threshold (WithdrawalKycGateService).',
    'governance_payout_holds' => 'Payout holds and freeze history for claim eligibility — frozen winning tickets are never auto-paid.',
    'governance_sales_reconciliation' => 'Sales reconciliation for ticket sales seats and draw settlement.',
    'governance_disclaimer' => 'These are engineering controls, not a promise of absolute safety or invulnerability, and not a guarantee of zero operational risk.',

    'vision_hero_eyebrow' => '03 · VISION & MISSION',
    'vision_hero_title' => 'Build trust into every step forward',
    'vision_hero_text' => 'A strategic view of the platform: clear information, accountable operations, responsible participation, and technology that makes important decisions easier to understand.',
    'vision_hero_visual_label' => 'FUTURE PLATFORM ORBIT',
    'vision_hero_disclaimer' => 'This is an independent platform vision. It is not a government identity, certification, licence, or official partnership claim.',
    'vision_principles_title' => 'Three principles shape the vision',
    'vision_principle_clarity_title' => 'Clarity',
    'vision_principle_clarity_text' => 'Make results, rules, status labels, and next steps understandable before a player acts.',
    'vision_principle_reliability_title' => 'Reliability',
    'vision_principle_reliability_text' => 'Use authoritative server-side decisions, controlled settlement, and reviewable records where the platform supports them.',
    'vision_principle_responsibility_title' => 'Responsibility',
    'vision_principle_responsibility_text' => 'Treat privacy, eligibility, responsible gaming, and honest public communication as part of the product.',
    'mission_eyebrow' => 'MISSION · HOW WE OPERATE',
    'mission_principles_title' => 'Turn the vision into accountable practice',
    'mission_principle_service_title' => 'Useful public service',
    'mission_principle_service_text' => 'Give people a clear way to read published information, follow results, and find the right support route.',
    'mission_principle_technology_title' => 'Practical technology',
    'mission_principle_technology_text' => 'Prefer verified server-side data, safe defaults, idempotent operations, and accessible interfaces over decorative complexity.',
    'mission_principle_growth_title' => 'Responsible growth',
    'mission_principle_growth_text' => 'Improve the platform without promising outcomes, inventing milestones, or turning future ideas into present facts.',
    'clear_eyebrow' => 'FIVE VALUE LENSES',
    'clear_title' => 'CLEAR, reinterpreted for this platform',
    'clear_text' => 'The live reference presents the word CLEAR. These five lenses are shown here as neutral editorial values, not as proof of government identity, certification, or official affiliation.',
    'clear_collaboration_title' => 'Collaboration',
    'clear_collaboration_text' => 'Coordinate across product, support, compliance, and operations so public information stays consistent.',
    'clear_learning_title' => 'Learning & Growth',
    'clear_learning_text' => 'Use reviewable feedback and measured improvement to make the platform easier and safer to use.',
    'clear_ethics_title' => 'Ethics',
    'clear_ethics_text' => 'Prefer honest labels, fair communication, privacy-aware defaults, and no unsupported claims.',
    'clear_accountability_title' => 'Accountability',
    'clear_accountability_text' => 'Keep important actions attributable, reviewable, and governed by server-side rules.',
    'clear_relationship_title' => 'Relationship',
    'clear_relationship_text' => 'Treat players, support contacts, and operational partners with clarity and respect.',
    'governance_eyebrow' => 'GOOD GOVERNANCE & TRUST',
    'governance_display_title' => 'Trust is a practice, not a badge',
    'governance_display_text' => 'The platform describes controls it can evidence. It does not convert those controls into a licence, certification, government relationship, or promise of absolute safety.',
    'journey_eyebrow' => 'WHERE WE ARE GOING',
    'journey_title' => 'A direction without invented milestones',
    'journey_text' => 'The application can improve through approved content, measured engineering work, and operational review. No launch dates, customer counts, partnerships, or future products are asserted here.',
    'journey_now' => 'NOW',
    'journey_now_text' => 'Operate the configured public pages, result views, verification flows, account controls, and audit-aware financial paths.',
    'journey_next' => 'NEXT',
    'journey_next_text' => 'Improve only through approved product and content changes that can be tested and versioned.',
    'journey_future' => 'FUTURE',
    'journey_future_text' => 'Keep the direction open until a specific improvement is approved, configured, and ready to disclose.',
    'vision_cta_title' => 'Explore the platform with context',
    'vision_cta_text' => 'Read the independent About page, follow published results, or contact support through real application routes.',
    'vision_cta_about' => 'About Us',
    'vision_cta_results' => 'View Results',
    'vision_cta_contact' => 'Contact Support',
    'vision_source_note' => 'The live page was used as an observable visual/content reference. Unsupported institutional language and official-looking claims are intentionally not copied.',

    // ------------------------------------------------------------------ terms
    'terms_title' => 'Terms of Use',
    'terms_version_label' => 'Version',
    'terms_effective_label' => 'Effective date',
    'terms_updated_label' => 'Last updated',
    'terms_not_configured' => 'NOT_CONFIGURED',

    'terms_intro_title' => '1. Scope and platform identity',
    'terms_intro_text' => 'These Terms govern your use of this independent lottery information and wagering platform. We are not the Government Lottery Office (GLO), not an official agent, and not a government portal. GLO product rules are referenced only for prize-rule compatibility.',

    'terms_products_title' => '2. Products and prize rules',
    'terms_products_l6_text' => 'GLO-compatible L6: ticket price {l6_price} THB; full-sale allocation {l6_allocation} THB across {l6_units} units; {l6_prize_count} prizes in the full-sale schedule; prizes scale proportionally when sales are below full sale.',
    'terms_products_n3_text' => 'GLO-compatible N3: ticket price {n3_price} THB; prize pool is {n3_pool_rate} of N3 gross sales; payouts are variable and draw-calculated — never fixed historical amounts.',
    'terms_products_separation_text' => 'Operator markets (3D, TOD, 2D, Run) are platform products. They are never described as GLO N3 or as government lottery products.',

    'terms_stamp_title' => '3. Prize duty and tax treatment',
    'terms_stamp_text' => 'Stamp duty: {stamp_unit_baht} THB for every {stamp_divisor} THB of gross prize or fraction thereof (ceil(gross/{stamp_divisor}) × {stamp_unit_baht} THB). Prize income under this rule is treated as stamp-duty only; income tax is exempt on the displayed calculation. No 0.5% or 1% withholding model is used on this platform.',

    'terms_accounts_title' => '4. Accounts and verification',
    'terms_accounts_one_text' => 'One account per verified user where enforced: email, username, and phone are unique at registration.',
    'terms_accounts_verification_text' => 'Identity verification requirements may vary by account, product, and channel. We never promise a single fixed verification path for every user.',

    'terms_age_title' => '5. Age rules',
    'terms_age_registration_text' => 'Registration: you confirm you can enter into a binding agreement under applicable law and that the information you provide is accurate. A date of birth may be collected for identity verification and is stored for server-side age checks.',
    'terms_age_purchase_text' => 'Purchase: eligibility to purchase or wager may be checked against product rules, responsible-gaming settings, and account status before an order is accepted.',
    'terms_age_claim_text' => 'Claim: prize claimants must be {claim_min_age} years of age or older, calculated from the verified date of birth on file — never from a client-supplied age.',

    'terms_ticket_title' => '6. Ticket ownership and transfers',
    'terms_ticket_text' => 'Lottery ticket numbers are digit strings with leading zeros preserved. For GLO-style L6 prizes, a claim follows the authorized claimant path for the verified holder; tickets are not freely transferable instruments for claiming purposes on this platform.',

    'terms_responsible_title' => '7. Responsible gaming',
    'terms_responsible_text' => 'Where enabled on your account, you can use self-exclusion, deposit/loss limits, and reality checks from your profile and the responsible-gaming settings. These are player-protection tools, not a statement of regulatory approval.',

    'terms_claim_title' => '8. Claims and payouts',
    'terms_claim_text' => 'There is no promise of immediate payment. Claims are subject to result verification, eligibility review, applicable holds, KYC where required, stamp duty calculation, and the approved payout channel. Claim window is {claim_window_years} years from the draw under configured GLO-compatible rules.',

    'terms_disclaimer_title' => '9. Legal disclaimer',
    'terms_disclaimer_text' => 'This is an independent platform. References to the Government Lottery Office and its prize rules are for compatibility and information only. We do not claim government ownership, operation, endorsement, or agency status. No page on this site should be read as an official GLO website.',

    'terms_prohibited_title' => '10. Prohibited conduct',
    'terms_prohibited_text' => 'Automated abuse of public endpoints, attempted circumvention of rate limits, fraudulent claim or identity information, and attempts to manipulate finalized results may result in account restriction.',

    'terms_contact_title' => '11. Contact and changes',
    'terms_contact_text' => 'Questions about these Terms: use the contact page. Material changes will publish a new version number and updated effective date on this page; historical versions are never silently rewritten in place without a version bump.',

    // ------------------------------------------------------------------ useful link labels
    'useful_links_results' => 'Draw results',
    'useful_links_ticket_check' => 'Check a ticket',
    'useful_links_sales_points' => 'Sales points',
    'useful_links_contact' => 'Contact',
    'useful_links_privacy' => 'Privacy policy',
    'useful_links_terms' => 'Terms of use',

    // ------------------------------------------------------------------ shared
    'not_configured' => 'NOT_CONFIGURED',
    'skip_to_content' => 'Skip to main content',
    'back_to_home' => 'Back to home',

    // ------------------------------------------------- PROMPT 3: member auth
    'login_meta_title' => 'Member Sign In',
    'login_meta_description' => 'Sign in to your member account.',
    'login_heading' => 'Member Sign In',
    'login_lead' => 'Sign in to your member account to continue.',
    'login_identifier' => 'Account ID or Email Address',
    'login_identifier_placeholder' => 'Account ID, username or email address',
    'login_identifier_hint' => 'Use your Account ID, username or the email address on your account.',
    'login_password' => 'Password',
    'login_remember' => 'Remember me',
    'login_submit' => 'Login',
    'login_register_link' => 'Register',
    'login_forgot_link' => 'Forgot Password',
    'captcha_label' => 'CAPTCHA',
    'captcha_placeholder' => 'Answer',
    'captcha_hint' => 'Solve the challenge above to prove you are human.',
    'identifier_invalid' => 'Please provide a valid Account ID, username or email address.',

    'register_meta_title' => 'Create a Member Account',
    'register_meta_description' => 'Register a new member account.',
    'register_heading' => 'Member Registration',
    'register_lead' => 'Create your member account in a few steps.',
    'register_section_account' => 'Accounts Information',
    'register_section_personal' => 'Personal Details',
    'register_section_birth' => 'Birth Information',
    'register_referral' => 'Referral ID',
    'register_mobile' => 'A.C. / Mobile Number',
    'register_mobile_hint' => 'Digits only, 5-15 numbers.',
    'register_password' => 'Password',
    'register_password_confirm' => 'Confirm Password',
    'register_first_name' => 'First Name',
    'register_last_name' => 'Last Name',
    'register_gender' => 'Gender',
    'register_gender_male' => 'Male',
    'register_gender_female' => 'Female',
    'register_gender_unspecified' => 'Unspecified',
    'register_select' => 'Please select',
    'register_city' => 'City',
    'register_country' => 'Country',
    'register_email' => 'Active Email',
    'register_dob' => 'Date of Birth',
    'register_nationality' => 'Nationality',
    'register_terms' => 'I have read and accept the Terms and Conditions.',
    'register_terms_hint' => 'Your acceptance is timestamped and recorded with your account.',
    'register_submit' => 'Register',
    'register_back_to_login' => 'Back to Login',
    'register_welcome' => 'Welcome! Your member account is ready.',
    'register_error_email_taken' => 'This email address is already registered.',
    'register_error_mobile_taken' => 'This mobile number is already registered.',

    'reset_meta_title' => 'Set a New Password',
    'reset_meta_description' => 'Choose a new password for your member account.',
    'forgot_meta_title' => 'Password Recovery',
    'forgot_meta_description' => 'Recover access to your member account.',
    'forgot_heading' => 'Forgot Password',
    'forgot_lead' => 'Enter your account details and we will send recovery instructions.',
    'forgot_identifier' => 'Account No. or Email',
    'forgot_identifier_placeholder' => 'Account number or email address',
    'forgot_identifier_hint' => 'Use your Account No., username or the email address on your account.',
    'forgot_submit' => 'Submit',
    'forgot_back_to_login' => 'Back to Login',
    'forgot_captcha_label' => 'CAPTCHA',
    'reset_requested' => 'If the account exists, password recovery instructions have been sent.',
    'reset_new_password' => 'New Password',
    'reset_confirm_password' => 'Confirm New Password',
    'reset_password_hint' => 'At least 8 characters with letters and numbers.',
    'reset_submit' => 'Reset Password',
    'reset_completed' => 'Your password has been reset. Please sign in with your new password.',
    'reset_invalid_token' => 'This password reset link is invalid or has expired.',
    'reset_mail_subject' => 'Password Recovery',
    'reset_mail_line1' => 'You are receiving this email because a password recovery was requested for your account.',
    'reset_mail_action' => 'Reset Password',
    'reset_mail_line2' => 'This link expires in :minutes minutes.',
    'reset_mail_line3' => 'If you did not request a password recovery, no further action is required.',
    'logged_out' => 'You have been logged out.',

    'password_required' => 'A password is required.',
    'password_min_length' => 'The password must be at least :min characters.',
    'password_invalid' => 'The password is invalid.',
    'password_letters_numbers' => 'The password must contain at least one letter and one number.',
    'password_common' => 'This password is too common. Please choose a stronger one.',

    // ------------------------------------------------------------------ privacy
    'privacy_meta_title' => 'Privacy Policy',
    'privacy_meta_description' => 'Versioned privacy policy explaining data processing, security, anonymous public browsing, and player rights.',
    'privacy_title' => 'Privacy Policy',
    'privacy_version_label' => 'Version',
    'privacy_effective_label' => 'Effective date',
    'privacy_updated_label' => 'Last updated',
    'privacy_not_configured' => 'NOT_CONFIGURED',
    'privacy_data_title' => '1. Data We Process',
    'privacy_data_text' => 'We process account registration data, wallet balances, transaction records, verification documents where required by compliance, and technical security logs. Public ticket checking does not store personal identity.',
    'privacy_public_title' => '2. Public & Anonymous Surfaces',
    'privacy_public_text' => 'Home, draw results, prize checking, and public sales information are completely anonymous. No user login is required to browse results, and other users\' personal details or balances are never exposed.',
    'privacy_security_title' => '3. Security & Storage',
    'privacy_security_text' => 'All data transmissions are protected via modern TLS encryption. Sensitive identity documents are stored securely on private disks with restricted access and immutable audit logging.',
    'privacy_rights_title' => '4. Player Rights & KYC',
    'privacy_rights_text' => 'You can review and update your profile details in your account dashboard. Verification documents can be managed according to the platform\'s compliance retention schedule.',
    'privacy_contact_title' => '5. Inquiries & Contact',
    'privacy_contact_text' => 'For any questions or data requests regarding this Privacy Policy, please contact our support team through the official contact channels.',

    // Public Pages 05-14 metadata and neutral headings.
    'fees_title' => 'Our Fees',
    'fees_meta_title' => 'Our Fees',
    'fees_meta_description' => 'Source-driven public fee schedule for platform services. Unconfigured amounts are shown as NOT_CONFIGURED.',
    'verification_title' => 'Account Verification',
    'verification_meta_title' => 'Account Verification',
    'verification_meta_description' => 'A public guide to this platform’s account verification process. Private document submission remains behind authentication.',
    'grade_title' => 'Account Grade',
    'grade_meta_title' => 'Account Grade',
    'grade_meta_description' => 'A public explanation of configured account-grade thresholds and eligible game discounts.',
    'discount_meta_title' => 'Lotto Discount',
    'discount_meta_description' => 'Published server-authoritative discount rules. Government lottery ticket prices remain protected where configured.',
    'download_title' => 'Download App',
    'download_meta_title' => 'Download App',
    'download_meta_description' => 'Configured application destinations for this platform. Unavailable destinations are not displayed.',


    'how_title' => 'How to Play',
    'how_meta_title' => 'How to Play',
    'how_meta_description' => 'A source-driven guide to using the platform, checking results, and following the configured claim process.',
    'how_disclaimer' => 'This guide explains platform steps only. It does not promise a win, payout, approval, or outcome.',
    'how_step_1_title' => 'Choose a supported product',
    'how_step_1_text' => 'Review the product rules and select only a market that is currently available in the platform.',
    'how_step_2_title' => 'Review the order',
    'how_step_2_text' => 'Check the number format, price, draw reference, and any applicable account or responsible-gaming limits before purchase.',
    'how_step_3_title' => 'Purchase through the account flow',
    'how_step_3_text' => 'Submit the order through the authenticated purchase flow. The server calculates the final price and ignores client-supplied discounts.',
    'how_step_4_title' => 'Check the published result',
    'how_step_4_text' => 'Use the result and verification tools to compare a ticket or order with a published source. A lookup is not a certification of physical paper.',
    'how_step_5_title' => 'Follow the claim process',
    'how_step_5_text' => 'If a result is eligible, follow the configured claim and verification instructions. There is no guarantee of immediate payment.',
    'faq_title' => 'Frequently Asked Questions',
    'faq_meta_title' => 'Frequently Asked Questions',
    'faq_meta_description' => 'Searchable answers about accounts, verification, results, fees, claims, and responsible use of the platform.',
    'faq_q_1' => 'Do I need an account to browse public pages?',
    'faq_a_1' => 'Public information and published results can be browsed without an account. Account actions use the authentication and authorization rules shown by the platform.',
    'faq_q_2' => 'How does account verification work?',
    'faq_a_2' => 'Verification is an authenticated account process. The public guide explains the configured document categories, while private documents are submitted only inside the account area.',
    'faq_q_3' => 'Are discounts calculated in the browser?',
    'faq_a_3' => 'No. Published discount rules and any purchase quote are resolved on the server. Browser values cannot change a final price.',
    'faq_q_4' => 'Can public verification certify a physical ticket?',
    'faq_a_4' => 'No. A public check reports the digital or published record available to the service. It cannot certify physical paper or ownership without the issuing authority.',
    'faq_q_5' => 'Where can I ask a support question?',
    'faq_a_5' => 'Use the Contact page. It shows only configured support channels and reports honestly when a delivery capability is unavailable.',

    // ------------------------------------------------------------------ Pages 80 and 82 interface labels
    'privacy_home_aria' => 'Home',
    'privacy_brand_subtitle' => 'Privacy information',
    'privacy_primary_nav' => 'Primary navigation',
    'privacy_nav_home' => 'Home',
    'privacy_nav_about' => 'About us',
    'privacy_nav_vision' => 'Vision',
    'privacy_nav_privacy' => 'Privacy',
    'privacy_nav_terms' => 'Terms',
    'privacy_nav_contact' => 'Contact',
    'privacy_login' => 'Login',
    'privacy_dashboard' => 'Dashboard',
    'privacy_eyebrow' => '05 · Data protection',
    'privacy_public_note' => 'This page displays approved public policy copy only. It does not invent a controller identity, regulator, legal basis, retention period, cookie claim, or certification.',
    'privacy_metadata_aria' => 'Privacy policy metadata',
    'privacy_status_label' => 'Status',
    'privacy_available' => 'Available',
    'privacy_unavailable' => 'Unavailable',
    'privacy_unavailable_title' => 'Privacy content unavailable',
    'privacy_unavailable_body' => 'No substitute privacy wording is displayed.',
    'privacy_contents_aria' => 'Privacy contents',
    'privacy_document_map' => 'Document map',
    'privacy_section_fallback' => 'Section',
    'privacy_search_label' => 'Search this policy',
    'privacy_search_placeholder' => 'Search privacy text',
    'privacy_clear' => 'Clear',
    'privacy_search_status' => 'Showing all :count items.',
    'privacy_section_eyebrow' => 'Privacy section',
    'privacy_data_questions' => 'Data questions',
    'privacy_support_heading' => 'Use the configured support route',
    'privacy_support_body' => 'Do not send identity documents through an unverified public endpoint.',
    'privacy_print_save' => 'Print / save',
    'privacy_contact_support' => 'Contact support',

    'download_home_aria' => 'Home',
    'download_brand_subtitle' => 'App destinations',
    'download_primary_nav' => 'Primary navigation',
    'download_nav_home' => 'Home',
    'download_nav_download' => 'Download',
    'download_nav_how_to_play' => 'How to play',
    'download_nav_faq' => 'FAQ',
    'download_nav_contact' => 'Contact',
    'download_login' => 'Login',
    'download_dashboard' => 'Dashboard',
    'download_eyebrow' => '14 · Trusted destinations',
    'download_public_note' => 'Only validated HTTPS destinations are shown. No package, version, checksum, QR code, security certificate, or store listing is invented.',
    'download_metadata_aria' => 'Download metadata',
    'download_link_status' => 'Link status',
    'download_android' => 'Android',
    'download_ios' => 'iOS',
    'download_pwa' => 'Progressive web app',
    'download_ready' => 'Ready',
    'download_destination_map' => 'Destination map',
    'download_destinations' => 'Destinations',
    'download_safety_check' => 'Safety check',
    'download_integrity_status' => 'Integrity status',
    'download_validated_links' => 'Validated links',
    'download_available_destinations' => 'Available destinations',
    'download_open_store' => 'Open store',
    'download_open_web_app' => 'Open web app',
    'download_not_configured' => 'NOT_CONFIGURED',
    'download_empty_body' => 'No verified app destination is configured. Continue using the web application through the current site address.',
    'download_browser_safety' => 'Browser safety',
    'download_check_before_install' => 'Check before you install',
    'download_safety_body' => 'Confirm that the destination is the expected HTTPS store or site address before entering credentials. Avoid files shared through unsolicited messages.',
    'download_safety_unknown_domain' => 'Do not install a package from a link with an unknown domain.',
    'download_safety_credentials' => 'Do not enter a password or payment detail into a page reached from an unverified message.',
    'download_safety_account_route' => 'Use the authenticated account route for account actions.',
    'download_integrity_eyebrow' => 'Integrity state',
    'download_build_verification' => 'Build verification',
    'download_integrity_body' => 'Checksum verification and download tracking require a verified public build manifest. They are not claimed when no manifest is configured.',
    'download_checksum' => 'Checksum: NOT_CONFIGURED · Tracking: NOT_CONFIGURED',
    'download_no_link_eyebrow' => 'No link available?',
    'download_support_heading' => 'Use the configured support route',
    'download_support_body' => 'Do not request an app package through an unverified channel.',
    'download_print_save' => 'Print / save',
    'download_contact_support' => 'Contact support',

];

```

## `lang/th/public_pages.php`

# TYPE: PHP translation map
# PURPOSE: Thai-locale Page 80 and Page 82 key and placeholder parity.

```php
<?php

/*
|--------------------------------------------------------------------------
| Public pages copy — Thai (/about, /vision, /terms)
|--------------------------------------------------------------------------
| Keys must match lang/en/public_pages.php exactly (parity-tested).
| Neutral wording only: never present the platform as GLO / official agent /
| government portal. Config-sourced numbers use the same {placeholders}.
|
*/

return [

    // ------------------------------------------------------------------ meta
    'about_meta_title' => 'เกี่ยวกับเรา',
    'about_meta_description' => 'วิธีทำงานของแพลตฟอร์มลอตเตอรี่ที่เข้ากันได้กับ GLO: เลือกงวด ซื้ออย่างปลอดภัย ติดตามผลที่ยืนยันแล้ว และขึ้นเงินรางวัลตามกฎที่ชัดเจน',
    'vision_meta_title' => 'วิสัยทัศน์และพันธกิจ',
    'vision_meta_description' => 'วิสัยทัศน์ พันธกิจ ค่านิยมหลัก และการกำกับดูแลที่เราใช้จริงสำหรับผลลอตเตอรี การชำระบัญชี และการคุ้มครองผู้เล่น',
    'terms_meta_title' => 'ข้อกำหนดการใช้งาน',
    'terms_meta_description' => 'ข้อกำหนดฉบับมีเลขเวอร์ชัน ครอบคลุมผลิตภัณฑ์ รางวัล ค่าธรรมเนียมแสตมป์ บัญชี อายุ การขึ้นเงิน การเล่นอย่างรับผิดชอบ และข้อจำกัดความรับผิด',
    'og_type' => 'website',

    // ------------------------------------------------------------------ about
    'about_title' => 'เกี่ยวกับแพลตฟอร์มนี้',
    'about_lead' => 'แพลตฟอร์มข้อมูลและเดิมพันลอตเตอรี่อิสระที่เข้ากันได้กับ GLO เราไม่ใช่สำนักงานสลากกินแบ่งรัฐบาล ไม่ใช่ตัวแทนทางการของ GLO และไม่ใช่พอร์ทัลของรัฐ',

    'how_it_works_title' => 'วิธีการทำงาน',
    'how_it_works_choose_label' => 'เลือก',
    'how_it_works_choose_text' => 'ดูงวดและผลที่เผยแพร่ แล้วเลือกผลิตภัณฑ์: L6 หรือ N3 ตามรูปแบบ GLO บนแพลตฟอร์มนี้ หรือตลาดของผู้ดำเนินการ เช่น 3D, TOD, 2D และ Run',
    'how_it_works_buy_label' => 'ซื้อ',
    'how_it_works_buy_text' => 'สร้างบัญชีหรือเข้าสู่ระบบ เติมกระเป๋าตามช่องทางชำระเงินที่กำหนดค่าไว้ แล้วสั่งซื้อ เลขหมายลอตเตอรี่จะคงเลขนำหน้าศูนย์ไว้เสมอ',
    'how_it_works_result_label' => 'ผลรางวัล',
    'how_it_works_result_text' => 'ติดตามผลงวดที่เผยแพร่พร้อมป้ายสถานะแหล่งที่มาที่ชัดเจน ข้อมูลตัวอย่าง (fixture) จะไม่ถูกนำเสนอเป็นประกาศทางการของรัฐบาล',
    'how_it_works_claim_label' => 'ขึ้นเงินรางวัล',
    'how_it_works_claim_text' => 'หากสลากถูกรางวัล การขึ้นเงินจะผ่านการตรวจสอบยืนยัน เงื่อนไขความเหมาะสม การระงับที่บังคับใช้ KYC การคำนวณค่าธรรมหน้าแสตมป์ และเส้นทางการจ่ายที่อนุมัติแล้ว — ไม่มีสัญญาการจ่ายทันที',

    'history_title' => 'แนวทางของเรา',
    'history_text' => 'แพลตฟอร์มนี้มีขึ้นเพื่อทำให้ผลลอตเตอรี การตรวจสลาก และเครื่องมือบัญชีโปร่งใสตรวจสอบได้ เราชวนผลพร้อมป้ายแหล่งที่มาที่ซื่อสัตย์ คำนวณรางวัลจากกฎที่กำหนดค่าซึ่งเข้ากันได้กับ GLO และเก็บบันทึกการเงินแบบ append-only เราไม่กล่าวอ้างปีก่อตั้ง ความเกี่ยวข้องกับรัฐบาล หรือการรับรองที่ไม่ได้เผยแพร่ที่นี่',

    'useful_links_title' => 'ลิงก์ที่เป็นประโยชน์',
    'useful_links_text' => 'หน้าสาธารณะบนเว็บไซต์นี้ — ดูผล ตรวจสลาก และค้นหาจุดขายได้โดยไม่ต้องเข้าสู่ระบบ',

    'contact_title' => 'ติดต่อ',
    'contact_text' => 'มีคำถามเกี่ยวกับแพลตฟอร์ม บัญชี หรือหน้าเหล่านี้? ใช้หน้าติดต่อเมื่อมีรายละเอียดฝ่ายสนับสนุนถูกกำหนดค่าแล้ว',

    'about_hero_eyebrow' => '02 · เกี่ยวกับเรา',
    'about_hero_title' => 'ทำความเข้าใจแพลตฟอร์มได้อย่างชัดเจนยิ่งขึ้น',
    'about_hero_visual_label' => 'คลังข้อมูลสาธารณะ',
    'about_story_eyebrow' => 'เรื่องราวเบื้องหลังข้อมูล',
    'about_story_source' => 'เนื้อหาประวัติศาสตร์ปรับจากหน้า About สาธารณะที่สังเกตได้ โดยกล่าวถึงประวัติลอตเตอรี่ไทย ไม่ใช่อัตลักษณ์หรือสถานะทางกฎหมายของแพลตฟอร์มนี้',
    'about_timeline_eyebrow' => 'ข้อมูลอ้างอิงทางประวัติศาสตร์',
    'about_timeline_title' => 'ประวัติลอตเตอรี่ในประเทศไทย',
    'about_timeline_text' => 'ไทม์ไลน์แบบย่อที่มีป้ายแหล่งที่มาจากเหตุการณ์ที่อธิบายในหน้าอ้างอิงสาธารณะ โดยแยกบริบททางประวัติศาสตร์ออกจากอัตลักษณ์อิสระของแพลตฟอร์มนี้',
    'about_timeline_source_label' => 'ประวัติศาสตร์พร้อมแหล่งที่มา',
    'about_timeline_empty' => 'ไม่สามารถโหลดเหตุการณ์ทางประวัติศาสตร์ได้ชั่วคราว ส่วนอื่นของหน้า About ยังใช้งานได้',
    'about_values_eyebrow' => 'วิธีการทำงานของเรา',
    'about_values_title' => 'หลักการที่สะท้อนในแอปพลิเคชัน',
    'about_values_text' => 'หลักการเหล่านี้อธิบายความสามารถและการควบคุมที่มองเห็นได้ในแอปพลิเคชัน ไม่ใช่ใบรับรอง การรับรองจากรัฐบาล หรือคำสัญญาผลลัพธ์ใดเป็นพิเศษ',
    'about_trust_eyebrow' => 'ความไว้วางใจโดยไม่กล่าวอ้างเกินจริง',
    'about_trust_title' => 'สิ่งที่แพลตฟอร์มแสดงได้อย่างชัดเจน',
    'about_trust_text' => 'หน้าผลรางวัลสาธารณะใช้ป้ายสถานะแหล่งที่มา การดำเนินการทางการเงินใช้การอนุญาตฝั่งเซิร์ฟเวอร์ และเนื้อหาสาธารณะหลีกเลี่ยงข้อมูลส่วนตัวกับข้อกล่าวอ้างเชิงสถาบันที่ไม่มีแหล่งอ้างอิง',
    'about_cta_title' => 'ไปต่อด้วยข้อมูลที่คุณต้องการ',
    'about_cta_text' => 'อ่านกฎ ดูผลที่เผยแพร่ ตรวจสลาก หรือติดต่อทีมสนับสนุนผ่านเส้นทางสาธารณะจริงของแอปพลิเคชัน',
    'about_cta_results' => 'ดูผลรางวัล',
    'about_cta_check' => 'ตรวจสลาก',
    'about_cta_contact' => 'ติดต่อฝ่ายสนับสนุน',
    'about_source_note' => 'หน้านี้มีวัตถุประสงค์เพื่อข้อมูล ประวัติลอตเตอรี่เป็นข้อมูลอ้างอิงทางประวัติศาสตร์ และไม่ได้หมายความว่าแพลตฟอร์มนี้เป็นหน่วยงานรัฐบาล พอร์ทัลรัฐบาล หรือตัวแทนทางการ',

    'about_timeline.1874.title' => 'ข้อมูลอ้างอิงลอตเตอรี่ยุคแรก',
    'about_timeline.1874.description' => 'หน้าอ้างอิงที่สังเกตได้กล่าวถึงการออกลอตเตอรี่ในปี 1874 ในรัชสมัยรัชกาลที่ 5 ซึ่งเกี่ยวข้องกับงานเฉลิมพระชนมพรรษาและงานจัดแสดง',
    'about_timeline.1917.title' => 'ลอตเตอรี่ระดมทุนในช่วงสงคราม',
    'about_timeline.1917.description' => 'แหล่งอ้างอิงกล่าวถึงบริบทการระดมทุนด้วยลอตเตอรี่ในปี 1917 ระหว่างสงครามโลกครั้งที่หนึ่ง โดยระบุถึงพระบรมราชานุญาตในบันทึกประวัติศาสตร์นั้น',
    'about_timeline.1923.title' => 'ลอตเตอรี่เสือป่าล้านบาท',
    'about_timeline.1923.description' => 'แหล่งข้อมูลกล่าวถึงลอตเตอรี่ในปี 1923 พิมพ์หนึ่งล้านฉบับ ราคาฉบับละหนึ่งบาท เพื่อหารายได้บำรุงกองเสือป่าอาสาสมัคร',
    'about_timeline.1933.title' => 'ลอตเตอรี่รัฐบาลสยาม',
    'about_timeline.1933.description' => 'แหล่งข้อมูลกล่าวถึงบริบทนโยบายลอตเตอรี่รัฐบาลในปี 1933 หลังการเปลี่ยนแปลงระบบเงินรัชชูปการ โดยระบุว่ามีการออกปีละสี่งวด',
    'about_timeline.1935.title' => 'กิจกรรมสลากบำรุงเทศบาล',
    'about_timeline.1935.description' => 'ข้อมูลอ้างอิงทางประวัติศาสตร์บันทึกการจำหน่ายสลากบำรุงเทศบาลครั้งแรกในเดือนพฤศจิกายน 1935 และการดำเนินควบคู่กับลอตเตอรี่รัฐบาล',
    'about_timeline.1939.title' => 'การบริหารสลากกินแบ่งรัฐบาลอย่างเป็นระบบ',
    'about_timeline.1939.description' => 'แหล่งข้อมูลบันทึกการโอนกิจการเข้าสังกัดกระทรวงการคลังในปี 1939 และระบุวันที่ 5 เมษายน 1939 เป็นวันสถาปนาสำนักงานสลากกินแบ่งรัฐบาล',
    'about_timeline.1974.title' => 'สถานะรัฐวิสาหกิจตามกฎหมาย',
    'about_timeline.1974.description' => 'แหล่งข้อมูลบันทึกพระราชบัญญัติสำนักงานสลากกินแบ่งรัฐบาล พ.ศ. 2517 และอธิบายสำนักงานในฐานะนิติบุคคลและรัฐวิสาหกิจสังกัดกระทรวงการคลัง',
    'about_timeline.2000.title' => 'สมาคมลอตเตอรี่ระหว่างประเทศ',
    'about_timeline.2000.description' => 'หน้าอ้างอิงที่สังเกตได้ระบุว่าสำนักงานสลากกินแบ่งรัฐบาลเข้าเป็นสมาชิก World Lottery Association และ Asia Pacific Lottery Association ในปี 2000',

    // ------------------------------------------------------------------ vision
    'vision_title' => 'วิสัยทัศน์และพันธกิจ',
    'vision_lead' => 'แพลตฟอร์มลอตเตอรี่ที่เชื่อถือได้และเข้ากันได้กับ GLO ซึ่งทุกผล ทุกรางวัล และทุกการจ่ายตรวจสอบได้',

    'vision_section_title' => 'วิสัยทัศน์',
    'vision_section_text' => 'เป็นแพลตฟอร์มลอตเตอรี่ที่เข้ากันได้กับ GLO ที่โปร่งใสที่สุด: ผู้เล่นตรวจสอบผลที่เผยแพร่ ทำความเข้าใจการคำนวณรางวัล และติดตามการตัดสินใจจ่ายเงินได้พร้อมป้ายแหล่งที่มาและหลักฐานการตรวจสอบ',

    'mission_section_title' => 'พันธกิจ',
    'mission_section_text' => 'ดำเนินการฟีเจอร์ข้อมูลและเดิมพันลอตเตอรี่ด้วยการเผยแพร่ผลที่ยืนยันแล้ว การชำระบัญชีรางวัลตามที่กำหนดค่า การปรับยอดขาย บันทึกการตรวจสอบแบบ immutable การถอนที่ผ่าน KYC และหน้าสาธารณะที่เคารพความเป็นส่วนตัว',

    'core_values_title' => 'ค่านิยมหลัก',
    'core_values_transparency_label' => 'ความโปร่งใส',
    'core_values_transparency_text' => 'ผลมีป้ายสถานะแหล่งที่มาชัดเจน กฎรางวัลมาจากค่าที่กำหนดไว้ ไม่ใช่ตารางที่ซ่อนอยู่',
    'core_values_security_label' => 'ความปลอดภัย',
    'core_values_security_text' => 'การอนุญาตฝั่งเซิร์ฟเวอร์ webhook ที่ลงนาม และการเขียนสมุดบัญชีแบบ transactional ป้องกันทุกการเงิน',
    'core_values_accountability_label' => 'ความรับผิดชอบ',
    'core_values_accountability_text' => 'บันทึก audit แบบ append-only ทำให้การเคลื่อนไหวเงินและการกระทำสิทธิพิเศษตรวจสอบย้อนหลังได้',
    'core_values_fairness_label' => 'ความเป็นธรรม',
    'core_values_fairness_text' => 'เครื่องยนต์รางวัลตามกฎที่กำหนด pool แบบ N3 คำนวณจากงวด ไม่ใช่จำนวนเงินคงที่จากอดีต',
    'core_values_privacy_label' => 'ความเป็นส่วนตัว',
    'core_values_privacy_text' => 'หน้าสาธารณะไม่ระบุตัวตน ข้อมูลส่วนบุคคลไม่ปรากฏบนหน้าผลหรือจุดขายที่แชร์กัน',
    'core_values_reliability_label' => 'ความน่าเชื่อถือ',
    'core_values_reliability_text' => 'operation แบบ idempotent และ lock แบบ concurrency กันการจ่ายซ้ำและ race condition',

    'governance_title' => 'การกำกับดูแลและมาตรการ',
    'governance_text' => 'มาตรการที่เราใช้จริงในแพลตฟอร์มนี้:',
    'governance_idempotency' => 'คีย์ idempotency สำหรับกระเป๋าและการจ่ายรางวัล เพื่อกัน 실행ซ้ำ',
    'governance_transactional_ledger' => 'การเขียนสมุดบัญชีใน transaction พร้อม row locks',
    'governance_immutable_audit' => 'บันทึก audit แบบ append-only สำหรับการเงินและการกระทำสิทธิพิเศษ',
    'governance_source_status_labels' => 'ป้ายสถานะแหล่งที่มาของผล (verified, reconciled, fixture, not configured, unavailable)',
    'governance_server_authorization' => 'การอนุญาตฝั่งเซิร์ฟเวอร์ทุกการเงิน — ไม่เชื่อ role หรือจำนวนเงินจาก client',
    'governance_kyc_gates' => 'ด่าน KYC สำหรับการถอนเหนือเกณฑ์ที่กำหนด (WithdrawalKycGateService)',
    'governance_payout_holds' => 'การระงับการจ่ายและประวัติ freeze สำหรับสิทธิขึ้นเงิน — สลากที่ถูกระงับแล้วถูกรางวัลจะไม่ถูกจ่ายอัตโนมัติ',
    'governance_sales_reconciliation' => 'การปรับยอดขายตั๋วและชำระบัญชีงวด',
    'governance_disclaimer' => 'เหล่านี้คือมาตรการวิศวกรรม ไม่ใช่สัญญาความปลอดภัยเชิงเด็ดขาดหรือการไม่ถูกเจาะ และไม่ใช่การรับประกันว่าจะไม่มีความเสี่ยงเชิงปฏิบัติการ',

    'vision_hero_eyebrow' => '03 · วิสัยทัศน์และพันธกิจ',
    'vision_hero_title' => 'สร้างความไว้วางใจไว้ในทุกก้าวข้างหน้า',
    'vision_hero_text' => 'มุมมองเชิงกลยุทธ์ของแพลตฟอร์ม: ข้อมูลที่ชัดเจน การดำเนินงานที่รับผิดชอบ การมีส่วนร่วมอย่างมีความรับผิดชอบ และเทคโนโลยีที่ช่วยให้เข้าใจการตัดสินใจสำคัญได้ง่ายขึ้น',
    'vision_hero_visual_label' => 'วงโคจรของแพลตฟอร์มแห่งอนาคต',
    'vision_hero_disclaimer' => 'นี่คือวิสัยทัศน์ของแพลตฟอร์มอิสระ ไม่ใช่อัตลักษณ์ของรัฐบาล ใบรับรอง ใบอนุญาต หรือการกล่าวอ้างความร่วมมือทางการ',
    'vision_principles_title' => 'สามหลักการกำหนดวิสัยทัศน์',
    'vision_principle_clarity_title' => 'ความชัดเจน',
    'vision_principle_clarity_text' => 'ทำให้ผล กฎ ป้ายสถานะ และขั้นตอนถัดไปเข้าใจได้ก่อนที่ผู้เล่นจะดำเนินการ',
    'vision_principle_reliability_title' => 'ความน่าเชื่อถือ',
    'vision_principle_reliability_text' => 'ใช้การตัดสินใจฝั่งเซิร์ฟเวอร์ การชำระบัญชีที่ควบคุมได้ และบันทึกที่ตรวจสอบได้ในส่วนที่แพลตฟอร์มรองรับ',
    'vision_principle_responsibility_title' => 'ความรับผิดชอบ',
    'vision_principle_responsibility_text' => 'ให้ความเป็นส่วนตัว สิทธิ์การใช้งาน การเล่นอย่างรับผิดชอบ และการสื่อสารสาธารณะที่ซื่อสัตย์เป็นส่วนหนึ่งของผลิตภัณฑ์',
    'mission_eyebrow' => 'พันธกิจ · วิธีการดำเนินงาน',
    'mission_principles_title' => 'เปลี่ยนวิสัยทัศน์ให้เป็นการปฏิบัติที่รับผิดชอบ',
    'mission_principle_service_title' => 'บริการสาธารณะที่มีประโยชน์',
    'mission_principle_service_text' => 'มอบวิธีที่ชัดเจนในการอ่านข้อมูลที่เผยแพร่ ติดตามผล และค้นหาช่องทางสนับสนุนที่ถูกต้อง',
    'mission_principle_technology_title' => 'เทคโนโลยีที่ใช้ได้จริง',
    'mission_principle_technology_text' => 'ให้ความสำคัญกับข้อมูลฝั่งเซิร์ฟเวอร์ที่ยืนยันแล้ว ค่าเริ่มต้นที่ปลอดภัย การทำงานแบบ idempotent และอินเทอร์เฟซที่เข้าถึงได้ มากกว่าความซับซ้อนเพื่อการตกแต่ง',
    'mission_principle_growth_title' => 'การเติบโตอย่างรับผิดชอบ',
    'mission_principle_growth_text' => 'พัฒนาแพลตฟอร์มโดยไม่สัญญาผลลัพธ์ ไม่สร้างหมุดหมาย และไม่เปลี่ยนแนวคิดในอนาคตให้เป็นข้อเท็จจริงในปัจจุบัน',
    'clear_eyebrow' => 'กรอบคุณค่า 5 ด้าน',
    'clear_title' => 'CLEAR ในความหมายที่เหมาะกับแพลตฟอร์มนี้',
    'clear_text' => 'หน้าอ้างอิงสาธารณะใช้คำว่า CLEAR ค่านิยม 5 ด้านนี้แสดงเป็นแนวคิดเชิงบรรณาธิการที่เป็นกลาง ไม่ใช่หลักฐานอัตลักษณ์รัฐบาล ใบรับรอง หรือความเกี่ยวข้องทางการ',
    'clear_collaboration_title' => 'ความร่วมมือ',
    'clear_collaboration_text' => 'ประสานงานระหว่างผลิตภัณฑ์ ฝ่ายสนับสนุน การกำกับดูแล และปฏิบัติการ เพื่อให้ข้อมูลสาธารณะสอดคล้องกัน',
    'clear_learning_title' => 'การเรียนรู้และเติบโต',
    'clear_learning_text' => 'ใช้ข้อเสนอแนะที่ตรวจสอบได้และการปรับปรุงอย่างมีหลักฐาน เพื่อให้แพลตฟอร์มใช้ง่ายและปลอดภัยขึ้น',
    'clear_ethics_title' => 'จริยธรรม',
    'clear_ethics_text' => 'เลือกใช้ป้ายที่ซื่อสัตย์ การสื่อสารที่เป็นธรรม ค่าเริ่มต้นที่คำนึงถึงความเป็นส่วนตัว และไม่กล่าวอ้างที่ไม่มีแหล่งรองรับ',
    'clear_accountability_title' => 'ความรับผิดชอบ',
    'clear_accountability_text' => 'ทำให้การดำเนินการสำคัญระบุผู้รับผิดชอบ ตรวจสอบได้ และอยู่ภายใต้กฎฝั่งเซิร์ฟเวอร์',
    'clear_relationship_title' => 'สัมพันธภาพ',
    'clear_relationship_text' => 'ปฏิบัติต่อผู้เล่น ผู้ติดต่อฝ่ายสนับสนุน และพันธมิตรปฏิบัติการด้วยความชัดเจนและเคารพ',
    'governance_eyebrow' => 'ธรรมาภิบาลและความไว้วางใจ',
    'governance_display_title' => 'ความไว้วางใจคือการปฏิบัติ ไม่ใช่ตราสัญลักษณ์',
    'governance_display_text' => 'แพลตฟอร์มอธิบายเฉพาะการควบคุมที่แสดงหลักฐานได้ ไม่เปลี่ยนการควบคุมเหล่านั้นให้เป็นใบอนุญาต ใบรับรอง ความสัมพันธ์กับรัฐบาล หรือคำสัญญาความปลอดภัยอย่างสมบูรณ์',
    'journey_eyebrow' => 'ทิศทางของเรา',
    'journey_title' => 'ทิศทางที่ไม่มีการสร้างหมุดหมายขึ้นเอง',
    'journey_text' => 'แอปพลิเคชันสามารถพัฒนาด้วยเนื้อหาที่อนุมัติ งานวิศวกรรมที่วัดผลได้ และการทบทวนปฏิบัติการ หน้านี้ไม่กล่าวอ้างวันเปิดตัว จำนวนลูกค้า ความร่วมมือ หรือผลิตภัณฑ์ในอนาคต',
    'journey_now' => 'ปัจจุบัน',
    'journey_now_text' => 'ให้บริการหน้าสาธารณะ มุมมองผลรางวัล กระบวนการตรวจสอบ การควบคุมบัญชี และเส้นทางการเงินที่คำนึงถึง audit ตามค่าที่กำหนด',
    'journey_next' => 'ถัดไป',
    'journey_next_text' => 'ปรับปรุงผ่านการเปลี่ยนแปลงผลิตภัณฑ์และเนื้อหาที่อนุมัติ ทดสอบ และกำหนดเวอร์ชันได้เท่านั้น',
    'journey_future' => 'อนาคต',
    'journey_future_text' => 'เปิดทิศทางไว้จนกว่าการปรับปรุงเฉพาะจะได้รับอนุมัติ กำหนดค่า และพร้อมเปิดเผย',
    'vision_cta_title' => 'สำรวจแพลตฟอร์มพร้อมบริบท',
    'vision_cta_text' => 'อ่านหน้า About อิสระ ติดตามผลที่เผยแพร่ หรือติดต่อฝ่ายสนับสนุนผ่านเส้นทางจริงของแอปพลิเคชัน',
    'vision_cta_about' => 'เกี่ยวกับเรา',
    'vision_cta_results' => 'ดูผลรางวัล',
    'vision_cta_contact' => 'ติดต่อฝ่ายสนับสนุน',
    'vision_source_note' => 'หน้าอ้างอิงสาธารณะถูกใช้เป็นข้อมูลสังเกตด้านภาพและเนื้อหาเท่านั้น ภาษาสถาบันที่ไม่มีการรองรับและข้อกล่าวอ้างแบบทางการจึงไม่ถูกคัดลอก',

    // ------------------------------------------------------------------ terms
    'terms_title' => 'ข้อกำหนดการใช้งาน',
    'terms_version_label' => 'เวอร์ชัน',
    'terms_effective_label' => 'วันที่มีผลบังคับ',
    'terms_updated_label' => 'อัปเดตล่าสุด',
    'terms_not_configured' => 'NOT_CONFIGURED',

    'terms_intro_title' => '1. ขอบเขตและตัวตนแพลตฟอร์ม',
    'terms_intro_text' => 'ข้อกำหนดเหล่านี้ครอบคลุมการใช้งานแพลตฟอร์มข้อมูลและเดิมพันลอตเตอรี่อิสระนี้ เราไม่ใช่สำนักงานสลากกินแบ่งรัฐบาล (GLO) ไม่ใช่ตัวแทนทางการ และไม่ใช่พอร์ทัลของรัฐบาล กฎรางวัลผลิตภัณฑ์ GLO อ้างอิงเพื่อความเข้ากันได้ของกฎรางวัลเท่านั้น',

    'terms_products_title' => '2. ผลิตภัณฑ์และกฎรางวัล',
    'terms_products_l6_text' => 'L6 ที่เข้ากันได้กับ GLO: ราคาสลาก {l6_price} บาท; รางวัลเต็มจำนวนขาย {l6_allocation} บาท จาก {l6_units} หน่วย; {l6_prize_count} รางวัลในตารางรางวัลเต็ม; รางวัลลดตามสัดส่วนเมื่อขายไม่เต็ม',
    'terms_products_n3_text' => 'N3 ที่เข้ากันได้กับ GLO: ราคาสลาก {n3_price} บาท; pool รางวัลคือ {n3_pool_rate} ของยอดขาย N3; การจ่ายผันแปรและคำนวณจากงวด — ไม่ใช่จำนวนเงินคงที่จากอดีต',
    'terms_products_separation_text' => 'ตลาดของผู้ดำเนินการ (3D, TOD, 2D, Run) เป็นผลิตภัณฑ์ของแพลตฟอร์ม ไม่เคยเรียกว่า GLO N3 หรือผลิตภัณฑ์ลอตเตอรีของรัฐบาล',

    'terms_stamp_title' => '3. ค่าธรรมเนียมแสตมป์และการคำนวณภาษี',
    'terms_stamp_text' => 'ค่าธรรมเนียมแสตมป์: {stamp_unit_baht} บาท ต่อทุก {stamp_divisor} บาทของรางวัลรวมหรือเศษ (ceil(gross/{stamp_divisor}) × {stamp_unit_baht} บาท) รางวัลภายใต้กฎนี้ไม่หักภาษีเงินได้ในหน้าคำนวณ แพลตฟอร์มนี้ไม่ใช้แบบหัก 0.5% หรือ 1%',

    'terms_accounts_title' => '4. บัญชีและการยืนยันตัวตน',
    'terms_accounts_one_text' => 'หนึ่งบัญชีต่อผู้ใช้ที่ยืนยันแล้วในที่ที่บังคับใช้: อีเมล ชื่อผู้ใช้ และเบอร์โทรศัพท์ไม่ซ้ำกันตอนลงทะเบียน',
    'terms_accounts_verification_text' => 'ข้อกำหนดการยืนยันตัวตนอาจแตกต่างกันตามบัญชี ผลิตภัณฑ์ และช่องทาง เราไม่สัญญาเส้นทางยืนยันคงที่สำหรับผู้ใช้ทุกคน',

    'terms_age_title' => '5. กฎด้านอายุ',
    'terms_age_registration_text' => 'ลงทะเบียน: คุณยืนยันว่าสามารถทำข้อผูกพันที่มีผลตามกฎหมายที่ใช้บังคับ และข้อมูลที่ให้ไว้ถูกต้อง วันเกิดอาจถูกเก็บเพื่อยืนยันตัวตนและใช้คำนวณอายุฝั่งเซิร์ฟเวอร์',
    'terms_age_purchase_text' => 'ซื้อ: สิทธิ์ซื้อหรือเดิมพันอาจถูกตรวจสอบตามกฎผลิตภัณฑ์ การตั้งค่าการเล่นอย่างรับผิดชอบ และสถานะบัญชีก่อนรับคำสั่ง',
    'terms_age_claim_text' => 'ขึ้นเงิน: ผู้ขึ้นเงินต้องมีอายุ {claim_min_age} ปีขึ้นไป คำนวณจากวันเกิดที่ยืนยันแล้วในระบบ — ไม่ใช่อายุที่ client ส่งมา',

    'terms_ticket_title' => '6. การครอบครองและการโอนสลาก',
    'terms_ticket_text' => 'เลขสลากเป็นสตริงตัวเลขที่คงเลขนำหน้าศูนย์ สำหรับรางวัล L6 ตามรูปแบบ GLO การขึ้นเงินเป็นไปตามเส้นทางผู้มีสิทธิ์ของผู้ถือที่ยืนยันแล้ว สลากไม่ใช่ตราสารที่โอนอิสระเพื่อการขึ้นเงินบนแพลตฟอร์มนี้',

    'terms_responsible_title' => '7. การเล่นอย่างรับผิดชอบ',
    'terms_responsible_text' => 'เมื่อเปิดใช้ในบัญชี คุณใช้ self-exclusion วงเงินฝาก/ขาดทุน และ reality checks ได้จากโปรไฟล์และตั้งค่าการเล่นอย่างรับผิดชอบ เครื่องมือเหล่านี้เป็นเครื่องมือคุ้มครองผู้เล่น ไม่ใช่การรับรองตามกฎระเบียบ',

    'terms_claim_title' => '8. การขึ้นเงินและการจ่าย',
    'terms_claim_text' => 'ไม่มีสัญญาการจ่ายทันที การขึ้นเงินอยู่ภายใต้การตรวจสอบผล ความเหมาะสม การระงับที่บังคับใช้ KYC เมื่อจำเป็น การคำนวณค่าธรรมเนียมแสตมป์ และช่องทางการจ่ายที่อนุมัติ ระยะเวลาขึ้นเงินคือ {claim_window_years} ปีจากงวดตามกฎที่กำหนดค่าซึ่งเข้ากันได้กับ GLO',

    'terms_disclaimer_title' => '9. ข้อจำกัดความรับผิดทางกฎหมาย',
    'terms_disclaimer_text' => 'นี่คือแพลตฟอร์มอิสระ การอ้างอิงสำนักงานสลากกินแบ่งรัฐบาลและกฎรางวัลทำเพื่อความเข้ากันได้และข้อมูลเท่านั้น เราไม่กล่าวอ้างการเป็นเจ้าของ ดำเนินการ รับรอง หรือสถานะตัวแทนของรัฐบาล ไม่มีหน้าใดบนไซต์นี้ที่ควรอ่านเป็นเว็บไซต์ GLO ทางการ',

    'terms_prohibited_title' => '10. พฤติกรรมที่ห้าม',
    'terms_prohibited_text' => 'การใช้ endpoint สาธารณะด้วยระบบอัตโนมัติ การพยายามเลี่ยง rate limit ข้อมูลยืนยันตัวตนหรือการขึ้นเงินที่ฉ้อโกง และการพยายามบิดเบือนผลที่สรุปแล้ว อาจส่งผลให้บัญชีถูกจำกัด',

    'terms_contact_title' => '11. ติดต่อและการเปลี่ยนแปลง',
    'terms_contact_text' => 'คำถามเกี่ยวกับข้อกำหนด: ใช้หน้าติดต่อ การเปลี่ยนแปลงสำคัญจะเผยแพร่เลขเวอร์ชันใหม่และวันที่มีผลบังคับใหม่บนหน้านี้ ฉบับประวัติไม่ถูกเขียนทับแบบเงียบ ๆ โดยไม่เพิ่มเวอร์ชัน',

    // ------------------------------------------------------------------ useful link labels
    'useful_links_results' => 'ผลรางวัลงวด',
    'useful_links_ticket_check' => 'ตรวจสลาก',
    'useful_links_sales_points' => 'จุดขาย',
    'useful_links_contact' => 'ติดต่อ',
    'useful_links_privacy' => 'นโยบายความเป็นส่วนตัว',
    'useful_links_terms' => 'ข้อกำหนดการใช้งาน',

    // ------------------------------------------------------------------ shared
    'not_configured' => 'NOT_CONFIGURED',
    'skip_to_content' => 'ข้ามไปยังเนื้อหาหลัก',
    'back_to_home' => 'กลับหน้าแรก',

    // ------------------------------------------------- PROMPT 3: member auth
    'login_meta_title' => 'เข้าสู่ระบบสมาชิก',
    'login_meta_description' => 'ลงชื่อเข้าใช้บัญชีสมาชิกของคุณ',
    'login_heading' => 'เข้าสู่ระบบสมาชิก',
    'login_lead' => 'ลงชื่อเข้าใช้บัญชีสมาชิกของคุณเพื่อดำเนินการต่อ',
    'login_identifier' => 'รหัสบัญชี หรือ อีเมลแอดเดรส',
    'login_identifier_placeholder' => 'รหัสบัญชี ชื่อผู้ใช้ หรืออีเมลแอดเดรส',
    'login_identifier_hint' => 'ใช้รหัสบัญชี ชื่อผู้ใช้ หรืออีเมลที่ลงทะเบียนไว้',
    'login_password' => 'รหัสผ่าน',
    'login_remember' => 'จดจำฉัน',
    'login_submit' => 'เข้าสู่ระบบ',
    'login_register_link' => 'สมัครสมาชิก',
    'login_forgot_link' => 'ลืมรหัสผ่าน',
    'captcha_label' => 'CAPTCHA',
    'captcha_placeholder' => 'คำตอบ',
    'captcha_hint' => 'แก้โจทย์ข้างต้นเพื่อยืนยันว่าคุณเป็นมนุษย์',
    'identifier_invalid' => 'กรุณาระบุรหัสบัญชี ชื่อผู้ใช้ หรืออีเมลที่ถูกต้อง',

    'register_meta_title' => 'สร้างบัญชีสมาชิก',
    'register_meta_description' => 'ลงทะเบียนบัญชีสมาชิกใหม่',
    'register_heading' => 'ลงทะเบียนสมาชิก',
    'register_lead' => 'สร้างบัญชีสมาชิกของคุณในไม่กี่ขั้นตอน',
    'register_section_account' => 'ข้อมูลบัญชี',
    'register_section_personal' => 'ข้อมูลส่วนตัว',
    'register_section_birth' => 'ข้อมูลการเกิด',
    'register_referral' => 'รหัสผู้แนะนำ',
    'register_mobile' => 'A.C. / เบอร์โทรศัพท์มือถือ',
    'register_mobile_hint' => 'ตัวเลขเท่านั้น 5-15 หลัก',
    'register_password' => 'รหัสผ่าน',
    'register_password_confirm' => 'ยืนยันรหัสผ่าน',
    'register_first_name' => 'ชื่อจริง',
    'register_last_name' => 'นามสกุล',
    'register_gender' => 'เพศ',
    'register_gender_male' => 'ชาย',
    'register_gender_female' => 'หญิง',
    'register_gender_unspecified' => 'ไม่ระบุ',
    'register_select' => 'กรุณาเลือก',
    'register_city' => 'เมือง',
    'register_country' => 'ประเทศ',
    'register_email' => 'อีเมลที่ใช้งาน',
    'register_dob' => 'วันเดือนปีเกิด',
    'register_nationality' => 'สัญชาติ',
    'register_terms' => 'ฉันได้อ่านและยอมรับข้อกำหนดและเงื่อนไข',
    'register_terms_hint' => 'การยอมรับของคุณจะถูกบันทึกพร้อมวันที่ในบัญชี',
    'register_submit' => 'สมัครสมาชิก',
    'register_back_to_login' => 'กลับไปหน้าเข้าสู่ระบบ',
    'register_welcome' => 'ยินดีต้อนรับ! บัญชีสมาชิกของคุณพร้อมใช้งานแล้ว',
    'register_error_email_taken' => 'อีเมลนี้ถูกลงทะเบียนแล้ว',
    'register_error_mobile_taken' => 'เบอร์มือถือนี้ถูกลงทะเบียนแล้ว',

    'reset_meta_title' => 'ตั้งรหัสผ่านใหม่',
    'reset_meta_description' => 'เลือกรหัสผ่านใหม่สำหรับบัญชีสมาชิกของคุณ',
    'forgot_meta_title' => 'กู้คืนรหัสผ่าน',
    'forgot_meta_description' => 'กู้คืนการเข้าถึงบัญชีสมาชิกของคุณ',
    'forgot_heading' => 'ลืมรหัสผ่าน',
    'forgot_lead' => 'กรอกข้อมูลบัญชีของคุณ แล้วเราจะส่งคำแนะนำในการกู้คืน',
    'forgot_identifier' => 'เลขบัญชี หรือ อีเมล',
    'forgot_identifier_placeholder' => 'เลขบัญชีหรืออีเมลแอดเดรส',
    'forgot_identifier_hint' => 'ใช้เลขบัญชี ชื่อผู้ใช้ หรืออีเมลที่ลงทะเบียนไว้',
    'forgot_submit' => 'ส่ง',
    'forgot_back_to_login' => 'กลับไปหน้าเข้าสู่ระบบ',
    'forgot_captcha_label' => 'CAPTCHA',
    'reset_requested' => 'หากบัญชีมีอยู่จริง ระบบได้ส่งคำแนะนำการกู้คืนรหัสผ่านแล้ว',
    'reset_new_password' => 'รหัสผ่านใหม่',
    'reset_confirm_password' => 'ยืนยันรหัสผ่านใหม่',
    'reset_password_hint' => 'อย่างน้อย 8 ตัวอักษร ประกอบด้วยตัวอักษรและตัวเลข',
    'reset_submit' => 'ตั้งรหัสผ่านใหม่',
    'reset_completed' => 'รหัสผ่านของคุณถูกตั้งใหม่แล้ว กรุณาเข้าสู่ระบบด้วยรหัสผ่านใหม่',
    'reset_invalid_token' => 'ลิงก์การตั้งรหัสผ่านนี้ไม่ถูกต้องหรือหมดอายุแล้ว',
    'reset_mail_subject' => 'กู้คืนรหัสผ่าน',
    'reset_mail_line1' => 'คุณได้รับอีเมลนี้เนื่องจากมีการร้องขอการกู้คืนรหัสผ่านสำหรับบัญชีของคุณ',
    'reset_mail_action' => 'ตั้งรหัสผ่านใหม่',
    'reset_mail_line2' => 'ลิงก์นี้จะหมดอายุใน :minutes นาที',
    'reset_mail_line3' => 'หากคุณไม่ได้ร้องขอการกู้คืนรหัสผ่าน ไม่ต้องดำเนินการใด ๆ',
    'logged_out' => 'คุณได้ออกจากระบบแล้ว',

    'password_required' => 'กรุณาระบุรหัสผ่าน',
    'password_min_length' => 'รหัสผ่านต้องมีอย่างน้อย :min ตัวอักษร',
    'password_invalid' => 'รหัสผ่านไม่ถูกต้อง',
    'password_letters_numbers' => 'รหัสผ่านต้องมีตัวอักษรและตัวเลขอย่างน้อยอย่างละหนึ่งตัว',
    'password_common' => 'รหัสผ่านนี้ใช้กันทั่วไปเกินไป กรุณาเลือกรหัสผ่านที่ปลอดภัยกว่านี้',

    // ------------------------------------------------------------------ privacy
    'privacy_meta_title' => 'นโยบายความเป็นส่วนตัว',
    'privacy_meta_description' => 'นโยบายความเป็นส่วนตัวฉบับระบุเวอร์ชัน อธิบายการประมวลผลข้อมูล ความปลอดภัย และสิทธิของผู้ใช้งาน',
    'privacy_title' => 'นโยบายความเป็นส่วนตัว',
    'privacy_version_label' => 'เวอร์ชัน',
    'privacy_effective_label' => 'วันที่มีผลบังคับ',
    'privacy_updated_label' => 'อัปเดตล่าสุด',
    'privacy_not_configured' => 'NOT_CONFIGURED',
    'privacy_data_title' => '1. ข้อมูลที่เราประมวลผล',
    'privacy_data_text' => 'เราประมวลผลข้อมูลการลงทะเบียน บัญชีกระเป๋าเงิน บันทึกธุรกรรม เอกสารยืนยันตัวตนตามข้อกำหนด และบันทึกความปลอดภัยทางเทคนิค โดยการตรวจรางวัลสาธารณะไม่มีการบันทึกข้อมูลส่วนบุคคล',
    'privacy_public_title' => '2. การเข้าถึงพื้นที่สาธารณะและไม่เปิดเผยตัวตน',
    'privacy_public_text' => 'หน้าแรก ผลการออกรางวัล ตรวจรางวัล และข้อมูลจุดจำหน่ายเปิดให้เข้าถึงได้โดยไม่ต้องระบุตัวตนและไม่มีการแสดงข้อมูลส่วนบุคคลของผู้ใช้อื่น',
    'privacy_security_title' => '3. ความปลอดภัยและการจัดเก็บข้อมูล',
    'privacy_security_text' => 'การส่งข้อมูลทั้งหมดได้รับการปกป้องด้วยการเข้ารหัส TLS ข้อมูลเอกสารยืนยันตัวตนจะถูกจัดเก็บอย่างปลอดภัยในระบบจัดเก็บส่วนตัวที่มีการควบคุมการเข้าถึงอย่างเข้มงวด',
    'privacy_rights_title' => '4. สิทธิของผู้เล่นและการยืนยันตัวตน',
    'privacy_rights_text' => 'ท่านสามารถตรวจสอบและแก้ไขข้อมูลโปรไฟล์ได้ที่แดชบอร์ดบัญชี และจัดการเอกสารยืนยันตัวตนตามกำหนดเวลาการเก็บรักษาของระบบ',
    'privacy_contact_title' => '5. การสอบถามและติดต่อ',
    'privacy_contact_text' => 'หากมีคำถามหรือข้อสงสัยเกี่ยวกับนโยบายความเป็นส่วนตัวนี้ สามารถติดต่อทีมสนับสนุนผ่านช่องทางการติดต่อที่กำหนด',

    // Public Pages 05-14 metadata and neutral headings.
    'fees_title' => 'ค่าธรรมเนียมของเรา',
    'fees_meta_title' => 'ค่าธรรมเนียมของเรา',
    'fees_meta_description' => 'ตารางค่าธรรมเนียมบริการแพลตฟอร์มจากแหล่งข้อมูลที่กำหนด หากยังไม่กำหนดจะแสดง NOT_CONFIGURED',
    'verification_title' => 'การยืนยันบัญชี',
    'verification_meta_title' => 'การยืนยันบัญชี',
    'verification_meta_description' => 'คู่มือสาธารณะเกี่ยวกับการยืนยันบัญชี การส่งเอกสารส่วนตัวยังคงต้องยืนยันตัวตนก่อน',
    'grade_title' => 'ระดับบัญชี',
    'grade_meta_title' => 'ระดับบัญชี',
    'grade_meta_description' => 'คำอธิบายระดับบัญชี เกณฑ์ยอดใช้จ่าย และส่วนลดเกมที่กำหนดไว้',
    'discount_meta_title' => 'ส่วนลดลอตเตอรี่',
    'discount_meta_description' => 'กฎส่วนลดที่เซิร์ฟเวอร์เป็นผู้คำนวณ ราคาสลากรัฐบาลยังคงได้รับการคุ้มครองตามที่กำหนด',
    'download_title' => 'ดาวน์โหลดแอป',
    'download_meta_title' => 'ดาวน์โหลดแอป',
    'download_meta_description' => 'ปลายทางดาวน์โหลดที่กำหนดค่าไว้สำหรับแพลตฟอร์ม จะไม่แสดงปลายทางที่ยังไม่พร้อม',


    'how_title' => 'วิธีการเล่น',
    'how_meta_title' => 'วิธีการเล่น',
    'how_meta_description' => 'คู่มือจากแหล่งข้อมูลของระบบสำหรับการใช้งาน การตรวจผล และขั้นตอนการขึ้นเงินที่กำหนดไว้',
    'how_disclaimer' => 'คู่มือนี้อธิบายขั้นตอนของแพลตฟอร์มเท่านั้น ไม่รับประกันการถูกรางวัล การจ่ายเงิน การอนุมัติ หรือผลลัพธ์ใด',
    'how_step_1_title' => 'เลือกผลิตภัณฑ์ที่รองรับ',
    'how_step_1_text' => 'อ่านกฎของผลิตภัณฑ์และเลือกเฉพาะตลาดที่เปิดให้บริการอยู่ในแพลตฟอร์ม',
    'how_step_2_title' => 'ตรวจสอบคำสั่งซื้อ',
    'how_step_2_text' => 'ตรวจรูปแบบตัวเลข ราคา งวดอ้างอิง และข้อจำกัดบัญชีหรือการเล่นอย่างรับผิดชอบก่อนซื้อ',
    'how_step_3_title' => 'ซื้อผ่านขั้นตอนบัญชี',
    'how_step_3_text' => 'ส่งคำสั่งผ่านขั้นตอนซื้อที่ต้องยืนยันตัวตน เซิร์ฟเวอร์จะคำนวณราคาสุดท้ายและไม่รับส่วนลดจาก client',
    'how_step_4_title' => 'ตรวจผลที่เผยแพร่',
    'how_step_4_text' => 'ใช้เครื่องมือตรวจผลเพื่อเปรียบเทียบสลากหรือคำสั่งกับแหล่งผลที่เผยแพร่ การตรวจไม่ใช่การรับรองสลากกระดาษ',
    'how_step_5_title' => 'ทำตามขั้นตอนการขึ้นเงิน',
    'how_step_5_text' => 'หากผลมีสิทธิ์ ให้ทำตามขั้นตอนการขึ้นเงินและการยืนยันที่กำหนด ไม่มีการรับประกันการจ่ายทันที',
    'faq_title' => 'คำถามที่พบบ่อย',
    'faq_meta_title' => 'คำถามที่พบบ่อย',
    'faq_meta_description' => 'คำตอบที่ค้นหาได้เกี่ยวกับบัญชี การยืนยัน ผลลัพธ์ ค่าธรรมเนียม การขึ้นเงิน และการใช้งานอย่างรับผิดชอบ',
    'faq_q_1' => 'ต้องมีบัญชีเพื่อดูหน้าสาธารณะหรือไม่',
    'faq_a_1' => 'สามารถดูข้อมูลสาธารณะและผลที่เผยแพร่ได้โดยไม่ต้องมีบัญชี ส่วนการดำเนินการกับบัญชีใช้กฎการยืนยันตัวตนและสิทธิ์',
    'faq_q_2' => 'การยืนยันบัญชีทำงานอย่างไร',
    'faq_a_2' => 'การยืนยันเป็นขั้นตอนในบัญชีที่ต้องเข้าสู่ระบบ คู่มือสาธารณะอธิบายประเภทเอกสารที่กำหนด เอกสารส่วนตัวต้องส่งในพื้นที่บัญชีเท่านั้น',
    'faq_q_3' => 'ส่วนลดคำนวณในเบราว์เซอร์หรือไม่',
    'faq_a_3' => 'ไม่ กฎส่วนลดและราคาซื้อคำนวณจากเซิร์ฟเวอร์ ค่าที่ส่งจากเบราว์เซอร์ไม่สามารถเปลี่ยนราคาสุดท้าย',
    'faq_q_4' => 'การตรวจสาธารณะรับรองสลากกระดาษได้หรือไม่',
    'faq_a_4' => 'ไม่ได้ การตรวจรายงานเฉพาะระเบียนดิจิทัลหรือผลที่เผยแพร่ ระบบไม่สามารถรับรองสลากกระดาษหรือสิทธิ์การถือครองแทนหน่วยงานผู้ออก',
    'faq_q_5' => 'ต้องการสอบถามฝ่ายสนับสนุนติดต่อที่ใด',
    'faq_a_5' => 'ใช้หน้าติดต่อ ซึ่งจะแสดงเฉพาะช่องทางที่กำหนดค่าไว้และแจ้งอย่างตรงไปตรงมาหากช่องทางส่งข้อความยังไม่พร้อม',

    // ------------------------------------------------------------------ ป้ายกำกับหน้าที่ 80 และ 82
    'privacy_home_aria' => 'หน้าหลัก',
    'privacy_brand_subtitle' => 'ข้อมูลความเป็นส่วนตัว',
    'privacy_primary_nav' => 'เมนูหลัก',
    'privacy_nav_home' => 'หน้าหลัก',
    'privacy_nav_about' => 'เกี่ยวกับเรา',
    'privacy_nav_vision' => 'วิสัยทัศน์',
    'privacy_nav_privacy' => 'ความเป็นส่วนตัว',
    'privacy_nav_terms' => 'ข้อกำหนด',
    'privacy_nav_contact' => 'ติดต่อ',
    'privacy_login' => 'เข้าสู่ระบบ',
    'privacy_dashboard' => 'แดชบอร์ด',
    'privacy_eyebrow' => '05 · การคุ้มครองข้อมูล',
    'privacy_public_note' => 'หน้านี้แสดงเฉพาะข้อความนโยบายสาธารณะที่อนุมัติแล้ว ไม่สร้างชื่อผู้ควบคุมข้อมูล หน่วยงานกำกับ ฐานกฎหมาย ระยะเวลาเก็บรักษา คุกกี้ หรือการรับรองขึ้นเอง',
    'privacy_metadata_aria' => 'ข้อมูลเมตานโยบายความเป็นส่วนตัว',
    'privacy_status_label' => 'สถานะ',
    'privacy_available' => 'พร้อมใช้งาน',
    'privacy_unavailable' => 'ไม่พร้อมใช้งาน',
    'privacy_unavailable_title' => 'เนื้อหาความเป็นส่วนตัวไม่พร้อมใช้งาน',
    'privacy_unavailable_body' => 'ไม่มีการแสดงข้อความนโยบายทดแทน',
    'privacy_contents_aria' => 'สารบัญความเป็นส่วนตัว',
    'privacy_document_map' => 'แผนผังเอกสาร',
    'privacy_section_fallback' => 'หัวข้อ',
    'privacy_search_label' => 'ค้นหานโยบายนี้',
    'privacy_search_placeholder' => 'ค้นหาข้อความความเป็นส่วนตัว',
    'privacy_clear' => 'ล้าง',
    'privacy_search_status' => 'แสดงทั้งหมด :count รายการ',
    'privacy_section_eyebrow' => 'หัวข้อความเป็นส่วนตัว',
    'privacy_data_questions' => 'คำถามเกี่ยวกับข้อมูล',
    'privacy_support_heading' => 'ใช้ช่องทางสนับสนุนที่กำหนดไว้',
    'privacy_support_body' => 'อย่าส่งเอกสารยืนยันตัวตนผ่าน endpoint สาธารณะที่ยังไม่ยืนยัน',
    'privacy_print_save' => 'พิมพ์ / บันทึก',
    'privacy_contact_support' => 'ติดต่อฝ่ายสนับสนุน',

    'download_home_aria' => 'หน้าหลัก',
    'download_brand_subtitle' => 'ปลายทางแอป',
    'download_primary_nav' => 'เมนูหลัก',
    'download_nav_home' => 'หน้าหลัก',
    'download_nav_download' => 'ดาวน์โหลด',
    'download_nav_how_to_play' => 'วิธีการเล่น',
    'download_nav_faq' => 'คำถามที่พบบ่อย',
    'download_nav_contact' => 'ติดต่อ',
    'download_login' => 'เข้าสู่ระบบ',
    'download_dashboard' => 'แดชบอร์ด',
    'download_eyebrow' => '14 · ปลายทางที่เชื่อถือได้',
    'download_public_note' => 'แสดงเฉพาะปลายทาง HTTPS ที่ตรวจสอบแล้ว ไม่สร้างแพ็กเกจ เวอร์ชัน checksum QR code ใบรับรองความปลอดภัย หรือรายการสโตร์ขึ้นเอง',
    'download_metadata_aria' => 'ข้อมูลเมตาดาวน์โหลด',
    'download_link_status' => 'สถานะลิงก์',
    'download_android' => 'Android',
    'download_ios' => 'iOS',
    'download_pwa' => 'เว็บแอปแบบติดตั้งได้',
    'download_ready' => 'พร้อมใช้งาน',
    'download_destination_map' => 'แผนผังปลายทาง',
    'download_destinations' => 'ปลายทาง',
    'download_safety_check' => 'ตรวจสอบความปลอดภัย',
    'download_integrity_status' => 'สถานะความถูกต้อง',
    'download_validated_links' => 'ลิงก์ที่ตรวจสอบแล้ว',
    'download_available_destinations' => 'ปลายทางที่พร้อมใช้งาน',
    'download_open_store' => 'เปิดสโตร์',
    'download_open_web_app' => 'เปิดเว็บแอป',
    'download_not_configured' => 'NOT_CONFIGURED',
    'download_empty_body' => 'ยังไม่ได้กำหนดปลายทางแอปที่ตรวจสอบแล้ว ให้ใช้เว็บแอปผ่านที่อยู่ไซต์ปัจจุบันต่อไป',
    'download_browser_safety' => 'ความปลอดภัยเบราว์เซอร์',
    'download_check_before_install' => 'ตรวจสอบก่อนติดตั้ง',
    'download_safety_body' => 'ยืนยันว่าปลายทางเป็นสโตร์หรือที่อยู่ไซต์ HTTPS ที่ถูกต้องก่อนกรอกข้อมูลเข้าสู่ระบบ หลีกเลี่ยงไฟล์จากข้อความที่ไม่ได้ร้องขอ',
    'download_safety_unknown_domain' => 'อย่าติดตั้งแพ็กเกจจากลิงก์ที่มีโดเมนไม่รู้จัก',
    'download_safety_credentials' => 'อย่ากรอกรหัสผ่านหรือข้อมูลการชำระเงินในหน้าที่เปิดจากข้อความที่ยังไม่ยืนยัน',
    'download_safety_account_route' => 'ใช้เส้นทางบัญชีที่ยืนยันตัวตนแล้วสำหรับการดำเนินการเกี่ยวกับบัญชี',
    'download_integrity_eyebrow' => 'สถานะความถูกต้อง',
    'download_build_verification' => 'การตรวจสอบบิลด์',
    'download_integrity_body' => 'การตรวจสอบ checksum และการติดตามดาวน์โหลดต้องมี manifest บิลด์สาธารณะที่ยืนยันแล้ว จะไม่กล่าวอ้างเมื่อยังไม่ได้กำหนด manifest',
    'download_checksum' => 'Checksum: NOT_CONFIGURED · การติดตาม: NOT_CONFIGURED',
    'download_no_link_eyebrow' => 'ไม่มีลิงก์ใช่ไหม',
    'download_support_heading' => 'ใช้ช่องทางสนับสนุนที่กำหนดไว้',
    'download_support_body' => 'อย่าขอแพ็กเกจแอปผ่านช่องทางที่ยังไม่ยืนยัน',
    'download_print_save' => 'พิมพ์ / บันทึก',
    'download_contact_support' => 'ติดต่อฝ่ายสนับสนุน',

];

```

## `lang/en/account_info.php`

# TYPE: PHP translation map
# PURPOSE: English Page 83 visible interface labels.

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Public account-programme explainers
|--------------------------------------------------------------------------
|
| Wording for the two signed-out pages: the grade ladder and the verification
| guide. Neither page shows anybody's data, and no string here names a
| document type by example, an identifier format or a sample image.
|
| NO FIGURE LIVES IN THIS FILE. Every threshold and rate on the ladder page
| comes from config/account_grades.php, which is the same source the live
| calculation uses. A number written here would be a second, drifting copy.
|
*/

return [
    'grades_meta_title' => 'Account grades and discounts',
    'grades_meta_description' => 'The account grade ladder: the 30-day spend each grade requires and the discount it carries on operator markets.',
    'grades_title' => 'Account grades',
    'grades_lead' => 'Grades are worked out from your qualifying spend over a rolling 30-day window. The highest grade you qualify for applies.',
    'grades_unavailable' => 'The grade programme is not configured yet.',

    'ladder_heading' => 'Grade ladder',
    'ladder_caption' => 'Each grade, the 30-day qualifying spend it needs, and the discount it carries.',
    'col_grade' => 'Grade',
    'col_min_spend' => '30-day minimum spend',
    'col_discount' => 'Discount',
    'col_scope' => 'Applies to',

    'scope_operator_markets' => 'Operator markets',
    'scope_all' => 'All products',

    'col_sl' => 'SL',
    'col_discount_of_game' => 'Discount Of Game',
    'sl_label' => 'Programme level :sl',
    'min_spend_a11y' => 'minimum qualifying spend over :days days',
    'discount_of_game' => 'Discount Of Game',
    'discount_of_game_title' => 'Games discounted for :grade',
    'discount_of_game_lead' => 'The :percent grade discount applies to these games.',
    'discount_of_game_empty' => 'No games are discounted at this grade.',
    'discount_of_game_close' => 'Close',

    // Stated on the page where the discount is advertised, so nobody infers a
    // reduction on a government-priced ticket.
    'discount_scope_note' => 'Grade discounts apply to operator markets only. GLO ticket prices are fixed and are never discounted.',
    'max_discount_note' => 'The combined discount is capped at :max.',
    'rule_version_note' => 'Grade rule version :version.',

    'verification_meta_title' => 'How account verification works',
    'verification_meta_description' => 'What account verification asks for, what the review checks and what happens afterwards.',
    'verification_title' => 'Account verification',
    'verification_lead' => 'Verification confirms that an account belongs to the person using it. Here is what the process involves before you start.',

    'steps_heading' => 'What happens',
    'step_register_title' => '1. Create and sign in to an account',
    'step_register_text' => 'Verification is attached to an account, so it starts from a signed-in session.',
    'step_submit_request_title' => '2. Send a verification request',
    'step_submit_request_text' => 'Enter your account details and submit the request from your account area.',
    'step_provide_documents_title' => '3. Provide the requested information',
    'step_provide_documents_text' => 'The request tells you which categories of information the review needs. Only what is asked for is required.',
    'step_review_title' => '4. Review',
    'step_review_text' => 'A reviewer checks the submission. This is a manual step, so it is not instant, and no timescale is promised here.',
    'step_outcome_title' => '5. Outcome',
    'step_outcome_text' => 'The result is recorded on your account. If something is missing you are told what, and you can submit again.',

    'verification_privacy_note' => 'Submitted information is used to review the account and nothing else. It is never shown on a public page.',
    'verification_cta' => 'Go to account verification',
    'verification_public_note' => 'This public guide never displays a user’s status, identity documents, address, or personal grade. Those values remain inside the authenticated account workflow.',
    'verification_guide_status' => 'Guide status',
    'verification_document_types' => 'Document types',
    'verification_max_upload' => 'Maximum upload',
    'verification_access' => 'Access',
    'verification_authenticated' => 'Authenticated',
    'verification_guide_map' => 'Guide map',
    'verification_documents' => 'Documents',
    'verification_workflow' => 'Authenticated workflow',
    'verification_private_policy' => 'Private document policy',
    'verification_configured_documents' => 'Configured document types',
    'verification_only_authenticated' => 'Only the authenticated account verification route accepts document uploads.',
    'verification_no_public_identity' => 'Never upload identity documents through Contact Us, public APIs, or an unverified third-party link.',
    'verification_private_workflow' => 'Private workflow',
    'verification_continue_title' => 'Continue in your account',
    'verification_continue_text' => 'Sign in to view or submit your own verification state. A public page cannot reveal it.',
    'verification_sign_in' => 'Sign in',
    'verification_open' => 'Open verification',
    'verification_contact' => 'Contact support',
    'verification_guide_unavailable' => 'Verification guide unavailable',
    'verification_no_steps' => 'No approved guide steps are configured.',
    'verification_eyebrow' => '07 · ACCOUNT VERIFICATION',
    'verification_brand_subtitle' => 'ACCOUNT SECURITY',
    'verification_nav_home' => 'HOME',
    'verification_nav_about' => 'ABOUT US',
    'verification_nav_verification' => 'VERIFICATION',
    'verification_nav_grades' => 'GRADES',
    'verification_nav_contact' => 'CONTACT',
    'verification_login' => 'LOGIN',
    'verification_my_verification' => 'MY VERIFICATION',
    'verification_contents' => 'Verification contents',
    'verification_home_aria' => 'Home',
    'verification_primary_nav' => 'Primary navigation',
    'verification_document_marker' => 'ID',
    // ------------------------------------------------------------------ Page 83 interface labels
    'grade_home_aria' => 'Home',
    'grade_brand_subtitle' => 'Account grade ladder',
    'grade_primary_nav' => 'Primary navigation',
    'grade_nav_home' => 'Home',
    'grade_nav_about' => 'About us',
    'grade_nav_verification' => 'Verification',
    'grade_nav_grades' => 'Grades',
    'grade_nav_contact' => 'Contact',
    'grade_login' => 'Login',
    'grade_my_grade' => 'My grade',
    'grade_eyebrow' => '08 · Member programme',
    'grade_public_note' => 'This catalogue explains the rules only. It does not reveal a user’s spend, current grade, history, eligibility, or personal discount.',
    'grade_metadata_aria' => 'Grade programme metadata',
    'grade_rule_version' => 'Rule version',
    'grade_review_period' => 'Review period',
    'grade_currency' => 'Currency',
    'grade_public_tiers' => 'Public tiers',
    'grade_days' => ':days days',
    'grade_ladder_summary' => 'Public ladder summary',
    'grade_ladder_map' => 'Ladder map',
    'grade_tier_fallback' => 'Tier',
    'grade_configured_tier' => 'Configured tier',
    'grade_minimum_spend' => 'Minimum spend',
    'grade_published_discount' => 'Published discount',
    'grade_scope' => 'Scope',
    'grade_eligible_games' => 'Eligible configured games: :count.',
    'grade_game_matrix_eyebrow' => 'Discount of game',
    'grade_game_matrix_title' => 'Discount of game',
    'grade_private_eyebrow' => 'Account-only information',
    'grade_private_title' => 'Your grade is private',
    'grade_private_body' => 'Personal spend totals, current grade, history, and any individual entitlement are available only after authentication through the Account Grade workflow.',
    'grade_private_note' => 'The public ladder is not a personal eligibility decision.',
    'grade_private_account' => 'Private account state',
    'grade_private_marker' => 'Me',
    'grade_unavailable_title' => 'Grade ladder unavailable',
    'grade_unavailable_body' => 'No public grade tiers are currently configured.',
    'grade_check_account' => 'Check your account',
    'grade_open_authenticated' => 'Open the authenticated view',
    'grade_authenticated_body' => 'Sign in to see your own account information. Never submit credentials to a public checker.',
    'grade_sign_in' => 'Sign in',
    'grade_open_my_grade' => 'Open my grade',
    'grade_contact_support' => 'Contact support',

];

```

## `lang/th/account_info.php`

# TYPE: PHP translation map
# PURPOSE: Thai-locale Page 83 key and placeholder parity.

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Public account-programme explainers — Thai
|--------------------------------------------------------------------------
|
| Exact key parity with lang/en/account_info.php, asserted by a test.
|
*/

return [
    'grades_meta_title' => 'ระดับบัญชีและส่วนลด',
    'grades_meta_description' => 'ตารางระดับบัญชี: ยอดใช้จ่าย 30 วันที่แต่ละระดับต้องการ และส่วนลดที่ได้รับในตลาดของผู้ให้บริการ',
    'grades_title' => 'ระดับบัญชี',
    'grades_lead' => 'ระดับคำนวณจากยอดใช้จ่ายที่เข้าเงื่อนไขในรอบ 30 วันล่าสุด โดยใช้ระดับสูงสุดที่คุณผ่านเกณฑ์',
    'grades_unavailable' => 'ยังไม่ได้ตั้งค่าโปรแกรมระดับบัญชี',

    'ladder_heading' => 'ตารางระดับ',
    'ladder_caption' => 'แต่ละระดับ ยอดใช้จ่าย 30 วันที่ต้องการ และส่วนลดที่ได้รับ',
    'col_grade' => 'ระดับ',
    'col_min_spend' => 'ยอดใช้จ่ายขั้นต่ำ 30 วัน',
    'col_discount' => 'ส่วนลด',
    'col_sl' => 'SL',
    'col_discount_of_game' => 'ส่วนลดเกม',
    'sl_label' => 'ระดับที่ :sl',
    'min_spend_a11y' => 'ยอดใช้จ่ายขั้นต่ำใน :days วัน',
    'discount_of_game' => 'ส่วนลดเกม',
    'discount_of_game_title' => 'เกมที่ได้ส่วนลดสำหรับ :grade',
    'discount_of_game_lead' => 'ส่วนลดระดับ :percent ใช้กับเกมต่อไปนี้',
    'discount_of_game_empty' => 'ยังไม่มีเกมที่ได้ส่วนลดในระดับนี้',
    'discount_of_game_close' => 'ปิด',

    'col_scope' => 'ใช้กับ',

    'scope_operator_markets' => 'ตลาดของผู้ให้บริการ',
    'scope_all' => 'ทุกผลิตภัณฑ์',

    'discount_scope_note' => 'ส่วนลดตามระดับใช้กับตลาดของผู้ให้บริการเท่านั้น ราคาสลาก GLO เป็นราคาคงที่และไม่มีส่วนลด',
    'max_discount_note' => 'ส่วนลดรวมสูงสุดไม่เกิน :max',
    'rule_version_note' => 'กฎระดับเวอร์ชัน :version',

    'verification_meta_title' => 'การยืนยันบัญชีทำงานอย่างไร',
    'verification_meta_description' => 'การยืนยันบัญชีต้องใช้อะไร ตรวจสอบอะไร และหลังจากนั้นเกิดอะไรขึ้น',
    'verification_title' => 'การยืนยันบัญชี',
    'verification_lead' => 'การยืนยันบัญชีคือการยืนยันว่าบัญชีเป็นของผู้ใช้จริง นี่คือขั้นตอนก่อนเริ่มต้น',

    'steps_heading' => 'ขั้นตอน',
    'step_register_title' => '1. สร้างบัญชีและเข้าสู่ระบบ',
    'step_register_text' => 'การยืนยันผูกกับบัญชี จึงเริ่มจากการเข้าสู่ระบบ',
    'step_submit_request_title' => '2. ส่งคำขอยืนยัน',
    'step_submit_request_text' => 'กรอกรายละเอียดบัญชีและส่งคำขอจากพื้นที่บัญชีของคุณ',
    'step_provide_documents_title' => '3. ให้ข้อมูลตามที่ระบบร้องขอ',
    'step_provide_documents_text' => 'คำขอจะระบุประเภทข้อมูลที่การตรวจสอบต้องใช้ ให้เฉพาะที่ร้องขอเท่านั้น',
    'step_review_title' => '4. การตรวจสอบ',
    'step_review_text' => 'เจ้าหน้าที่ตรวจสอบข้อมูลที่ส่งมา ขั้นตอนนี้เป็นการตรวจโดยคน จึงไม่ใช่ทันที และไม่มีการรับประกันระยะเวลา',
    'step_outcome_title' => '5. ผลลัพธ์',
    'step_outcome_text' => 'ผลจะถูกบันทึกไว้ในบัญชีของคุณ หากข้อมูลไม่ครบ ระบบจะแจ้งว่าขาดอะไร และคุณส่งใหม่ได้',

    'verification_privacy_note' => 'ข้อมูลที่ส่งมาใช้เพื่อตรวจสอบบัญชีเท่านั้น และจะไม่ถูกแสดงบนหน้าสาธารณะ',
    'verification_cta' => 'ไปที่หน้ายืนยันบัญชี',
    'verification_public_note' => 'คู่มือสาธารณะนี้ไม่แสดงสถานะ เอกสารยืนยันตัวตน ที่อยู่ หรือระดับบัญชีของผู้ใช้ ข้อมูลเหล่านี้อยู่เฉพาะในขั้นตอนบัญชีที่ยืนยันตัวตนแล้ว',
    'verification_guide_status' => 'สถานะคู่มือ',
    'verification_document_types' => 'ประเภทเอกสาร',
    'verification_max_upload' => 'ขนาดอัปโหลดสูงสุด',
    'verification_access' => 'การเข้าถึง',
    'verification_authenticated' => 'ยืนยันตัวตนแล้ว',
    'verification_guide_map' => 'แผนผังคู่มือ',
    'verification_documents' => 'เอกสาร',
    'verification_workflow' => 'ขั้นตอนที่ยืนยันตัวตนแล้ว',
    'verification_private_policy' => 'นโยบายเอกสารส่วนตัว',
    'verification_configured_documents' => 'ประเภทเอกสารที่ตั้งค่าไว้',
    'verification_only_authenticated' => 'เฉพาะเส้นทางยืนยันบัญชีที่เข้าสู่ระบบแล้วเท่านั้นที่รับการอัปโหลดเอกสาร',
    'verification_no_public_identity' => 'อย่าอัปโหลดเอกสารยืนยันตัวตนผ่านติดต่อเรา API สาธารณะ หรือลิงก์บุคคลที่สามที่ยังไม่ยืนยัน',
    'verification_private_workflow' => 'ขั้นตอนส่วนตัว',
    'verification_continue_title' => 'ดำเนินการต่อในบัญชีของคุณ',
    'verification_continue_text' => 'เข้าสู่ระบบเพื่อดูหรือส่งสถานะการยืนยันของคุณ หน้าสาธารณะไม่สามารถเปิดเผยข้อมูลนี้ได้',
    'verification_sign_in' => 'เข้าสู่ระบบ',
    'verification_open' => 'เปิดการยืนยันบัญชี',
    'verification_contact' => 'ติดต่อฝ่ายสนับสนุน',
    'verification_guide_unavailable' => 'ไม่สามารถใช้คู่มือการยืนยันได้',
    'verification_no_steps' => 'ยังไม่ได้ตั้งค่าขั้นตอนคู่มือที่อนุมัติ',
    'verification_eyebrow' => '07 · การยืนยันบัญชี',
    'verification_brand_subtitle' => 'ความปลอดภัยบัญชี',
    'verification_nav_home' => 'หน้าหลัก',
    'verification_nav_about' => 'เกี่ยวกับเรา',
    'verification_nav_verification' => 'การยืนยัน',
    'verification_nav_grades' => 'ระดับบัญชี',
    'verification_nav_contact' => 'ติดต่อ',
    'verification_login' => 'เข้าสู่ระบบ',
    'verification_my_verification' => 'การยืนยันของฉัน',
    'verification_contents' => 'เนื้อหาการยืนยัน',
    'verification_home_aria' => 'หน้าหลัก',
    'verification_primary_nav' => 'เมนูหลัก',
    'verification_document_marker' => 'เอกสาร',
    // ------------------------------------------------------------------ ป้ายกำกับหน้าที่ 83
    'grade_home_aria' => 'หน้าหลัก',
    'grade_brand_subtitle' => 'ตารางระดับบัญชี',
    'grade_primary_nav' => 'เมนูหลัก',
    'grade_nav_home' => 'หน้าหลัก',
    'grade_nav_about' => 'เกี่ยวกับเรา',
    'grade_nav_verification' => 'การยืนยัน',
    'grade_nav_grades' => 'ระดับบัญชี',
    'grade_nav_contact' => 'ติดต่อ',
    'grade_login' => 'เข้าสู่ระบบ',
    'grade_my_grade' => 'ระดับของฉัน',
    'grade_eyebrow' => '08 · โปรแกรมสมาชิก',
    'grade_public_note' => 'แคตตาล็อกนี้อธิบายเฉพาะกฎ ไม่เปิดเผยยอดใช้จ่าย ระดับปัจจุบัน ประวัติ สิทธิ์ หรือส่วนลดส่วนบุคคลของผู้ใช้',
    'grade_metadata_aria' => 'ข้อมูลเมตาโปรแกรมระดับบัญชี',
    'grade_rule_version' => 'เวอร์ชันกฎ',
    'grade_review_period' => 'รอบทบทวน',
    'grade_currency' => 'สกุลเงิน',
    'grade_public_tiers' => 'ระดับสาธารณะ',
    'grade_days' => ':days วัน',
    'grade_ladder_summary' => 'สรุปตารางระดับสาธารณะ',
    'grade_ladder_map' => 'แผนผังระดับ',
    'grade_tier_fallback' => 'ระดับ',
    'grade_configured_tier' => 'ระดับที่กำหนดค่าแล้ว',
    'grade_minimum_spend' => 'ยอดใช้จ่ายขั้นต่ำ',
    'grade_published_discount' => 'ส่วนลดที่เผยแพร่',
    'grade_scope' => 'ขอบเขต',
    'grade_eligible_games' => 'เกมที่เข้าเงื่อนไขตามการตั้งค่า: :count รายการ',
    'grade_game_matrix_eyebrow' => 'ส่วนลดเกม',
    'grade_game_matrix_title' => 'ส่วนลดเกม',
    'grade_private_eyebrow' => 'ข้อมูลเฉพาะบัญชี',
    'grade_private_title' => 'ระดับของคุณเป็นข้อมูลส่วนตัว',
    'grade_private_body' => 'ยอดใช้จ่ายส่วนบุคคล ระดับปัจจุบัน ประวัติ และสิทธิ์เฉพาะบุคคลจะดูได้หลังยืนยันตัวตนผ่านขั้นตอนระดับบัญชีเท่านั้น',
    'grade_private_note' => 'ตารางระดับสาธารณะไม่ใช่การตัดสินสิทธิ์ส่วนบุคคล',
    'grade_private_account' => 'สถานะบัญชีส่วนตัว',
    'grade_private_marker' => 'ฉัน',
    'grade_unavailable_title' => 'ตารางระดับไม่พร้อมใช้งาน',
    'grade_unavailable_body' => 'ยังไม่ได้กำหนดระดับบัญชีสาธารณะ',
    'grade_check_account' => 'ตรวจสอบบัญชีของคุณ',
    'grade_open_authenticated' => 'เปิดมุมมองที่ยืนยันตัวตนแล้ว',
    'grade_authenticated_body' => 'เข้าสู่ระบบเพื่อดูข้อมูลบัญชีของคุณ อย่าส่งข้อมูลเข้าสู่ระบบให้เครื่องมือตรวจสอบสาธารณะ',
    'grade_sign_in' => 'เข้าสู่ระบบ',
    'grade_open_my_grade' => 'เปิดระดับของฉัน',
    'grade_contact_support' => 'ติดต่อฝ่ายสนับสนุน',

];

```

## `tests/Feature/Pages77To100StaticContractTest.php`

# TYPE: PHPUnit feature/static test
# PURPOSE: Static route, no-fabrication, exact-money, payment read-only, translation parity, and audit-row checks.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Static contract checks for the Pages 77–100 hardening pass.
 *
 * These assertions deliberately do not require a database. Runtime, route
 * dispatch, Blade compilation, browser, and provider gates remain separate
 * and are reported as unavailable when the Laravel runtime is unavailable.
 */
final class Pages77To100StaticContractTest extends TestCase
{
    public function test_admin_route_group_has_authentication_and_admin_gate(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        self::assertIsString($routes);
        self::assertStringContainsString("Route::prefix('admin')->name('admin.')->middleware(['admin.auth', 'can:access-admin'])->group", $routes);
        self::assertStringContainsString("Route::post('/api/reconciliation'", $routes);
        self::assertStringContainsString("LottoFinExecutiveDashboardController::class, 'unsupportedMutation'", $routes);
        self::assertStringContainsString("/kyc/{documentToken}/download", $routes);
        self::assertStringNotContainsString("/kyc/{id}/download", $routes);
    }

    public function test_requested_public_payment_and_admin_route_contracts_are_present(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));

        self::assertIsString($routes);
        foreach ([
            "Route::get('/results'",
            "Route::get('/check'",
            "Route::get('/sales-points'",
            "Route::get('/privacy'",
            "Route::get('/contact'",
            "Route::get('/download'",
            "Route::get('/download-app'",
            "Route::get('/app'",
            "Route::get('/account-grades'",
            "Route::get('/account-grade'",
            "Route::get('/account-verification'",
            "Route::get('/account-verification-guide'",
        ] as $route) {
            self::assertStringContainsString($route, $routes);
        }

        self::assertStringContainsString("Route::middleware('auth')->group(function (): void {", $routes);
        self::assertStringContainsString("\$callbackPath('success_url', '/payment/success')", $routes);
        self::assertStringContainsString("\$callbackPath('failure_url', '/payment/failure')", $routes);
        self::assertStringContainsString("\$callbackPath('cancel_url', '/payment/cancel')", $routes);
        self::assertStringContainsString("\$callbackPath('pending_url', '/payment/pending')", $routes);
    }

    public function test_results_controller_and_admin_dashboard_contain_no_known_fixture_values(): void
    {
        $results = file_get_contents(base_path('app/Http/Controllers/GloResultsPageController.php'));
        $dashboard = file_get_contents(base_path('app/Http/Controllers/Admin/LottoFinExecutiveDashboardController.php'));
        $view = file_get_contents(base_path('resources/views/admin/dashboard.blade.php'));

        self::assertIsString($results);
        self::assertIsString($dashboard);
        self::assertIsString($view);

        foreach (['724605', '482963', '7419', '9361', '5824', '52938', '1420500', '348200', '4821'] as $fixture) {
            self::assertStringNotContainsString($fixture, $results.$dashboard.$view);
        }
        self::assertStringNotContainsString('number_format((float)', $dashboard);
    }

    public function test_payment_browser_return_lane_is_read_only_and_reference_bounded(): void
    {
        $service = file_get_contents(base_path('app/Services/Payment/PaymentCallbackService.php'));
        $controller = file_get_contents(base_path('app/Http/Controllers/Web/PaymentCallbackController.php'));

        self::assertIsString($service);
        self::assertIsString($controller);
        self::assertStringContainsString('browserReturnProjection', $service);
        self::assertStringContainsString('safeReference', $service);
        self::assertStringContainsString('viewerId: $request->user()?->id', $controller);
        self::assertStringNotContainsString('->save()', substr($controller, strpos($controller, 'private function render')));
    }

    public function test_results_and_admin_translation_maps_have_exact_recursive_key_parity(): void
    {
        foreach (['results', 'admin', 'public_pages', 'account_info'] as $file) {
            $english = require base_path('lang/en/'.$file.'.php');
            $thai = require base_path('lang/th/'.$file.'.php');

            self::assertSame(self::keys($english), self::keys($thai), $file.' translation key parity failed.');
        }
    }

    public function test_audit_matrix_has_one_row_for_each_page_77_through_100(): void
    {
        $audit = file_get_contents(base_path('audit.md'));

        self::assertIsString($audit);
        for ($page = 77; $page <= 100; $page++) {
            self::assertMatchesRegularExpression('/\| '.$page.' \|/', $audit);
        }
        self::assertStringContainsString('NOT VERIFIED — RUNTIME UNAVAILABLE', $audit);
    }

    /**
     * @param array<mixed> $value
     * @return list<string>
     */
    private static function keys(array $value, string $prefix = ''): array
    {
        $keys = [];
        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys[] = $path;
            if (is_array($child)) {
                $keys = array_merge($keys, self::keys($child, $path));
            }
        }

        sort($keys);

        return $keys;
    }
}

```

## `audit.md`

# TYPE: Markdown audit report
# PURPOSE: Pages 77–100 matrix, validation evidence, and changed-file manifest.

```text
# Pages 44–70 Implementation and Hardening Audit

**Audit date:** 2026-09-30
**Local timezone:** Asia/Dhaka
**Scope:** GLO L6 Pages 44–50 and authenticated member Pages 51–70
**Runtime status:** `NOT VERIFIED — RUNTIME UNAVAILABLE`
**Production readiness:** Not declared

## Evidence boundary

The repository has no PHP interpreter, Composer vendor directory, Laravel application runtime, database connection, browser runner, or configured external payment provider in this workspace. PHP files were parsed with the installed JavaScript `php-parser` package as a static syntax aid. This is not a Laravel boot, dependency-resolution, migration, route-list, Blade compilation, database, browser, payment-provider, or production verification.

The final frontend asset build was executed after adding the existing React component dependencies required by the repository's Vite entry graph:

```text
npm run build
vite v5.4.21 building for production
✓ 168 modules transformed.
✓ built in 3.92s
```

`npm ci`/`npm install` reported two dependency audit findings: one moderate and one high. No automatic force upgrade was applied.

The first asset-build attempt failed because `react` was not resolvable from `resources/js/components/WalletManagement.tsx`. `react` and `react-dom` were added to `package.json` and `package-lock.json`; the subsequent build passed. Generated `public/build` output is excluded from the persisted workspace snapshot.

## Acceptance decision

The implementation is not production-ready. The exact runtime status is:

```text
NOT VERIFIED — RUNTIME UNAVAILABLE
```

This status applies to runtime behavior, authentication, authorization, CSRF, throttling, CAPTCHA, database ownership, payment initiation, gateway callbacks, wallet reservation, ledger posting, responsible-gaming enforcement, KYC gates, grade evaluation, accessibility behavior, responsive browser behavior, route listing, Blade compilation, Laravel service-container resolution, migrations, and automated PHP tests.

## Page matrix

| Page | Route | Controller and canonical source | Financial or identity behavior | Status and finding |
|---|---|---|---|---|
| 44 | `glo-l6.index` | `GloL6Controller::index`; `GloL6HomeService`, `GloPublicHomeService`, purchase capability service | Read-only canonical projections. No purchase mutation. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Existing page retained and hardened; no duplicate home was created. |
| 45 | `glo-l6.buy` | `GloL6Controller::buy`; `GloL6PurchaseCapabilityService` | Purchase remains disabled with `NOT_CONFIGURED`; no price, selection, wallet, ticket, ledger, or idempotency mutation is advertised. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Fail-closed behavior is statically present. |
| 46 | `glo-l6.latest` | `GloL6Controller::latestResult`; `GloPublicResultService` | Published result projection only; unavailable source returns an unavailable state. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. No fabricated result values were added. |
| 47 | `glo-l6.history` | `GloL6Controller::history`; bounded canonical history query | Read-only paginated result rows and provenance state. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. History is bounded by configured window and page size. |
| 48 | `glo-l6.year` | `GloL6Controller::year`; canonical history service | Year is accepted only inside configured history window and route is constrained to four digits. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Runtime boundary and data query remain unverified. |
| 49 | `glo-l6.draw` | `GloL6Controller::drawDetail`; canonical draw/result projection | Read-only draw detail. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Collision-safe route pattern is present. |
| 50 | `glo-l6.result` | `GloL6Controller::resultDetail`; canonical result projection | Read-only result detail with provenance. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. No result is claimed when the source is unavailable. |
| 51 | `login` | `MemberAuthController`; canonical login service | Session authentication, CAPTCHA/throttle contract remains delegated to existing auth architecture. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. No duplicate auth surface created. |
| 52 | `register` | `MemberAuthController`; canonical registration service | Authenticated identity is created only through existing registration flow. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Runtime and CAPTCHA gates not executable. |
| 53 | `password.request` | `MemberAuthController`; canonical password-reset request service | Reset-token flow remains canonical and throttled. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Token security and mail delivery not runtime-tested. |
| 54 | `password.reset` | `MemberAuthController`; canonical password-reset service | Token-gated reset remains canonical. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Runtime not available. |
| 55 | `player.dashboard` | `PlayerWebController::dashboard`; `User`, `Wallet`, `Draw`, `Bet`, `FinancialTransaction` | Owner-scoped records only. Exact `Money` formatting is used for wallet and wager amounts. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. No fabricated player, wallet, draw, or wager rows are inserted by the page. |
| 56 | `player.draws` | `PlayerWebController::draws`; `Draw` and result relations | Real draw schedule and published result fields. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Draw fields and pagination require Laravel runtime verification. |
| 57 | `player.draws.detail` | `PlayerWebController::drawDetail`; owner-independent public draw read model | Real draw/result relation. Missing result displays a translated pending state. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Runtime and view compilation unverified. |
| 58 | `player.bet` and `player.bets.purchase` | `PlayerWebController::betSlip`, `BetPurchaseController`; `BulkBetService` | Purchase submits a public draw reference, resolves the canonical draw server-side, validates decimal stakes without floating-point parsing, and delegates to the canonical bulk betting service. The endpoint does not fabricate a success response when all items are refused. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Complete product, price, wallet, reservation, ledger, RG, and idempotency contract is not runtime-verified. |
| 59 | `player.bets` | `PlayerWebController::bets`; owner-scoped `Bet` query | Uses authenticated user ownership and canonical ticket/draw/item relations. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Presentation no longer invents ticket or draw references. |
| 60 | `player.wallet` | `PlayerWebController::wallet`; `Wallet`, `FinancialTransaction`, `Money` | Owner-scoped wallet and transaction journal. Decimal aggregates are reduced through `Money` rather than a floating-point PHP aggregate. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Database and ledger state not executable. |
| 61 | `player.deposit`, `player.deposit.store` | `PlayerWebController`; `PaymentInitiationService` and its canonical `DepositService::request` orchestration | Gateway-capable configured methods only. Deposit initiation now calls `PaymentInitiationService::initiateDeposit(Wallet, Money, PaymentMethod, key, options)` using the configured finance currency, exact decimal validation, and a constrained idempotency key. Wallet credit still requires canonical callback/completion. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Provider capability, gateway callback, and transaction behavior remain unverified. |
| 62 | `player.deposit.status` | `PlayerWebController::depositStatus`; owner-scoped `Deposit` query | Reads only the authenticated owner's deposit by reference or UUID. Status view distinguishes pending/provider state from wallet credit. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. New route/view is statically present; runtime ownership and model resolution are unverified. |
| 63 | `player.withdraw`, `player.withdraw.store` | `PlayerWebController`; canonical `WithdrawalService` and `WalletHoldService` | Gateway-capable payout methods only. Exact configured-currency validation and available-balance arithmetic use `Money`. Requests remain pending without a browser-side hold; canonical approval owns reservation and downstream payout/ledger transitions. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. KYC, RG, balance, hold, approval, payout, and ledger behavior remain unverified. |
| 64 | `player.withdrawal.status` | `PlayerWebController::withdrawalStatus`; owner-scoped `Withdrawal` query | Reads only the authenticated owner's request and does not expose encrypted payout details. Recent history links to the owner-scoped status route. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. New route/view is statically present; runtime not available. |
| 65 | `player.profile` | `PlayerWebController`; authenticated `User` and responsible-gaming limit record | Profile update derives ownership from session and preserves password and responsible-gaming routes. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. User model, validation and CSRF are not runtime-tested. |
| 66 | `player.security`, `player.settings` | `PlayerSecuritySettingsController`; canonical account verification service, security session records, responsible-gaming service | KYC status is read through `AccountVerificationService::publicStatus`; active sessions are owner-scoped, active, and unexpired; self-exclusion reads the canonical self-exclusion service. Unsupported compatibility mutations return `NOT_CONFIGURED`. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Container resolution and security-session schema are not executable. |
| 67 | `settings.index`, `member.settings`, `player.settings.portal` | `PlayerWebController::responsibleGaming`; canonical responsible-gaming and self-exclusion services | Limit updates use canonical responsible-gaming service. Self-exclusion now requests and activates a canonical `SelfExclusion` record and stamps the legacy limit lane through the existing engine. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Server clock, database transition, and enforcement gates are unverified. |
| 68 | `account.verification` | `MemberAccountVerificationController`; canonical `AccountVerificationService`, private document services, and opaque owner-scoped download tokens | Owner-scoped KYC status and document metadata; internal user/document numeric IDs are not rendered or placed in download URLs; document downloads remain owner-authorized and private. The retired duplicate root controller, alias, and view were removed from the active architecture. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. No private document or KYC runtime test can run. |
| 69 | `account.grade` | `AccountGradeController`; `AccountGradeService`, evaluator and discount projection | Uses server-computed grade, qualifying spend, entitlement projection, and canonical history. Monetary spend is formatted with `Money`; no hardcoded ticket price fallback remains in the view. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Grade calculations and database snapshots are unverified. |
| 70 | `account.grade.history` | `AccountGradeController::history`; canonical `AccountGradeService::history` | Browser request renders the authenticated user's canonical history view; JSON clients retain the JSON response when `expectsJson()` is true. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Runtime, route, and JSON negotiation not executable. |

## Finance and responsible-gaming findings

1. The Page 61 defect was corrected. `PlayerWebController::storeDeposit()` no longer calls the nonexistent `DepositService::initiate()` method. It now calls the inspected canonical `PaymentInitiationService::initiateDeposit()` contract and reads its array return values.
2. The deposit flow does not treat a redirect, provider reference, pending state, or manual instruction as proof of wallet credit. The wallet changes only through the canonical completion/callback path.
3. Deposit and withdrawal payment-method projections reject enum values without a configured, enabled, capability-backed gateway. Unsupported configured methods are not advertised.
4. Withdrawal balance display and configured-currency amount validation use exact `Money` arithmetic; the withdrawal form has no fabricated monetary default and no duplicate browser-side reservation.
5. The account-verification surface no longer exposes internal numeric user/document IDs. Owner download URLs use opaque HMAC tokens and the controller resolves them only within the authenticated owner scope.
6. Player self-exclusion was aligned to the canonical `Compliance\SelfExclusionService` bridge and `ResponsibleGaming\SelfExclusionService` engine. The security/API, settings compatibility, and browser form paths now use `SelfExclusionData`, request the canonical row, and activate it through the engine.
7. Unsupported settings mutations remain fail-closed with `NOT_CONFIGURED`; no MFA, notification, LINE, PIN, or security preference mutation claims success without an inspected backend contract.
8. Pages 62 and 64 are owner-scoped status views. They do not reveal another user's records and do not expose encrypted withdrawal payout details.
9. Public GLO L6 purchase remains `NOT_CONFIGURED`; no checkout, wallet debit, ticket issuance, reservation, or ledger mutation was invented.

## Localization and UI checks

| Resource | EN keys | TH keys | Result |
|---|---:|---:|---|
| `lang/en/player.php` / `lang/th/player.php` | 277 | 277 | Exact key and placeholder parity confirmed by a repository script. |
| `lang/en/glo_l6.php` / `lang/th/glo_l6.php` | 107 | 107 | Exact key and placeholder parity confirmed by a repository script. |
| `lang/en/account_services.php` / `lang/th/account_services.php` | 207 | 207 | Exact key and placeholder parity confirmed by a repository script. |
| `lang/en/account_info.php` / `lang/th/account_info.php` | 76 | 76 | Exact key and placeholder parity confirmed by a repository script. |

Changed player and account views use the dark/gold/glass classes and translated labels. Financial values use the existing exact-money value object. The browser accessibility gate, reduced-motion behavior, focus rendering, small-mobile layout, and assistive-technology output remain `NOT VERIFIED — RUNTIME UNAVAILABLE`.

## Static and build evidence

| Gate | Evidence | Result |
|---|---|---|
| PHP parser pass | 319 existing tracked/untracked PHP files parsed with `php-parser` after removing the retired duplicate account-verification controller/view | Static parser pass; not a PHP runtime check |
| Vite asset build | `npm run build` after the final Pages 44–70 edits | Passed |
| Translation parity | EN/TH key-set and placeholder comparison for player, GLO L6, account services, and account-info resources | Passed |
| `git diff --check` | Executed after the final whitespace cleanup | Passed |
| Static route/deletion scan | No active route references the retired root verification controller/view; the member verification route uses the canonical Verification controller and opaque document-token parameter | Passed |
| Fixture/fallback scan | No known fixture identity/financial markers, `number_format()` money output, or internal account/document IDs were found in the hardened owner-facing projections/responses | Passed |
| Laravel route list | PHP runtime unavailable | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| Blade compilation | PHP runtime and Composer vendor unavailable | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| PHPUnit/Pest | PHP runtime and Composer vendor unavailable | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| Database migrations and ownership tests | Database/runtime unavailable | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| Browser and accessibility audit | Browser runner unavailable | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| Payment-provider tests | No configured provider/runtime | `NOT VERIFIED — RUNTIME UNAVAILABLE` |

## Changed-file manifest for this Pages 44–70 hardening pass

Each entry includes the path, file type, and purpose. Full file contents remain in the workspace at these exact paths; no implementation body is omitted from the repository deliverable.

| Path | `# TYPE` | `# PURPOSE` |
|---|---|---|
| `app/Http/Controllers/Web/PlayerWebController.php` | PHP controller | Canonical owner-scoped player pages; corrected deposit orchestration; added deposit and withdrawal status views; exact configured-currency validation and wallet aggregation; canonical self-exclusion. |
| `app/Http/Controllers/Web/BetPurchaseController.php` | PHP controller | Resolves a public draw reference to the canonical draw server-side and delegates exact-decimal bet selections to `BulkBetService`; no internal draw ID is accepted from the browser. |
| `app/Http/Requests/Web/BetPurchaseRequest.php` | PHP form request | Retained compatibility validation with public draw references and exact decimal stake strings. |
| `app/Http/Requests/Web/DepositRequest.php` | PHP form request | Retained compatibility validation with configured payment currency/limits and exact decimal deposit strings. |
| `app/Http/Requests/Web/WithdrawRequest.php` | PHP form request | Retained compatibility validation with configured payment currency/limits and exact decimal withdrawal strings. |
| `app/Http/Controllers/Verification/AccountVerificationController.php` | PHP controller | Canonical member verification orchestration; owner-scoped opaque document-token downloads; reviewer decisions remain policy-walled. |
| `app/Http/Controllers/Api/V1/AuthController.php` | PHP controller | Authenticated API identity projection without exposing the internal numeric user key. |
| `app/Http/Controllers/Api/V1/MeController.php` | PHP controller | Authenticated account projection without exposing the internal numeric user key. |
| `app/Http/Controllers/Api/V1/ProfileController.php` | PHP controller | Authenticated profile projection and mutation responses without exposing the internal numeric user key; translated API messages. |
| `app/Http/Resources/UserResource.php` | PHP API resource | Authenticated/public-safe user projection without exposing the internal numeric user key. |
| `app/Http/Controllers/Player/PlayerSecuritySettingsController.php` | PHP controller | Authenticated security, KYC, session, responsible-gaming limit, and canonical self-exclusion adapter. |
| `app/Http/Controllers/Player/LotteryHistoryPortalController.php` | PHP controller | Replaced fixture history/slip behavior with owner-scoped canonical Bet/Draw/Ticket/BetItem projections, canonical cancellation, and fail-closed re-bet. |
| `app/Http/Controllers/Player/PlayerDashboardController.php` | PHP controller | Compatibility dashboard projection without internal numeric draw/bet IDs and with translated fail-closed messages. |
| `app/Http/Controllers/Player/PlayerProfilePortalController.php` | PHP controller | Compatibility profile adapter with translated fail-closed unsupported mutations and session-owned canonical profile delegation. |
| `app/Http/Controllers/Player/PlayerSettingsPortalController.php` | PHP controller | Compatibility settings adapter with translated API messages and canonical responsible-gaming/self-exclusion transitions. |
| `resources/views/player/history-portal.blade.php` | Deleted Blade view | Removed the fixture-based duplicate history portal; `/history` compatibility paths now redirect to canonical `player.bets`. |
| `app/Http/Controllers/AccountVerificationController.php` | Deleted PHP controller | Removed the unrouted duplicate root verification controller; the Verification namespace controller is the sole active member path. |
| `app/Http/Controllers/Web/AccountVerificationController.php` | Deleted PHP controller alias | Removed the unrouted duplicate web verification alias. |
| `resources/views/account/verification.blade.php` | Deleted Blade view | Removed the unrouted duplicate hardcoded verification page; the canonical `account-verification.index` view is the sole active member surface. |
| `resources/views/player/profile-portal.blade.php` | Deleted Blade view | Removed an unused duplicate profile portal view; profile compatibility is API-only and browser paths redirect to canonical profile. |
| `resources/views/player/settings-portal.blade.php` | Deleted Blade view | Removed an unused duplicate settings portal view; browser paths use canonical security/responsible-gaming surfaces. |
| `resources/views/player/verification.blade.php` | Deleted Blade view | Removed an unused duplicate verification view; authenticated verification uses the canonical account verification controller. |
| `app/Http/Controllers/AccountGradeController.php` | PHP controller | Canonical account-grade browser history view with JSON compatibility for JSON clients. |
| `app/Models/AccountVerificationDocument.php` | PHP model projection | Owner-safe KYC document metadata projection with no exposed internal document ID. |
| `app/Services/Account/AccountVerificationService.php` | PHP service | Canonical owner KYC facade; removes internal account-number output and produces/validates opaque owner-scoped document download tokens. |
| `app/Services/Account/AccountVerificationDocumentService.php` | PHP service | Private KYC document storage/read projection with a generic download filename that does not reveal an internal document ID. |
| `app/Services/Verification/AccountVerificationService.php` | PHP service | Canonical member verification aggregate wrapper; exposes the owner-token lookup while preserving KYC state transitions and audit behavior. |
| `app/Services/Verification/DocumentStorageService.php` | PHP service | Private document storage/read contract with a generic content-disposition filename and no numeric ID disclosure. |
| `app/DTOs/ResponsibleGaming/SelfExclusionData.php` | Existing canonical PHP DTO | Server-pronounced self-exclusion request data; consumed by the hardened player paths. |
| `app/Services/Compliance/SelfExclusionService.php` | Existing canonical PHP service | Owner-scoped bridge used for current/active self-exclusion and transitions. |
| `app/Services/ResponsibleGaming/SelfExclusionService.php` | Existing canonical PHP service | Existing request/activation engine used by the new adapters; no duplicate engine created. |
| `app/Services/Payment/PaymentInitiationService.php` | Existing canonical PHP service | Inspected deposit orchestration contract reached by Page 61. |
| `app/Services/Finance/DepositService.php` | Existing canonical PHP service | Inspected request/create deposit contract; nonexistent `initiate()` call removed. |
| `resources/views/glo-l6/index.blade.php` | Blade view | Existing Page 44 home hardening; translated fail-closed purchase reason. |
| `resources/views/glo-l6/buy.blade.php` | Blade view | Page 45 fail-closed ticket-selection boundary with translated missing-contract states. |
| `resources/views/glo-l6/result.blade.php` | Blade view | Pages 46, 49, and 50 canonical result projection with translated unavailable messaging. |
| `resources/views/glo-l6/history.blade.php` | Blade view | Pages 47 and 48 bounded history/archive presentation. |
| `resources/views/player/dashboard.blade.php` | Blade view | Page 55 authenticated dashboard; exact money formatting and translated state fallback. |
| `resources/views/player/draws.blade.php` | Blade view | Page 56 real draw schedule/results view; corrected canonical close field and translated empty states. |
| `resources/views/player/draw-detail.blade.php` | Blade view | Page 57 real draw detail using actual result arrays and pending state. |
| `resources/views/player/bets.blade.php` | Blade view | Page 59 owner history without fabricated ticket/draw references. |
| `resources/views/player/wallet.blade.php` | Blade view | Page 60 exact wallet/ledger display and enum-safe transaction type projection. |
| `resources/views/player/deposit.blade.php` | Blade view | Page 61 capability-backed deposit form, exact limits, and status links. |
| `resources/views/player/withdraw.blade.php` | Blade view | Page 63 capability-backed withdrawal form, exact available balance, and history links. |
| `resources/views/player/withdrawal-status.blade.php` | Blade view | Page 64 owner-scoped withdrawal status/history detail without payout secrets, stored currency fallback refusal, and translated status/method labels. |
| `resources/views/player/profile.blade.php` | Blade view | Page 65 translated profile, password, and limit forms without fabricated limit placeholders. |
| `resources/views/account-verification/index.blade.php` | Blade view | Canonical Page 68 authenticated/public verification surface with translated public guide copy, owner-safe status/document projections, and opaque download-token links. |
| `resources/views/player/bet.blade.php` | Blade view | Page 58 fail-closed bet slip using a public draw reference rather than an internal draw ID and exact client-side cent totals. |
| `resources/views/components/account/verification-status.blade.php` | Blade component | Owner-safe verification summary with translated unavailable identity fields. |
| `resources/views/components/account/document-upload.blade.php` | Blade component | Canonical document-upload placeholder using translated unavailable state. |
| `resources/views/player/deposit-status.blade.php` | Blade view | Page 62 owner-scoped deposit/payment-intent status with stored currency, exact Money formatting, and translated status/method labels. |
| `resources/views/player/security.blade.php` | Blade view | Page 66 translated KYC, active session, self-exclusion, and security action view. |
| `resources/views/player/responsible-gaming.blade.php` | Blade view | Page 67 canonical limits and self-exclusion form without fabricated input defaults. |
| `resources/views/account/grade.blade.php` | Blade view | Page 69 exact grade/spend display and full-history link; removed fallback ticket prices. |
| `resources/views/account/grade-history.blade.php` | Blade view | Page 70 canonical owner grade history view with exact-money qualifying spend. |
| `resources/views/components/account/grade-card.blade.php` | Blade component | Exact-money grade spend/progress presentation and no fabricated Bronze/zero fallback labels. |
| `routes/web.php` | PHP route file | Added owner-scoped Page 62/64 status routes, switched member verification downloads to opaque token parameters, removed the unrouted duplicate verification-controller import, and retained auth/throttle/legacy route boundaries. |
| `lang/en/player.php` | PHP translation map | English player/status/security/deposit/withdrawal/self-exclusion keys. |
| `lang/th/player.php` | PHP translation map | Thai parity for the same player/status/security/deposit/withdrawal/self-exclusion keys. |
| `lang/en/glo_l6.php` | PHP translation map | English GLO L6 fail-closed contract labels. |
| `lang/th/glo_l6.php` | PHP translation map | Thai parity for GLO L6 fail-closed contract labels. |
| `lang/en/account_services.php` | PHP translation map | English grade-history and grade display keys; removed hardcoded price claims. |
| `lang/th/account_services.php` | PHP translation map | Thai parity for grade-history and grade display keys. |
| `lang/en/account_info.php` | PHP translation map | English public verification-guide and navigation copy with exact placeholder parity. |
| `lang/th/account_info.php` | PHP translation map | Thai parity for public verification-guide and navigation copy. |
| `package.json` | JSON dependency manifest | Added React runtime dependencies required by the existing Vite WalletManagement component. |
| `package-lock.json` | JSON lockfile | Locked React runtime dependencies and retained the project lockfile name. |
| `audit.md` | Markdown audit report | This page matrix, evidence boundary, findings, status ledger, and changed-file manifest. |

## Limitations and remaining findings

- The PHP runtime and Composer dependencies are unavailable, so no Laravel route list, Blade compiler, service-container resolution, migration, controller test, or browser request was executed.
- The existing repository contains a broad set of prior changes outside the focused files above. This audit does not convert those unrelated historical changes into new architecture.
- The payment providers, database, queue workers, callback signing keys, mail transport, CAPTCHA provider, and browser session are unavailable in the workspace.
- The Vite dependency audit still reports one moderate and one high vulnerability. No force upgrade was applied because the compatible remediation was not runtime-tested.
- Production readiness remains prohibited until the runtime, finance, security, localization, accessibility, build, and complete test gates are executed in an environment with PHP, Composer, database, and configured services.
## Pages 77–100 independent audit matrix

The following rows are independent page records. Static source review and edits are recorded; no Laravel, PHP, database, browser, provider, or full-test runtime gate is claimed.

| PAGE | ROUTE | ROUTE NAME | CONTROLLER | SERVICE | REQUEST | MODEL | DATABASE | API | VIEW | JS | CSS | TRANSLATION | SECURITY | DATA SOURCE | AUTHORIZATION | STATUS | TESTS | RUNTIME STATUS | REMAINING GAP |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 77 | `/results` | `results.index` | `GloResultsPageController` | `ResultsPageService` | none | `Draw`, `DrawResult` | published draw/result projection | `/api/v1/glo/latest-draw` | `results/index.blade.php` | none required | existing app/theme styles | `results.php` EN/TH | public-safe source state; no fixture claims | canonical published rows | anonymous public projection | IMPLEMENTED — STATIC ONLY | static inspection; runtime test not executed | NOT VERIFIED — RUNTIME UNAVAILABLE | verify route, Blade, query, and accessibility behavior with Laravel/browser |
| 78 | `/check` | `ticket-check`, `ticket-check.submit` | `HomeController` | `GloPublicResultService` | CSRF; six digits; throttled POST | `Draw`, `DrawResult` through service | canonical public result/check data | existing GLO check APIs | `home/check.blade.php` | none required | existing home styles | `home.php` EN/TH | server-side bounded input and rate limit | canonical GLO ticket checker | anonymous; no client identity accepted | REVIEWED — STATIC ONLY | existing check flow inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | execute throttling and no-data behavior |
| 79 | `/sales-points` | `sales-points` | `HomeController` | `GloSalesPointService` | bounded query/page filters | service-owned public point projection | configured/public sales-point data | existing GLO sales-point API | `home/sales-points.blade.php` | none required | existing home styles | home text bag EN/TH | bounded search and explicit unavailable state | canonical published sales points | anonymous public projection | REVIEWED — STATIC ONLY | existing controller/view inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify paginator and public data-state behavior |
| 80 | `/privacy` | `privacy` | `PublicPagesController` | existing public legal page service | none | legal content projection | configured legal content | existing privacy API | `static/privacy.blade.php` | none required | existing legal styles | `public_pages.php` EN/TH | public legal headers; no unsupported claims intended | configured legal source | anonymous | REVIEWED — STATIC ONLY | existing route/controller/view inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify canonical metadata and legal content parity |
| 81 | `/contact` | `contact`, `contact.submit` | `ContactController` | existing contact service/mail/storage lane | CSRF; validation; spam controls; throttle | contact submission model if configured | canonical contact configuration and sanitized submission | none | `contact/index.blade.php` | none required | existing contact styles | `contact.php` EN/TH | throttling, validation, truthful success | configured contact channels | anonymous GET/POST | REVIEWED — STATIC ONLY | existing route/controller/view inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify mail/storage failure states |
| 82 | `/download`, `/download-app`, `/app` | `download`, `download-app`, `app` | `PublicDownloadAppController` | `PublicAppLinkService` | none | none | configured app-link data | existing download API | `download/index.blade.php` | none required | existing app styles | public page resources EN/TH | no fabricated URLs | canonical configured links only | anonymous | REVIEWED — STATIC ONLY | existing service/controller inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify unavailable/not-configured rendering |
| 83 | `/account-grades`, `/account-grade` | existing named routes | `PublicGradeController` | existing public grade service | none | public grade configuration | configured grade rules only | none | existing grade public view | none required | existing public styles | account services/info EN/TH | no authenticated account data | configured explainer only | anonymous | REVIEWED — STATIC ONLY | existing route/controller inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify exact EN/TH key parity in runtime |
| 84 | `/account-verification`, `/account-verification-guide` | existing named routes | `PublicVerificationController` | existing public verification service | none | public verification configuration | configured guide data | none | existing verification public view | none required | existing public styles | account services/info EN/TH | no user/KYC records on public page | configured guide only | anonymous | REVIEWED — STATIC ONLY | existing route/controller inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify guide state and metadata |
| 85 | `/sitemap.xml`, robots/indexation surfaces | `sitemap` | `SitemapController` | existing sitemap/public-page services | none | public route registry | configured canonical URL source | XML sitemap | sitemap response | none | response headers | public page translations | excludes auth/admin/API/payment returns | canonical public routes only | anonymous | REVIEWED — STATIC ONLY | existing sitemap/security tests present but not run | NOT VERIFIED — RUNTIME UNAVAILABLE | execute sitemap and robots assertions |
| 86 | `/payment/success` | `payment.callback.success` | `PaymentCallbackController` | `PaymentCallbackService::browserReturnProjection` | authenticated query references; read-only | `Payment` plus payable projection | payments paper only | provider callback architecture remains separate | `payment/callback.blade.php` | none required | existing app styles | `account_services.php` EN/TH | owner check; safe reference bounds; no state mutation | verified internal payment status | session owner | REVIEWED — STATIC ONLY | existing BrowserPaymentCallback tests not run | NOT VERIFIED — RUNTIME UNAVAILABLE | verify confirmed/pending/not-found page states |
| 87 | `/payment/failure` | `payment.callback.failure` | `PaymentCallbackController` | same read-only projection | same bounded references | `Payment` | payments paper | no browser mutation | shared callback view | none required | existing app styles | account services EN/TH | authoritative failed status only | internal payment status | session owner | REVIEWED — STATIC ONLY | existing callback tests not run | NOT VERIFIED — RUNTIME UNAVAILABLE | verify failure cannot be forged by route |
| 88 | `/payment/cancel` | `payment.callback.cancel` | `PaymentCallbackController` | same read-only projection | same bounded references | `Payment` | payments paper | no browser mutation | shared callback view | none required | existing app styles | account services EN/TH | authoritative cancelled status only | internal payment status | session owner | REVIEWED — STATIC ONLY | existing callback tests not run | NOT VERIFIED — RUNTIME UNAVAILABLE | verify cancel context does not override state |
| 89 | `/payment/pending` | `payment.callback.pending` | `PaymentCallbackController` | same read-only projection | same bounded references | `Payment` | payments paper | no browser mutation | shared callback view | none required | existing app styles | account services EN/TH | authoritative pending status only | internal payment status | session owner | REVIEWED — STATIC ONLY | existing callback tests not run | NOT VERIFIED — RUNTIME UNAVAILABLE | verify pending remains pending until verified transition |
| 90 | `/admin`, `/admin/dashboard` | `admin.dashboard`, `admin.dashboard.index` | `LottoFinExecutiveDashboardController` | canonical model projections | authenticated; admin gate | `Bet`, `Withdrawal`, `FinancialTransaction` | live aggregate queries only | bounded analytics companion | `admin/dashboard.blade.php` | none required | existing admin styles | `admin.php` EN/TH | auth; access-admin gate; panel permission | canonical aggregates; no fallbacks | admin panel permission | HARDENED — STATIC ONLY | new route/controller/view static review | NOT VERIFIED — RUNTIME UNAVAILABLE | verify roles, empty DB, and Blade compilation |
| 91 | `/admin/api/analytics` | `admin.api.analytics` | `LottoFinExecutiveDashboardController` | canonical aggregate projections | bounded 0–31 day date range; throttled | `Bet`, `Withdrawal` | bounded aggregate queries | safe KPI JSON | none | none | none | admin EN/TH keys for labels | auth; access-admin; rate protection; no raw models | canonical aggregate data; unavailable trend/profit state | dashboard permission | HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | execute JSON and range-limit tests |
| 92 | `/admin/draws` | `admin.draws.index` | `LottoFinExecutiveDashboardController` | existing draw lifecycle services remain authoritative | bounded projection page | `Draw` | latest 50 draw projection | none | shared admin projection view | none required | existing admin styles | admin EN/TH | auth; draw permission; no browser mutation | canonical draw records | `view draws` permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify draw policy and pagination behavior |
| 93 | `/admin/risk` | `admin.risk.index` | `LottoFinExecutiveDashboardController` | existing risk services remain authoritative | none | no fabricated risk model rows | no fabricated records | none | shared explicit unavailable state | none required | existing admin styles | admin EN/TH | auth; risk permission | explicit `UNAVAILABLE` until canonical projection supplied | risk permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical risk alert projection without duplication |
| 94 | `/admin/bets` | `admin.bets.index` | `LottoFinExecutiveDashboardController` | existing betting services remain authoritative | latest 100 safe records | `Bet` | bounded latest bet projection | none | shared admin projection view | none required | existing admin styles | admin EN/TH | auth; transaction permission; no mutation controls | canonical bet rows | transaction-history permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify pagination and object-level policy expectations |
| 95 | `/admin/wallets` | `admin.wallets.index` | `LottoFinExecutiveDashboardController` | `WalletService` remains canonical for mutations | latest 100 safe projection | `Wallet` | canonical wallet rows | none | shared admin projection view | none required | existing admin styles | admin EN/TH | auth; wallet permission; no browser financial mutations | canonical wallet balances | wallet permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify masking/least privilege for deployed roles |
| 96 | `/admin/ledger` | `admin.ledger.index` | `LottoFinExecutiveDashboardController` | finance/ledger services remain canonical | latest 100 safe records | `FinancialTransaction` | canonical financial transaction projection | none | shared admin projection view | none required | existing admin styles | admin EN/TH | auth; finance permission; no adjustment UI | canonical financial transactions | financial-reports permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify ledger entry object policy and pagination |
| 97 | `/admin/reconciliation`, `/admin/api/reconciliation` | `admin.reconciliation.index`, `admin.api.reconciliation` | `LottoFinExecutiveDashboardController` | `FinancialReconciliationService` | bounded 0–31 day POST run; throttled | reconciliation DTOs and ledger models | canonical reconciliation service | explicit `NOT_CONFIGURED` GET; canonical report POST | shared explicit state | none required | existing admin styles | admin EN/TH | auth; reconcile permission; GET has no side effect | service report or explicit no bank feed | reconcile-ledger permission | HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | verify report DTO serialization and audit write |
| 98 | `/admin/audits` | `admin.audits.index` | `LottoFinExecutiveDashboardController` | existing audit query service architecture | bounded latest 100 projection | `AuditLog` | canonical immutable audit rows | none | shared admin projection view | none required | existing admin styles | admin EN/TH | auth; audit permission; no raw metadata exposure | canonical audit log safe fields | view-audit-logs permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | bind `AdminAuditQueryService` filters/pagination in runtime |
| 99 | `/admin/kyc` and secured document/action routes | `admin.kyc.index`, `admin.kyc.download`, `admin.kyc.approve`, `admin.kyc.reject` | `LottoFinExecutiveDashboardController` | `AccountVerificationService`, `AccountVerificationDocumentService` | CSRF review form; throttled document/action routes | `KycDocument` | private KYC storage and KYC tables | no public document API | shared admin projection view; private streamed response | none required | existing admin styles | admin EN/TH | auth; KYC permission; object-level document load; private stream; audit | canonical KYC document/service | manage-users permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify policy/four-eyes decision and private storage headers |
| 100 | `/admin/compliance` | `admin.compliance.index` | `LottoFinExecutiveDashboardController` | existing compliance/AML services remain authoritative | none | no fabricated compliance rows | no fabricated records | none | shared explicit unavailable state | none required | existing admin styles | admin EN/TH | auth; risk permission; no browser-only mutation | explicit `UNAVAILABLE` until canonical projection supplied | risk permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical compliance case projection without duplication |

**Runtime boundary for every row above:** `NOT VERIFIED — RUNTIME UNAVAILABLE`.

## Pages 77–100 changed-file manifest

| Path | `# TYPE` | `# PURPOSE` |
|---|---|---|
| `app/Http/Controllers/GloResultsPageController.php` | PHP controller | Replaced fabricated results and ticket-check payloads with the existing canonical public result and ticket-check projections. |
| `app/Http/Controllers/Admin/LottoFinExecutiveDashboardController.php` | PHP controller | Added authorized, bounded, canonical admin projections; removed fabricated KPI/trend/reconciliation values; uses configured-currency exact Money formatting with explicit UNAVAILABLE fallback; secured private KYC streaming and canonical KYC review delegation; made unsupported browser mutations explicit. |
| `app/Providers/AuthServiceProvider.php` | PHP provider | Added the `access-admin` gate backed by `AdminAccess` panel authorization. |
| `app/Http/Middleware/Authenticate.php` | PHP middleware | Keeps existing authentication behavior and redirects unauthenticated `/admin/*` requests to the Filament login boundary. |
| `bootstrap/app.php` | PHP bootstrap | Registers the explicit `admin.auth` middleware alias without changing the global authentication alias. |
| `app/Providers/AppServiceProvider.php` | PHP provider | Added authenticated operator rate protection for admin analytics and reconciliation endpoints. |
| `app/Services/Payment/PaymentCallbackService.php` | PHP service | Bounded browser-return references and preserved owner-scoped, read-only authoritative payment-state projection. |
| `app/Services/PublicPages/ResultsPageService.php` | PHP service | Public results rows are limited to published/completed draws whose scheduled time has passed. |
| `routes/web.php` | PHP route file | Resolved `/results` to the canonical public controller and added authentication, authorization, throttling, explicit reconciliation POST, secured KYC document/action routes, and non-mutating unsupported withdrawal responses. |
| `resources/views/results/index.blade.php` | Blade view | Public results hub using only canonical published rows, explicit source states, safe table overflow, status text, and translated copy. |
| `resources/views/admin/dashboard.blade.php` | Blade view | Shared authorized admin projection view with no fabricated financial values, explicit unavailable states, semantic tables, and translated labels. |
| `lang/en/results.php` | PHP translation map | English results-hub labels and explicit public-data states. |
| `lang/th/results.php` | PHP translation map | Exact Thai-locale key parity for the results-hub map. |
| `lang/en/admin.php` | PHP translation map | English admin labels and explicit operational states. |
| `lang/th/admin.php` | PHP translation map | Exact Thai-locale key parity for the admin map. |
| `tests/Feature/Pages77To100StaticContractTest.php` | PHPUnit feature/static contract test | Checks admin route boundary, known fixture removal, read-only payment-return lane, translation parity, and one audit row per page. |
| `app/Services/Account/AccountVerificationService.php` | PHP service | Added bounded, reviewer-bound opaque document-token resolution so admin KYC routes do not expose numeric document IDs while reusing the canonical KYC service. |
| `audit.md` | Markdown audit report | Added independent Page 77–100 audit matrix, runtime boundary, and changed-file manifest. |
| `PAGES-77-100-IMPLEMENTATION-REPORT.md` | Markdown delivery report | Complete contents, `# TYPE`, and `# PURPOSE` for every implementation file changed in this pass. |

| `resources/views/home/check.blade.php` | Blade view | Page 78 translated ticket-check labels while retaining CSRF, six-digit validation, server-side result state, and status messaging. |
| `resources/views/home/sales-points.blade.php` | Blade view | Page 79 translated bounded sales-point search, pagination, empty, and unavailable states. |
| `resources/views/privacy/index.blade.php` | Blade view | Page 80 policy surface with translated navigation, metadata, search, unavailable, and support labels. |
| `resources/views/download/index.blade.php` | Blade view | Page 82 configured app-destination surface with translated safety, integrity, and unavailable states. |
| `resources/views/account-grade/index.blade.php` | Blade view | Page 83 public grade explainer with translated navigation, configured-tier labels, private-state copy, and no fabricated account state. |
| `lang/en/public_pages.php` | PHP translation map | Added Page 80 and Page 82 visible interface labels. |
| `lang/th/public_pages.php` | PHP translation map | Exact EN/TH key and placeholder parity for Page 80 and Page 82 labels. |
| `lang/en/account_info.php` | PHP translation map | Added Page 83 visible interface labels. |
| `lang/th/account_info.php` | PHP translation map | Exact EN/TH key and placeholder parity for Page 83 labels. |
| `lang/en/home.php` | PHP translation map | Added Page 78–79 labels and count/page placeholders. |
| `lang/th/home.php` | PHP translation map | Exact EN/TH key and placeholder parity for Page 78–79 labels. |

All runtime-dependent rows and checks remain exactly: `NOT VERIFIED — RUNTIME UNAVAILABLE`.

## Pages 77–100 validation evidence

| Check | Result |
|---|---|
| PHP parser for changed PHP and translation files | Passed with `php-parser` static parser; this is not a PHP runtime check. |
| `git diff --check` | Passed. |
| Vite asset build | Passed with `npm run build`. |
| Static fixture-marker scan for Pages 77, 90, and admin view | Passed; known fabricated values are absent from the changed projections/views. |
| Static route, audit-row, and translation-map checks | Passed, including exact EN/TH keys and placeholders for results, admin, public-pages, account-info, and home maps. |
| Pages 80, 82, and 83 visible-label review | Passed static review after moving remaining visible interface labels into translation maps. |
| Laravel route list | NOT VERIFIED — RUNTIME UNAVAILABLE |
| Blade compilation | NOT VERIFIED — RUNTIME UNAVAILABLE |
| PHPUnit/Pest | NOT VERIFIED — RUNTIME UNAVAILABLE |
| Database, browser, payment-provider, queue, and accessibility checks | NOT VERIFIED — RUNTIME UNAVAILABLE |

```
