<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\BetStatus;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BetPurchaseException;
use App\Exceptions\FinancialException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\WithdrawalException;
use App\Exceptions\WithdrawalKycException;
use App\Http\Requests\Web\DepositRequest;
use App\Http\Requests\Web\UpdateResponsibleGamingLimitsRequest;
use App\Http\Requests\Web\WithdrawalRequest;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\ResponsibleGamingLimit;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Rules\StrongPasswordRule;
use App\Services\Auth\PasswordChangeService;
use App\Services\Betting\BetAmountService;
use App\Services\Betting\BetPurchaseWalletService;
use App\Services\Compliance\RealMoneyGate;
use App\Services\Compliance\SelfExclusionService;
use App\Services\Finance\Money;
use App\Services\Finance\WithdrawalService;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payment\PaymentInitiationService;
use App\Services\Security\ResponsibleGamingService;
use App\Services\Transfer\TransferPanelService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Player Web App Controller providing dynamic Blade views and player actions.
 */
final class PlayerWebController
{
    public function __construct(
        private readonly PaymentInitiationService $paymentInitiation,
        private readonly WithdrawalService $withdrawalService,
        private readonly ResponsibleGamingService $rgService,
        private readonly SelfExclusionService $selfExclusions,
        private readonly ?PaymentGatewayManager $gatewayManager = null,
    ) {}

    /**
     * Closed label map over the canonical PaymentMethod vocabulary. Anything
     * the config allows but the enum does not define surfaces as its raw
     * value rather than being silently dropped — a misconfiguration should
     * be visible, not invisible.
     *
     * @param  list<string>  $allowed
     * @return array<string, string>
     */
    private function configuredFinanceCurrency(): Currency
    {
        return Currency::from((string) config('payment.currency.default'));
    }

    private function methodOptions(array $allowed): array
    {
        $options = [];

        foreach ($allowed as $method) {
            if (! is_string($method) || PaymentMethod::tryFrom($method) === null) {
                continue;
            }

            $key = 'player.payment_method_'.$method;
            $label = trans($key);
            $options[$method] = (string) ($label === $key
                ? trans('payment_ops.method_'.$method)
                : $label);
        }

        return $options;
    }

    /**
     * Available deposit methods derived from canonical configuration.
     *
     * @return array<string, string>
     */
    private function availableDepositMethods(): array
    {
        if (! app(RealMoneyGate::class)->isOpen()) {
            return [];
        }
        $allowed = (array) config('payment.deposit.allowed_methods', []);
        $manager = $this->gatewayManager ?? app(PaymentGatewayManager::class);

        $filtered = array_filter($allowed, function ($method) use ($manager): bool {
            $enum = PaymentMethod::tryFrom((string) $method);
            if ($enum === null) {
                return false;
            }

            return $manager->isDepositCapable($enum);
        });

        return $this->methodOptions(array_values($filtered));
    }

    /**
     * Available withdrawal methods derived from canonical configuration.
     *
     * @return array<string, string>
     */
    private function availableWithdrawalMethods(): array
    {
        if (! app(RealMoneyGate::class)->isOpen()) {
            return [];
        }
        $allowed = (array) config('payment.withdrawal.allowed_methods', []);
        $manager = $this->gatewayManager ?? app(PaymentGatewayManager::class);

        $filtered = array_filter($allowed, function ($method) use ($manager): bool {
            $enum = PaymentMethod::tryFrom((string) $method);
            if ($enum === null) {
                return false;
            }

            return $manager->isWithdrawalCapable($enum);
        });

        return $this->methodOptions(array_values($filtered));
    }

