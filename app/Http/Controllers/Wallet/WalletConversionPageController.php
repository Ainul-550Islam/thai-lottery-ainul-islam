<?php

declare(strict_types=1);

namespace App\Http\Controllers\Wallet;

use App\Contracts\Wallet\ConversionPolicyContract;
use App\Contracts\Wallet\WalletConversionServiceContract;
use App\DTOs\Wallet\ConversionHistoryFilterData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\Currency;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionRejectionReason;
use App\Http\Requests\Wallet\ConversionQuoteRequest;
use App\Models\Wallet;
use App\Services\Wallet\Conversion\ConversionFeeCalculator;
use App\Services\Wallet\Conversion\ConversionHistoryService;
use App\Services\Wallet\Conversion\ConversionLimitService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The member-facing Cash <-> Win page.
 *
 * WHAT A MEMBER SEES, AND WHY EACH PIECE IS SHOWN
 * The page answers four questions in order: what does it cost, how much can I
 * move, what is my current position, and what have I done before. The price and
 * the limits are rendered from the same services the engine charges from, so
 * what is displayed is by construction what will be charged — a page that
 * computed its own preview would eventually disagree with the engine, and the
 * member would be right to call that a lie.
 *
 * WHY THE QUOTE IS TAKEN SERVER-SIDE ON PAGE LOAD
 * The member landing on the page has an amount in mind; the browser cannot know
 * the fee before asking. Issuing a quote at page render means the first thing a
 * member sees is a real, actionable number with a real expiry rather than a
 * placeholder that changes when they submit. It is not a commitment: nothing
 * moves until the member explicitly converts.
 *
 * WHY THE PAGE IS STILL RENDERED WHEN THE LANE IS CLOSED
 * A disabled direction renders the same page with the controls replaced by an
 * explanation and the published fee table intact. Redirecting away would leave a
 * member who bookmarked the page staring at a login screen; showing an empty
 * error would hide the fact that the schedule they can read on /fees still
 * applies. An honest "not right now, here is the price when it returns" is the
 * only version of this page that does not waste someone's afternoon.
 */
class WalletConversionPageController
{
    public function __construct(
        private readonly WalletConversionServiceContract $conversions,
        private readonly ConversionFeeCalculator $fees,
        private readonly ConversionLimitService $limits,
        private readonly ConversionPolicyContract $policy,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $userId = (int) $user->getKey();
        $currency = Currency::tryFrom((string) config('conversions.currency', '')) ?? Currency::primary();

        $requested = $request->query('direction');
        $direction = is_string($requested)
            ? (WalletConversionDirection::tryFrom($requested) ?? $this->defaultDirection())
            : $this->defaultDirection();

        $wallets = Wallet::query()
            ->where('user_id', $userId)
            ->get()
            ->keyBy(static fn (Wallet $wallet): string => $wallet->type?->value ?? 'unknown');

        $breakdown = $this->fees->breakdownFor($direction, '0.00', $currency->value);
        $limits = $this->limits->limits($currency);

        return view('wallet.conversion.index', [
            'user' => $user,
            'direction' => $direction,
            'directions' => $this->availableDirections(),
            'currency' => $currency,
            'wallets' => $wallets,
            'sourceWallet' => $wallets->get($direction->fromType()->value),
            'destinationWallet' => $wallets->get($direction->toType()->value),
            'feeBreakdown' => $breakdown,
            'limits' => $limits,
            'dailyRemaining' => $this->limits->remainingDaily($userId, $currency),
            'dailyTotal' => $this->limits->dailyTotal($userId),
            'monthlyTotal' => $this->limits->monthlyTotal($userId),
            'openQuotes' => $this->conversions->openQuotesForUser($userId),
            'summary' => $this->conversions->summaryForUser($userId),
            'history' => $this->conversions->history(new ConversionHistoryFilterData(
                userId: $userId,
                direction: $direction,
                perPage: (int) config('conversions.history.per_page', 20),
            )),
            'conversionEnabled' => (bool) config('conversions.enabled', true),
            'directionEnabled' => (bool) config('conversions.directions.'.$direction->value.'.enabled', true),
            'blockedReason' => $this->blockedReason($userId, $direction, $currency),
            'quoteTtlSeconds' => (int) config('conversions.quotes.ttl_seconds', 120),
            'showFeeBreakdown' => (bool) config('conversions.ui.show_fee_breakdown', true),
            'showLimits' => (bool) config('conversions.ui.show_limits', true),
        ]);
    }

    /**
     * Show one conversion, for the member who owns it.
     *
     * The lookup is scoped by user id rather than filtered after the fact: an
     * unscoped fetch followed by an ownership comparison is one refactor away
     * from leaking somebody else's financial history.
     */
    public function show(Request $request, string $reference): View|RedirectResponse
    {
        $userId = (int) $request->user()->getKey();
        $conversion = $this->conversions->findForUser($reference, $userId);

        if ($conversion === null) {
            // A missing row and somebody else's row are answered identically, so
            // the response cannot be used to discover which references exist.
            return redirect()
                ->route('player.wallet.conversion')
                ->with('error', 'That conversion could not be found.');
        }

        return view('wallet.conversion.show', [
            'conversion' => $conversion,
            'timeline' => app(ConversionHistoryService::class)
                ->auditTimeline($reference, $userId),
        ]);
    }

