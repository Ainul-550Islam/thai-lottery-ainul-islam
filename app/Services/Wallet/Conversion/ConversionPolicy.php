<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionPolicyContract;
use App\DTOs\Wallet\ConversionPolicyDecisionData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\WalletConversionRejectionReason;
use App\Exceptions\Wallet\ConversionDisabledException;
use App\Exceptions\Wallet\ConversionException;
use App\Exceptions\Wallet\ConversionLimitExceededException;
use App\Support\Wallet\ConversionCurrency;

/**
 * Every reason this platform refuses a conversion, answered by one object.
 *
 * evaluate() is a read-only verdict: no writes, no locks, no money arithmetic
 * beyond exact decimal comparison. The member form asks it whether ANY amount
 * could work (so a suspended member is told why, instead of being invited to
 * type into a form that can only refuse them), and the engine's execution path
 * enforces the same gates in the same fixed order, so the API, the UI and the
 * audit trail explain a denial identically.
 */
final class ConversionPolicy implements ConversionPolicyContract
{
    public function __construct(
        private readonly ConversionFeeCalculator $fees,
        private readonly ConversionLimitService $limits,
        private readonly ConversionWalletEligibility $wallets,
        private readonly ConversionMemberEligibility $members,
    ) {}

    public function evaluate(ConversionRequestData $request): ConversionPolicyDecisionData
    {
        // A preview never reserves wallet or member facts, even when invoked by
        // a caller that already has an unrelated transaction.
        return $this->evaluatePolicy($request, false);
    }