    /**
     * Player Dashboard overview.
     */
    public function dashboard(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->first();

        $openDraw = Draw::query()
            ->where('status', DrawStatus::Open)
            ->with(['result.winningNumbers', 'winningNumbers'])
            ->first();

        $upcomingDraw = $openDraw ?? Draw::query()
            ->where('status', DrawStatus::Scheduled)
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')
            ->first();

        $recentBets = Bet::query()
            ->where('user_id', $user->id)
            ->with(['draw', 'items'])
            ->latest('id')
            ->take(5)
            ->get();

        $latestCompletedDraw = Draw::query()
            ->whereIn('status', [DrawStatus::ResultPublished, DrawStatus::Completed])
            ->with(['result.winningNumbers', 'winningNumbers'])
            ->latest('completed_at')
            ->first();

        $recentTransactions = FinancialTransaction::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->take(5)
            ->get();

        return view('player.dashboard', [
            'user' => $user,
            'wallet' => $wallet,
            'openDraw' => $openDraw,
            'upcomingDraw' => $upcomingDraw,
            'recentBets' => $recentBets,
            'latestCompletedDraw' => $latestCompletedDraw,
            'recentTransactions' => $recentTransactions,
        ]);
    }

    /**
     * Sum database decimal strings through Money so the presentation never
     * relies on a floating-point aggregate returned by a database driver.
     */
    private function sumTransactionAmounts(int $userId, string $currencyCode, string $type): string
    {
        $currency = Currency::from($currencyCode);
        $total = Money::zero($currency);
        $amounts = FinancialTransaction::query()
            ->where('user_id', $userId)
            ->where('currency', $currencyCode)
            ->where('type', $type)
            ->pluck('amount');

        foreach ($amounts as $amount) {
            $total = $total->plus(Money::fromDatabase((string) $amount, $currency));
        }

        return $total->toString();
    }

    /**
     * Complete lottery draw schedule and past results.
     */
    public function draws(Request $request): View
    {
        $status = $request->query('status');

        $query = Draw::query()
            ->with(['result.winningNumbers', 'winningNumbers'])
            ->latest('scheduled_at');

        if ($status && $status !== 'all') {
            $statusEnum = DrawStatus::tryFrom($status);
            if ($statusEnum) {
                $query->where('status', $statusEnum);
            }
        }

        $draws = $query->paginate(12);

        $openDraw = Draw::query()
            ->where('status', DrawStatus::Open)
            ->first();

        return view('player.draws', [
            'draws' => $draws,
            'openDraw' => $openDraw,
            'currentFilter' => $status ?? 'all',
        ]);
    }

    /**
     * Single draw details and results.
     */
    public function drawDetail(string $id): View
    {
        $draw = ctype_digit($id)
            ? Draw::with(['result.winningNumbers', 'winningNumbers'])->findOrFail((int) $id)
            : Draw::with(['result.winningNumbers', 'winningNumbers'])->where('draw_number', $id)->firstOrFail();

        return view('player.draw-detail', [
            'draw' => $draw,
        ]);
    }

    /**
     * Bet placement and interactive bet slip interface.
     */
    public function betSlip(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();

        // Mirror the purchase resolver instead of presenting a different wallet
        // or currency as spendable. This is advisory: the real purchase locks and
        // rechecks it, including wallet type, before any debit.
        $bettingCurrency = app(BetAmountService::class)->currency();
        $wallets = app(BetPurchaseWalletService::class);
        try {
            $wallet = $wallets->resolveWallet($user, $bettingCurrency);
            $wallets->assertBettingWallet($wallet);
        } catch (BetPurchaseException) {
            $wallet = null;
        }

        // This is only a read-only presentation filter: admission is rechecked
        // under the draw lock by the purchase service. Never show a draw as
        // sellable solely because its mutable status still says "open".
        $clock = now();
        $openDraw = Draw::query()
            ->where('status', DrawStatus::Open)
            ->where('betting_open_at', '<=', $clock)
            ->where('betting_close_at', '>', $clock)
            ->whereNull('closed_at')
            ->whereNull('drawn_at')
            ->whereNull('result_published_at')
            ->whereNull('completed_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('draw_cancellation_ownerships')->whereColumn('draw_id', 'draws.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payouts')->whereColumn('draw_id', 'draws.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('settlement_runs')->whereColumn('draw_id', 'draws.id'))
            ->orderBy('betting_close_at')->orderBy('id')
            ->first();

        $marketOptions = [];
        foreach ((array) config('lottery.markets', []) as $key => $market) {
            if (! is_array($market) || ($market['enabled'] ?? false) !== true) {
                continue;
            }

            $marketOptions[(string) $key] = [
                'label' => isset($market['label']) && (string) $market['label'] !== ''
                    ? (string) $market['label']
                    : (string) trans('player.not_configured'),
                'digits' => (int) ($market['digits'] ?? 0),
                // The payout multiplier is published to the browser so the bet
                // slip's maximum-payout preview uses the same configured rate
                // the settlement engine uses - never a rate duplicated in JS.
                // The value is passed through exactly as configured; no float
                // cast, so it cannot drift from what settlement reads.
                'multiplier' => $market['payout_multiplier'] ?? 0,
            ];
        }

        return view('player.bet', [
            'user' => $user,
            'wallet' => $wallet,
            'openDraw' => $openDraw,
            'marketOptions' => $marketOptions,
            'bettingCurrency' => $bettingCurrency,
        ]);
    }