    /**
     * The fee a specific amount would cost, for the live preview on the form.
     *
     * Implemented as a JSON endpoint rather than as client-side arithmetic: the
     * browser must never compute a fee, or the page and the engine can disagree
     * and only one of them is authoritative. The endpoint is thin and read-only,
     * and validates through the same request class the quote endpoint uses.
     */
    public function preview(ConversionQuoteRequest $request): JsonResponse
    {
        $currency = $request->currency();
        $direction = $request->direction();
        $amount = (string) $request->validated('amount');

        $breakdown = $this->fees->breakdownFor($direction, $amount, $currency->value);

        return response()->json([
            'direction' => $direction->value,
            'amount_in' => $breakdown->hasFee() || bccomp($amount, '0', 2) === 1 ? bcadd($amount, '0', 2) : '0.00',
            'fee_amount' => $breakdown->feeAmount,
            'amount_out' => bccomp($amount, '0', 2) === 1
                ? bcsub(bcadd($amount, '0', 2), $breakdown->feeAmount, 2)
                : '0.00',
            'rate' => $breakdown->rate,
            'percentage' => $breakdown->percentage,
            'currency' => $currency->value,
            'rule_version' => $breakdown->ruleVersion,
            'configured' => $breakdown->isConfigured,
            'limits' => $this->limits->limits($currency)->toArray(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    /**
     * EVERY direction, open or closed.
     *
     * WHY CLOSED ONES ARE STILL LISTED
     * The switch is how a member discovers that the other direction exists. When
     * only the open directions were passed down, a member arriving while
     * cash-to-win was closed saw a page with no switch at all and no way to learn
     * that their winnings could still be moved out — which is precisely the
     * moment they most need to know it. The partial already renders an
     * unavailable direction as a disabled label with an explanation instead of a
     * link, so the decision belongs there and the controller hands it the full
     * list.
     *
     * @return array<string, string>
     */
    private function availableDirections(): array
    {
        $directions = [];

        foreach (WalletConversionDirection::cases() as $direction) {
            $directions[$direction->value] = $direction->label();
        }

        return $directions;
    }

    /**
     * The reason this member could not convert ANY amount right now, or null.
     *
     * WHY THE PAGE ASKS THE POLICY INSTEAD OF ASSUMING
     * A form is a promise that pressing Convert can work. A suspended member, a
     * member whose identity is unverified, a self-excluded member or a member
     * whose wallet is locked is refused by the policy no matter what they type,
     * so rendering the form to them is an invitation to a refusal — and the
     * member reasonably reads a refusal as the platform's fault rather than as
     * their own standing. The check is made with the MINIMUM amount, which makes
     * it the most permissive question possible: only a refusal that no amount
     * could avoid is treated as a block.
     *
     * Refusals that a different amount would avoid (below the floor, above the
     * ceiling, over the daily allowance, an expired price, a rate limit) leave
     * the form exactly where it is: the member can fix those by typing, and the
     * limits panel tells them what is left.
     */
    private function blockedReason(int $userId, WalletConversionDirection $direction, Currency $currency): ?WalletConversionRejectionReason
    {
        if (! (bool) config('conversions.enabled', true)) {
            return WalletConversionRejectionReason::FeatureDisabled;
        }

        if (! (bool) config('conversions.directions.'.$direction->value.'.enabled', true)) {
            return WalletConversionRejectionReason::DirectionDisabled;
        }

        try {
            $decision = $this->policy->evaluate(new ConversionRequestData(
                direction: $direction,
                amount: $this->limits->limits($currency)->minimum(),
                currency: $currency,
                channel: WalletConversionChannel::Web,
                userId: $userId,
            ));
        } catch (Throwable) {
            // A page must render even when the standing check cannot be
            // evaluated — a missing wallet row, an eligibility service that is
            // briefly unavailable. Falling back to "show the form" keeps a
            // rendering failure from becoming a member-facing outage, and the
            // engine refuses the conversion at execution time in any case.
            return null;
        }

        if ($decision->isAllowed()) {
            return null;
        }

        $reason = $decision->reason;

        if ($reason === null) {
            return null;
        }

        return $this->anyAmountCouldSucceed($reason) ? null : $reason;
    }

    /**
     * True when a different amount could still be accepted, so the form stays.
     */
    private function anyAmountCouldSucceed(WalletConversionRejectionReason $reason): bool
    {
        return in_array($reason, [
            WalletConversionRejectionReason::AmountInvalid,
            WalletConversionRejectionReason::BelowMinimum,
            WalletConversionRejectionReason::AboveMaximum,
            WalletConversionRejectionReason::DailyLimitExceeded,
            WalletConversionRejectionReason::MonthlyLimitExceeded,
            WalletConversionRejectionReason::QuoteExpired,
            WalletConversionRejectionReason::QuoteNotFound,
            WalletConversionRejectionReason::IdempotencyConflict,
            WalletConversionRejectionReason::RateLimited,
        ], true);
    }

    private function defaultDirection(): WalletConversionDirection
    {
        $configured = (string) config('conversions.ui.default_direction', WalletConversionDirection::CashToWin->value);

        return WalletConversionDirection::tryFrom($configured) ?? WalletConversionDirection::CashToWin;
    }
}