    private function evaluatePolicy(ConversionRequestData $request, bool $decisive): ConversionPolicyDecisionData
    {
        $checks = [];

        // 0. The lane must know what currency it is denominated in, and the request
        //    must be in that currency. This comes FIRST because everything below is
        //    arithmetic in a denomination: limits, fees and balances are all expressed
        //    in it, so a wrong or missing denomination makes every later answer
        //    meaningless rather than merely wrong.
        try {
            $configured = ConversionCurrency::configured();
        } catch (ConversionException) {
            return ConversionPolicyDecisionData::deny(WalletConversionRejectionReason::CurrencyNotConfigured, $checks);
        }
        $checks['currency_configured'] = true;

        $checks['currency_matches'] = $request->currency === $configured;
        if (! $checks['currency_matches']) {
            return $this->deny(WalletConversionRejectionReason::CurrencyMismatch, $checks, $request);
        }

        // 1. The feature must be on.
        $checks['feature_enabled'] = (bool) config('conversions.enabled', true);
        if (! $checks['feature_enabled']) {
            return $this->deny(WalletConversionRejectionReason::FeatureDisabled, $checks, $request);
        }

        // 2. The direction must be on.
        $checks['direction_enabled'] = (bool) config('conversions.directions.'.$request->direction->value.'.enabled', true);
        if (! $checks['direction_enabled']) {
            return $this->deny(WalletConversionRejectionReason::DirectionDisabled, $checks, $request);
        }

        // 3. There must be a price at all — no price is unconfigured, never free.
        $breakdown = $this->fees->breakdownFor($request->direction, $request->amount, $request->currency->value);
        $checks['price_configured'] = $breakdown->isConfigured;
        if (! $breakdown->isConfigured) {
            return $this->deny(WalletConversionRejectionReason::FeatureDisabled, $checks, $request);
        }

        // 4. The amount must be a positive decimal.
        $checks['amount_valid'] = bccomp($request->amount, '0', 2) === 1;
        if (! $checks['amount_valid']) {
            return $this->deny(WalletConversionRejectionReason::AmountInvalid, $checks, $request);
        }

        // 5-7. The amount must sit inside its envelope, and the rolling windows
        // must have head-room for it.
        //
        // The per-transaction bounds are answered from configuration by the value
        // object. The rolling windows are NOT re-implemented here: the limit service
        // owns the history read, the exact decimal summation and the unprovable-row
        // refusal, and this method only translates its answer into a decision. Two
        // implementations of "how much has this member already converted today" is
        // how a page ends up promising head-room the engine then refuses.
        $limits = $this->limits->limits($request->currency);
        $violations = $limits->transactionViolations($request->amount);
        $checks['amount_minimum'] = ! in_array(WalletConversionRejectionReason::BelowMinimum, $violations, true);
        $checks['amount_maximum'] = ! in_array(WalletConversionRejectionReason::AboveMaximum, $violations, true);
        if ($violations !== []) {
            return $this->deny($violations[0], $checks, $request);
        }

        $checks['daily_limit'] = true;
        $checks['monthly_limit'] = true;

        try {
            if ($decisive) {
                $this->limits->assertAllowedForExecution($request->userId, $request->amount, $request->currency);
            } else {
                $this->limits->assertAllowed($request->userId, $request->amount, $request->currency);
            }
        } catch (ConversionLimitExceededException $breached) {
            $reason = $breached->rejectionReason() ?? WalletConversionRejectionReason::DailyLimitExceeded;
            $checks[$reason === WalletConversionRejectionReason::MonthlyLimitExceeded ? 'monthly_limit' : 'daily_limit'] = false;

            return $this->deny($reason, $checks, $request, $breached->getMessage());
        } catch (ConversionException $unproven) {
            // An unprovable history is a refusal, not a zero. The member is told the
            // limit cannot be shown right now rather than that they have head-room.
            $checks['daily_limit'] = false;
            $checks['monthly_limit'] = false;

            return $this->deny(
                WalletConversionRejectionReason::DailyLimitExceeded,
                $checks,
                $request,
                $unproven->getMessage(),
            );
        }

        // 11-14. The member must exist, be verified, and be in good standing.
        //
        // The facts are NOT read here from the User model. ConversionMemberEligibility
        // owns them — current rows rather than a cached principal or an old snapshot,
        // the canonical self-exclusion table as well as the legacy projection including
        // soft-deleted safety rows, and KYC evidence that is neither deleted nor
        // expired — and this method only translates its refusal into a decision. A
        // second copy of those rules is how the page came to offer a form that the
        // engine then refused, and a model-based answer is what let a stale
        // `users.status` survive a mid-request suspension.
        $checks['member_eligible'] = true;
        try {
            if ($decisive) {
                $this->members->assertAllowedForExecution($request->userId);
            } else {
                $this->members->assertAdvisoryAllowed($request->userId);
            }
        } catch (ConversionException $refusal) {
            $checks['member_eligible'] = false;
            $reason = $refusal->rejectionReason();

            if ($reason === null || ! in_array($reason, [
                WalletConversionRejectionReason::AccountSuspended,
                WalletConversionRejectionReason::SelfExclusionActive,
                WalletConversionRejectionReason::KycNotVerified,
            ], true)) {
                // Any other refusal (a lost transaction, an unknown code) is still a
                // refusal. Closed by default: an unrecognised reason never becomes an
                // allowance, and it is reported as an account restriction because that
                // is the only safe member-facing explanation we can stand behind.
                $reason = WalletConversionRejectionReason::AccountSuspended;
            }

            return $this->deny($reason, $checks, $request, $refusal->getMessage());
        }

        // The wallet facts come AFTER the person checks on purpose: a member with no
        // wallets at all is first a member whose identity is unverified, and telling
        // them "wallet locked" would send them to support about the wrong thing —
        // wallets are created when identity is verified, so the wallet problem is a
        // consequence, never the cause.
        //
        // The facts themselves are NOT re-implemented here. ConversionWalletEligibility
        // owns them — both wallets rather than just the source, deletion, the wallet
        // lock, raw status values the enum does not know, and the available balance
        // rather than the gross one — and this method only translates its refusal into
        // a decision. Two copies of "is this wallet usable" is how the page came to
        // invite members into a form the engine would refuse.
        try {
            if ($decisive) {
                $this->wallets->pairForExecution($request);
            } else {
                $this->wallets->assertAdvisoryAllowed($request);
            }
            $checks['wallet_eligible'] = true;
        } catch (ConversionException $refusal) {
            $checks['wallet_eligible'] = false;

            return $this->deny(match ((string) $refusal->errorCode()) {
                WalletConversionRejectionReason::InsufficientBalance->value => WalletConversionRejectionReason::InsufficientBalance,
                default => WalletConversionRejectionReason::WalletLocked,
            }, $checks, $request);
        }

        return ConversionPolicyDecisionData::allow($checks, ['amount' => $request->amount]);
    }

    public function assertAllowed(ConversionRequestData $request): void
    {
        $decision = $this->evaluatePolicy($request, \Illuminate\Support\Facades\DB::transactionLevel() > 0);

        if ($decision->isAllowed()) {
            return;
        }

        $reason = $decision->reason ?? WalletConversionRejectionReason::FeatureDisabled;

        if ($reason === WalletConversionRejectionReason::FeatureDisabled) {
            throw ConversionDisabledException::feature();
        }

        if ($reason === WalletConversionRejectionReason::DirectionDisabled) {
            throw ConversionDisabledException::forDirection($request->direction);
        }

        throw ConversionException::failure(
            $reason->value,
            $decision->message ?? ($reason->label().'.'),
            ['direction' => $request->direction->value, 'amount' => $request->amount],
        );
    }

    /**
     * @param  array<string, bool|string>  $checks
     */
    private function deny(
        WalletConversionRejectionReason $reason,
        array $checks,
        ConversionRequestData $request,
        ?string $message = null,
    ): ConversionPolicyDecisionData {
        return ConversionPolicyDecisionData::deny(
            $reason,
            $checks,
            ['direction' => $request->direction->value, 'amount' => $request->amount],
            $message ?? ($reason->label().'.'),
        );
    }
}