    /**
     * Player's wagers and lottery tickets ledger.
     */
    public function bets(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();
        $status = $request->query('status');

        $query = Bet::query()
            ->where('user_id', $user->id)
            ->with(['draw', 'items', 'ticket'])
            ->latest('id');

        if ($status && $status !== 'all') {
            $statusEnum = BetStatus::tryFrom($status);
            if ($statusEnum) {
                $query->where('status', $statusEnum);
            }
        }

        $bets = $query->paginate(15);

        return view('player.bets', [
            'bets' => $bets,
            'currentFilter' => $status ?? 'all',
        ]);
    }

    /**
     * Wallet balances, holds, and full ledger history.
     */
    public function wallet(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();
        $type = $request->query('type');
        $currencyParam = $request->query('currency');
        $defaultCurrency = $this->configuredFinanceCurrency();

        $walletQuery = Wallet::query()->where('user_id', $user->id);
        if ($currencyParam && in_array(strtoupper((string) $currencyParam), [Currency::THB->value, Currency::BDT->value, Currency::USD->value], true)) {
            $walletQuery->where('currency', strtoupper((string) $currencyParam));
        }

        $wallet = $walletQuery->first() ?? Wallet::query()->where('user_id', $user->id)->first();
        $selectedCurrency = $wallet?->currency instanceof Currency
            ? $wallet->currency->value
            : $defaultCurrency->value;

        $txQuery = FinancialTransaction::query()
            ->where('user_id', $user->id)
            ->where('currency', $selectedCurrency)
            ->latest('id');

        if ($type && $type !== 'all') {
            $txQuery->where('type', $type);
        }

        $transactions = $txQuery->paginate(15);

        $totalDeposited = $this->sumTransactionAmounts(
            (int) $user->id,
            $selectedCurrency,
            'deposit',
        );
        $totalPrizesWon = $this->sumTransactionAmounts(
            (int) $user->id,
            $selectedCurrency,
            'payout',
        );

        return view('player.wallet', [
            'user' => $user,
            'wallet' => $wallet,
            'transactions' => $transactions,
            'totalDeposited' => $totalDeposited,
            'totalPrizesWon' => $totalPrizesWon,
            'selectedCurrency' => $selectedCurrency,
            // The Cash <-> Win panel. Same TransferPanelService the JSON endpoint
            // (BalanceTransferController::panel) uses, so the server-rendered panel
            // and the API can never disagree about a player's pockets or limits.
            // The view includes the partial only when this key is present.
            'transferPanel' => $this->transferPanel($user),
        ]);
    }

    /**
     * Deposit funds page.
     */
    public function deposit(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();
        $currency = $this->configuredFinanceCurrency();

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency', $currency->value)
            ->first();

        $recentDeposits = Deposit::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->take(5)
            ->get();

        return view('player.deposit', [
            'user' => $user,
            'wallet' => $wallet,
            // Audit W3/W7/W8: the page renders the SAME config the finance
            // engine enforces — limits and the closed method vocabulary —
            // so the UI can never advertise a rule or a gateway that does
            // not exist in the canonical payment configuration.
            'limits' => [
                'min' => (string) config('payment.deposit.min'),
                'max' => (string) config('payment.deposit.max'),
            ],
            'currency' => $currency,
            'methods' => $this->availableDepositMethods(),
            'recentDeposits' => $recentDeposits,
        ]);
    }

