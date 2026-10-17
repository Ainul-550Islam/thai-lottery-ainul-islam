<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionPolicyContract;
use App\DTOs\Wallet\ConversionPolicyDecisionData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\KycStatus;
use App\Enums\WalletConversionRejectionReason;
use App\Enums\WalletStatus;
use App\Exceptions\Wallet\ConversionDisabledException;
use App\Exceptions\Wallet\ConversionException;
use App\Models\User;
use App\Models\Wallet;

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
    ) {
    }

    public function evaluate(ConversionRequestData $request): ConversionPolicyDecisionData
    {
        $checks = [];

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
        $limits = $this->limits->limits($request->currency);
        $violations = array_merge(
            $limits->transactionViolations($request->amount),
            $limits->rollingViolations(
                $request->amount,
                $this->limits->dailyTotal($request->userId),
                $this->limits->monthlyTotal($request->userId),
            ),
        );
        $checks['amount_minimum'] = ! in_array(WalletConversionRejectionReason::BelowMinimum, $violations, true);
        $checks['amount_maximum'] = ! in_array(WalletConversionRejectionReason::AboveMaximum, $violations, true);
        $checks['daily_limit'] = ! in_array(WalletConversionRejectionReason::DailyLimitExceeded, $violations, true);
        $checks['monthly_limit'] = ! in_array(WalletConversionRejectionReason::MonthlyLimitExceeded, $violations, true);
        if ($violations !== []) {
            return $this->deny($violations[0], $checks, $request);
        }

        // 8-10. The source wallet must exist, be active, and hold the funds.
        $source = Wallet::query()
            ->where('user_id', $request->userId)
            ->where('type', $request->direction->fromType()->value)
            ->where('currency', $request->currency->value)
            ->first();

        $checks['wallet_present'] = $source instanceof Wallet;
        $checks['wallet_active'] = $source instanceof Wallet && $source->status === WalletStatus::Active;
        $available = $source instanceof Wallet ? (string) $source->balance : '0.00';
        $checks['balance_sufficient'] = bccomp($available, $request->amount, 2) >= 0;

        if (! $checks['wallet_present'] || ! $checks['wallet_active']) {
            return $this->deny(WalletConversionRejectionReason::WalletLocked, $checks, $request);
        }
        if (! $checks['balance_sufficient']) {
            return $this->deny(WalletConversionRejectionReason::InsufficientBalance, $checks, $request);
        }

        // 11-14. The member must exist, be verified, and be in good standing.
        $user = User::query()->find($request->userId);
        $checks['member_exists'] = $user instanceof User;
        if (! $checks['member_exists']) {
            return $this->deny(WalletConversionRejectionReason::AccountSuspended, $checks, $request);
        }

        /** @var User $user */
        $checks['kyc_verified'] = ! (bool) config('conversions.gates.require_kyc', true)
            || $user->kycStatus() === KycStatus::Verified;
        if (! $checks['kyc_verified']) {
            return $this->deny(WalletConversionRejectionReason::KycNotVerified, $checks, $request);
        }

        $checks['account_active'] = ! (bool) config('conversions.gates.block_if_suspended', true)
            || $user->isActive();
        if (! $checks['account_active']) {
            return $this->deny(WalletConversionRejectionReason::AccountSuspended, $checks, $request);
        }

        $checks['self_exclusion_clear'] = ! (bool) config('conversions.gates.block_if_self_excluded', true)
            || ! $user->isSelfExcluded();
        if (! $checks['self_exclusion_clear']) {
            return $this->deny(WalletConversionRejectionReason::SelfExclusionActive, $checks, $request);
        }

        return ConversionPolicyDecisionData::allow($checks, ['amount' => $request->amount]);
    }

    public function assertAllowed(ConversionRequestData $request): void
    {
        $decision = $this->evaluate($request);

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
    ): ConversionPolicyDecisionData {
        return ConversionPolicyDecisionData::deny(
            $reason,
            $checks,
            ['direction' => $request->direction->value, 'amount' => $request->amount],
            $reason->label().'.',
        );
    }
}
