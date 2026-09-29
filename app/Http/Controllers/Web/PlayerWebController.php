<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\PaymentMethod;
use App\Enums\WalletHoldType;
use App\Exceptions\FinancialException;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\WithdrawalException;
use App\Exceptions\WithdrawalKycException;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Rules\StrongPasswordRule;
use App\Services\Finance\DepositService;
use App\Services\Payment\PaymentInitiationService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WithdrawalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Player Web App Controller providing dynamic Blade views and player actions.
 */
final class PlayerWebController
{
    public function __construct(
        private readonly DepositService $depositService,
        private readonly PaymentInitiationService $paymentInitiation,
        private readonly WithdrawalService $withdrawalService,
        private readonly WalletHoldService $holdService,
    ) {
    }

    /**
     * Closed label map over the canonical PaymentMethod vocabulary. Anything
     * the config allows but the enum does not define surfaces as its raw
     * value rather than being silently dropped — a misconfiguration should
     * be visible, not invisible.
     *
     * @param  list<string>  $allowed
     * @return array<string, string>
     */
    private function methodOptions(array $allowed): array
    {
        $labels = [
            PaymentMethod::Stripe->value => 'Stripe (Card)',
            PaymentMethod::Bkash->value => 'bKash',
            PaymentMethod::Nagad->value => 'Nagad',
            PaymentMethod::Crypto->value => 'Crypto',
            PaymentMethod::BankTransfer->value => 'Thai Bank Transfer',
            PaymentMethod::Manual->value => 'Manual',
        ];

        $options = [];

        foreach ($allowed as $method) {
            if (is_string($method)) {
                $options[$method] = $labels[$method] ?? $method;
            }
        }

        return $options;
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

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->first();

        $openDraw = Draw::query()
            ->where('status', DrawStatus::Open)
            ->first();

        return view('player.bet', [
            'user' => $user,
            'wallet' => $wallet,
            'openDraw' => $openDraw,
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
            $statusEnum = \App\Enums\BetStatus::tryFrom($status);
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

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->first();

        $txQuery = FinancialTransaction::query()
            ->where('user_id', $user->id)
            ->latest('id');

        if ($type && $type !== 'all') {
            $txQuery->where('type', $type);
        }

        $transactions = $txQuery->paginate(15);

        $totalDeposited = FinancialTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'deposit')
            ->sum('amount');

        $totalPrizesWon = FinancialTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'payout')
            ->sum('amount');

        return view('player.wallet', [
            'user' => $user,
            'wallet' => $wallet,
            'transactions' => $transactions,
            'totalDeposited' => $totalDeposited,
            'totalPrizesWon' => $totalPrizesWon,
        ]);
    }