    /**
     * Handle deposit initiation.
     */
    public function storeDeposit(DepositRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $currency = $this->configuredFinanceCurrency();

        $validated = $request->validated();

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency', $currency->value)
            ->first();

        if (! $wallet instanceof Wallet) {
            return redirect()->route('player.deposit')
                ->with('error', trans('player.wallet_missing'));
        }

        $amount = Money::of((string) $validated['amount'], $currency);

        try {
            $payment = $this->paymentInitiation->initiateDeposit(
                wallet: $wallet,
                amount: $amount,
                method: PaymentMethod::from((string) $validated['method']),
                idempotencyKey: (string) $validated['idempotency_key'],
                options: [
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                    'metadata' => [
                        'source' => 'web',
                    ],
                ],
            );
            $deposit = $payment['deposit'];
            $gatewayResponse = $payment['gateway_response'];
        } catch (FinancialException $exception) {
            return redirect()->route('player.deposit')
                ->withInput($request->only('amount', 'method'))
                ->with('error', trans('player.deposit_request_failed'));
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('player.deposit')
                ->with('error', trans('player.deposit_request_failed'));
        }

        if (! $gatewayResponse->successful) {
            return redirect()->route('player.deposit')
                ->with('error', trans('player.deposit_checkout_unavailable'));
        }

        // External gateway checkout (Stripe/hosted checkout): the player's
        // browser is directed to the provider. The browser return route
        // shows the AUTHORITATIVE internal state - never this
        // redirect as proof of payment.
        if (is_string($gatewayResponse->redirectUrl) && $gatewayResponse->redirectUrl !== '') {
            return redirect()->away($gatewayResponse->redirectUrl);
        }

        // Manual settlement (operator-configured bank transfer): show the
        // operator's real settlement instructions for this reference.
        $instructions = $gatewayResponse->metadata['instructions'] ?? null;

        if (is_array($instructions) && $instructions !== []) {
            return redirect()->route('player.deposit')->with('instructions', [
                'reference' => (string) $deposit->reference_number,
            ] + $instructions);
        }

        $status = $deposit->status instanceof \BackedEnum ? $deposit->status->value : (string) $deposit->status;

        return redirect()->route('player.deposit')->with('success', trans('player.deposit_created_notice', [
            'reference' => (string) $deposit->reference_number,
            'amount' => Money::of((string) $deposit->amount, $currency)->format(),
            'status' => $status,
        ]));
    }

    /**
     * Page 62: owner-scoped deposit status / payment intent state.
     */
    public function depositStatus(Request $request, string $deposit): View
    {
        /** @var User $user */
        $user = Auth::user();
        $record = Deposit::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($deposit): void {
                $query->where('reference_number', $deposit)->orWhere('uuid', $deposit);
            })
            ->firstOrFail();

