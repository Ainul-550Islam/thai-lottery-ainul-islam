<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionPolicyContract;
use App\DTOs\Wallet\ConversionPolicyDecisionData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\WalletConversionRejectionReason;
use App\Exceptions\Wallet\ConversionDisabledException;
use App\Exceptions\Wallet\ConversionException;
use App\Support\Wallet\ConversionCurrency;
use Illuminate\Contracts\Config\Repository;

final class ConversionPolicyGuard implements ConversionPolicyContract
{
    public function __construct(
        private readonly Repository $config,
        private readonly ConversionFeeCalculator $fees,
        private readonly ConversionLimitService $limits,
        private readonly ConversionMemberEligibility $members,
        private readonly ConversionWalletEligibility $walletEligibility,
    ) {}

    public function assertAllowed(ConversionRequestData $request): void
    {
        $this->check($request, false);
    }

    private function check(ConversionRequestData $request, bool $advisory): void
    {
        if (! (bool) $this->config->get('conversions.enabled', true)) {
            throw ConversionDisabledException::feature();
        }
        if (! (bool) $this->config->get('conversions.directions.'.$request->direction->value.'.enabled', true)) {
            throw ConversionDisabledException::forDirection($request->direction);
        }
        if ($request->userId <= 0 || preg_match('/^\d{1,18}(\.\d{1,2})?$/', $request->amount) !== 1
            || bccomp($request->amount, '0', 2) <= 0) {
            throw ConversionException::failure('amount_invalid', 'A positive exact decimal amount is required.');
        }
        if ($request->currency->value !== ConversionCurrency::configured($this->config)->value) {
            throw ConversionException::failure('currency_mismatch', 'Conversion never crosses currencies.');
        }
        $this->members->assertAllowed($request->userId);
        $price = $this->fees->breakdownFor($request->direction, $request->amount, $request->currency->value);
        if (! $price->isConfigured || bccomp($price->netAmount, '0', 2) <= 0) {
            throw ConversionException::failure('fee_rule_invalid', 'No valid, effective conversion fee is configured.');
        }
        $this->limits->assertAllowed($request->userId, $request->amount, $request->currency);
        if ($advisory) {
            $this->walletEligibility->assertAdvisoryAllowed($request);
        } else {
            $this->walletEligibility->assertAllowed($request);
        }
    }

    public function evaluate(ConversionRequestData $request): ConversionPolicyDecisionData
    {
        try {
            $this->check($request, true);

            return ConversionPolicyDecisionData::allow(['policy' => true]);
        } catch (ConversionException $error) {
            // Unknown failures remain closed; never turn an unknown refusal into allowance.
            $reason = $error->rejectionReason() ?? WalletConversionRejectionReason::FeatureDisabled;

            return ConversionPolicyDecisionData::deny($reason, ['policy' => false], message: $error->getMessage());
        }
    }
}