    /**
     * Deposit funds page.
     */
    public function deposit(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
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
            'methods' => $this->methodOptions((array) config('payment.deposit.allowed_methods')),
            'recentDeposits' => $recentDeposits,
        ]);
    }

    /**
     * Handle deposit initiation.
     */
    public function storeDeposit(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $limits = (array) config('payment.deposit');

        $validated = $request->validate([
            // Bounds come from the SAME config the finance engine enforces
            // (audit W3): the request gate is a convenience, the service's
            // assertAmountWithinLimits() remains the exact decimal authority.
            'amount' => [
                'required',
                'numeric',
                'min:'.(string) ($limits['min'] ?? '50.00'),
                'max:'.(string) ($limits['max'] ?? '500000.00'),
            ],
            'method' => ['required', 'string', Rule::in((array) ($limits['allowed_methods'] ?? []))],
            // One-time key rendered into the form: a double-submit or a
            // back-button resubmit replays the SAME key and the service
            // returns the existing deposit instead of creating a second one.
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency', Currency::THB->value)
            ->first();

        if (! $wallet instanceof Wallet) {
            return redirect()->route('player.deposit')
                ->with('error', 'No THB wallet found for your account. Please contact support.');
        }

        // ---------------------------------------------------------
        // FINAL AUDIT #1: the web deposit path goes through the SAME
        // orchestrated initiation as the API - capability gate, deposit
        // intent, gateway session, Payment aggregate - instead of writing
        // a deposit row and stopping. Nothing is credited here; wallets
        // only move on verified provider confirmation.
        // ---------------------------------------------------------
        try {
            $initiation = $this->paymentInitiation->initiateDeposit(
                wallet: $wallet,
                amount: Money::of((string) $validated['amount'], Currency::THB),
                method: PaymentMethod::from((string) $validated['method']),
                idempotencyKey: (string) $validated['idempotency_key'],
                options: ['metadata' => ['source' => 'web', 'ip' => $request->ip()]],
            );
        } catch (FinancialException $exception) {
            return redirect()->route('player.deposit')
                ->withInput($request->only('amount', 'method'))
                ->with('error', $exception->getMessage());
        }

        $deposit = $initiation['deposit'];
        $gatewayResponse = $initiation['gateway_response'];

        // Provider refused the session (credentials missing, misconfigured
        // settlement details, upstream error): honest failure, nothing
        // redirected, nothing credited. The deposit stays unpaid-pending.
        if (! $gatewayResponse->successful) {
            return redirect()->route('player.deposit')
                ->with('error', $gatewayResponse->errorMessage ?? 'The payment provider could not be reached. Please try again.');
        }

        // Hosted checkout (Stripe/bKash/Nagad): send the player to the
        // provider. They come back to /payment/success|failure|cancel,
        // which shows the AUTHORITATIVE internal state - never this
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

        return redirect()->route('player.deposit')->with('success', sprintf(
            'Deposit %s created for %s — status: %s. Complete the payment; funds are credited after confirmation.',
            (string) $deposit->reference_number,
            Money::of((string) $deposit->amount, Currency::THB)->format(),
            $status,
        ));
    }

    /**
     * Withdraw funds page.
     */
    public function withdraw(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->first();

        $recentWithdrawals = Withdrawal::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->take(5)
            ->get();

        return view('player.withdraw', [
            'user' => $user,
            'wallet' => $wallet,
            // Audit W4/W5/W6: minimum, maximum and the processing window are
            // rendered from the same config the withdrawal engine enforces,
            // so the page can never quote a rule the engine does not apply.
            'limits' => [
                'min' => (string) config('payment.withdrawal.min'),
                'max' => (string) config('payment.withdrawal.max'),
                'processing_hours' => (int) config('payment.withdrawal.processing_hours'),
            ],
            'methods' => $this->methodOptions((array) config('payment.withdrawal.allowed_methods')),
            'recentWithdrawals' => $recentWithdrawals,
        ]);
    }

    /**
     * Handle withdrawal request.
     */
    public function storeWithdraw(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $limits = (array) config('payment.withdrawal');

        $validated = $request->validate([
            // Same config as the engine (audit W4/W5): the service's
            // assertAmountWithinLimits() stays the exact decimal authority.
            'amount' => [
                'required',
                'numeric',
                'min:'.(string) ($limits['min'] ?? '100.00'),
                'max:'.(string) ($limits['max'] ?? '500000.00'),
            ],
            'method' => ['required', 'string', Rule::in((array) ($limits['allowed_methods'] ?? []))],
            // Destination fields: bank details for bank_transfer, mobile
            // number for the wallet rails, address for crypto. The server
            // keeps whatever was submitted as encrypted payout details.
            'account_number' => ['required', 'string', 'max:255'],
            'account_name' => ['required', 'string', 'max:255'],
            'bank_name' => ['required_if:method,bank_transfer', 'nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:64'],
        ]);

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency', Currency::THB->value)
            ->first();

        if (! $wallet instanceof Wallet) {
            return redirect()->route('player.withdraw')
                ->with('error', 'No THB wallet found for your account. Please contact support.');
        }

        $amount = Money::of((string) $validated['amount'], Currency::THB);

        $payoutDetails = array_filter([
            'bank_name' => $validated['bank_name'] ?? null,
            'account_number' => (string) $validated['account_number'],
            'account_name' => (string) $validated['account_name'],
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

            // Same reservation the API controller makes: the service records
            // the request, the hold locks the funds for payout.
            $this->holdService->hold($wallet, $amount, WalletHoldType::Withdrawal, [
                'withdrawal_id' => $withdrawal->id,
            ]);
        } catch (InsufficientBalanceException) {
            return redirect()->route('player.withdraw')
                ->withInput($request->only('amount', 'method', 'account_number', 'account_name', 'bank_name'))
                ->with('error', 'Available balance is insufficient to request this withdrawal amount.');
        } catch (WithdrawalKycException $exception) {
            return redirect()->route('player.withdraw')
                ->withInput($request->only('amount', 'method', 'account_number', 'account_name', 'bank_name'))
                ->with('error', 'Withdrawal blocked by verification requirements: '.$exception->getMessage());
        } catch (WithdrawalException|FinancialException $exception) {
            return redirect()->route('player.withdraw')
                ->withInput($request->only('amount', 'method', 'account_number', 'account_name', 'bank_name'))
                ->with('error', $exception->getMessage());
        }

        return redirect()->route('player.withdraw')->with('success', sprintf(
            'Withdrawal %s requested for %s — pending approval. Payout is processed within %d hours.',
            (string) $withdrawal->reference_number,
            Money::of((string) $withdrawal->amount, Currency::THB)->format(),
            (int) ($limits['processing_hours'] ?? 24),
        ));
    }

    /**
     * Profile and account settings page.
     */
    public function profile(Request $request): View
    {
        /** @var User $user */
        $user = Auth::user();

        return view('player.profile', [
            'user' => $user,
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
    public function updatePassword(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $request->validate([
            'current_password' => ['required', 'current_password'],
            // Audit S2: the SAME centralised rule registration and reset
            // use — no weaker local policy on the profile surface.
            'new_password' => ['required', 'string', new StrongPasswordRule()],
        ]);

        $user->update([
            'password' => Hash::make($request->string('new_password')->value()),
        ]);

        return redirect()->route('player.profile')->with('success', 'Password updated successfully.');
    }

    /**
     * Update responsible gaming limits.
     */
    public function updateLimits(Request $request): RedirectResponse
    {
        $request->validate([
            'daily_deposit_limit' => ['nullable', 'numeric', 'min:100'],
            'max_bet_stake' => ['nullable', 'numeric', 'min:10'],
            'monthly_loss_limit' => ['nullable', 'numeric', 'min:500'],
        ]);

        return redirect()->route('player.profile')->with('success', 'Responsible gaming limits saved.');
    }
}