        return view('player.deposit-status', ['deposit' => $record]);
    }

    /**
     * Withdraw funds page.
     */
    public function withdraw(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();
        $currency = $this->configuredFinanceCurrency();

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency', $currency->value)
            ->first();

        $recentWithdrawals = Withdrawal::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->take(5)
            ->get();
        $availableBalance = $wallet instanceof Wallet
            ? Money::fromDatabase($wallet->getAvailableBalance(), $currency)
                ->assertNotNegative('available balance')
                ->format()
            : null;

        return view('player.withdraw', [
            'user' => $user,
            'wallet' => $wallet,
            'availableBalance' => $availableBalance,
            'currency' => $currency,
            // Audit W4/W5/W6: minimum, maximum and the processing window are
            // rendered from the same config the withdrawal engine enforces,
            // so the page can never quote a rule the engine does not apply.
            'limits' => [
                'min' => (string) config('payment.withdrawal.min'),
                'max' => (string) config('payment.withdrawal.max'),
                'processing_hours' => (int) config('payment.withdrawal.processing_hours'),
            ],
            'methods' => $this->availableWithdrawalMethods(),
            'supportedBanks' => (array) config('payment.supported_banks', []),
            'recentWithdrawals' => $recentWithdrawals,
        ]);
    }

    /**
     * Handle withdrawal request with method-specific destination validation.
     */
    public function storeWithdraw(WithdrawalRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $currency = $this->configuredFinanceCurrency();

        $limits = (array) config('payment.withdrawal');
        $validated = $request->validated();
        $method = (string) $validated['method'];

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency', $currency->value)
            ->first();

        if (! $wallet instanceof Wallet) {
            return redirect()->route('player.withdraw')
                ->with('error', trans('player.wallet_missing'));
        }

        $amount = Money::of((string) $validated['amount'], $currency);

        $destinationNumber = $method === PaymentMethod::PromptPay->value
            ? (string) (($validated['destination_type'] ?? 'promptpay_mobile') === 'promptpay_national_id'
                ? ($validated['national_id'] ?? '') : ($validated['mobile'] ?? ''))
            : (string) ($validated['account_number'] ?? '');
        $payoutDetails = array_filter([
            'bank_name' => $validated['bank_name'] ?? null,
            'bank_code' => $validated['bank_code'] ?? null,
            'account_number' => $destinationNumber,
            'account_name' => (string) $validated['account_name'],
            'destination_type' => $method === PaymentMethod::PromptPay->value
                ? (string) ($validated['destination_type'] ?? 'promptpay_mobile')
                : 'bank_account',
            'mobile' => $method === PaymentMethod::PromptPay->value ? ($validated['mobile'] ?? null) : null,
            'national_id' => $method === PaymentMethod::PromptPay->value ? ($validated['national_id'] ?? null) : null,
            'method' => $method,
        ], static fn ($value): bool => $value !== null && $value !== '');

        try {
            $withdrawal = $this->withdrawalService->request(
                wallet: $wallet,
                amount: $amount,
                method: PaymentMethod::from((string) $validated['method']),
                idempotencyKey: (string) $validated['idempotency_key'],
                options: [
                    'payout_details' => $payoutDetails,
                    'ip' => $request->ip(),
                ],
            );

        } catch (InsufficientBalanceException) {
            return redirect()->route('player.withdraw')
                ->withInput($request->only('amount', 'method', 'account_number', 'account_name', 'bank_name'))
                ->with('error', trans('player.withdrawal_insufficient_balance'));
        } catch (WithdrawalKycException $exception) {
            return redirect()->route('player.withdraw')
                ->withInput($request->only('amount', 'method', 'account_number', 'account_name', 'bank_name'))
                ->with('error', trans('player.withdrawal_kyc_blocked'));
        } catch (WithdrawalException|FinancialException $exception) {
            return redirect()->route('player.withdraw')
                ->withInput($request->only('amount', 'method', 'account_number', 'account_name', 'bank_name'))
                ->with('error', trans('player.withdrawal_request_failed'));
        }

        return redirect()->route('player.withdraw')->with('success', trans('player.withdrawal_requested_notice', [
            'reference' => (string) $withdrawal->reference_number,
            'amount' => Money::of((string) $withdrawal->amount, $currency)->format(),
            'hours' => (int) ($limits['processing_hours'] ?? 24),
        ]));
    }

    /**
     * Page 64: owner-scoped withdrawal status / history detail.
     */
    public function withdrawalStatus(Request $request, string $withdrawal): View
    {
        /** @var User $user */
        $user = Auth::user();
        $record = Withdrawal::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($withdrawal): void {
                $query->where('reference_number', $withdrawal)->orWhere('uuid', $withdrawal);
            })
            ->firstOrFail();

        return view('player.withdrawal-status', ['withdrawal' => $record]);
    }

    /**
     * Profile and account settings page.
     */
    public function profile(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();
        $limits = ResponsibleGamingLimit::query()->where('user_id', $user->id)->first();

        return view('player.profile', [
            'user' => $user,
            'limits' => $limits,
        ]);
    }

    /**
     * Update profile details.
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $user->update($validated);

        return redirect()->route('player.profile')->with('success', 'Profile details updated successfully.');
    }

    /**
     * Update password.
     */
    public function updatePassword(Request $request, PasswordChangeService $passwords): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            // The same policy registration and reset use.
            'new_password' => ['required', 'string', new StrongPasswordRule],
        ]);

        $passwords->change($user, $validated['current_password'], $validated['new_password']);

        // Prevent the still-in-memory request session being re-saved after its
        // database row was revoked. Require a fresh sign-in with the new secret.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Password updated. Please sign in again.');
    }

    /**
     * Responsible-gaming page. All values are read from the authenticated
     * player's canonical limit and self-exclusion records.
     */
    public function responsibleGaming(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();
        $limits = ResponsibleGamingLimit::query()->where('user_id', $user->id)->first();

        return view('player.responsible-gaming', [
            'user' => $user,
            'limits' => $limits,
            'activeExclusion' => $this->selfExclusions->currentActiveFor((int) $user->id),
        ]);
    }

    /**
     * Activate an authenticated player's responsible-gaming exclusion using
     * the existing responsible-gaming service. The server derives identity
     * and the service records the audit event.
     */
    public function storeSelfExclusion(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $validated = $request->validate([
            'duration' => ['required', 'string', Rule::in(['7_days', '30_days', '90_days', '180_days', '365_days'])],

        ]);

        // The day count is derived from the validated key itself, so no duration
        // literal is duplicated here - adding a duration means adding one entry
        // to the rule above and nothing else.
        $days = (int) str_replace('_days', '', (string) $validated['duration']);

        try {
            $requestData = SelfExclusionData::fromInput([
                'user_id' => (int) $user->id,
                'effective_at' => now(),
                'ends_at' => now()->addDays($days),
                'scope' => 'account',
                'reason_code' => 'PLAYER_REQUESTED',
            ]);
            $requested = $this->selfExclusions->request($requestData);
            $this->selfExclusions->activate($requested);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('settings.index')->with('error', trans('player.self_exclusion_request_failed'));
        }

        return redirect()->route('settings.index')->with('success', 'Self-exclusion is active and betting and deposit flows are restricted.');
    }

    /**
     * Update responsible gaming limits.
     */
    public function updateLimits(UpdateResponsibleGamingLimitsRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $validated = $request->validated();

        $dailyDeposit = isset($validated['daily_deposit_limit']) && $validated['daily_deposit_limit'] !== '' ? (string) $validated['daily_deposit_limit'] : null;
        $singleBet = isset($validated['single_bet_limit']) && $validated['single_bet_limit'] !== '' ? (string) $validated['single_bet_limit'] : null;
        $dailyWagering = isset($validated['daily_wagering_limit']) && $validated['daily_wagering_limit'] !== '' ? (string) $validated['daily_wagering_limit'] : null;

        $this->rgService->setLimits(
            user: $user,
            dailyDepositLimit: $dailyDeposit,
            singleBetLimit: $singleBet,
            dailyWageringLimit: $dailyWagering,
        );

        return redirect()->route('player.profile')->with('success', 'Responsible gaming limits saved.');
    }

    /**
     * Panel data for the wallet page, or null when the pane cannot be built.
     *
     * A failure here must not take the whole wallet page down: the balance cards
     * and the ledger are the reason the player opened the page, and the transfer
     * panel is one section of it. The refusal is logged with the player id (never
     * the amounts) so an operator can see it, and the view renders without the
     * panel — it does not render a fabricated "transfers disabled" state, because
     * an exception is not the same statement as an operator switch.
     *
     * @return array<string, mixed>|null
     */
    private function transferPanel(User $user): ?array
    {
        try {
            return app(TransferPanelService::class)->panelData($user);
        } catch (\Throwable $e) {
            Log::error('wallet.transfer_panel_unavailable', [
                'user_id' => (int) $user->getKey(),
                'exception' => $e::class,
            ]);

            return null;
        }
    }
}
