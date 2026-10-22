<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionPolicyContract;
use App\DTOs\Wallet\ConversionPolicyDecisionData;
use App\DTOs\Wallet\ConversionRequestData;
use App\Enums\KycStatus;
use App\Enums\WalletConversionRejectionReason as Reason;
use App\Enums\WalletConversionStatus;
use App\Enums\WalletStatus;
use App\Exceptions\Wallet\ConversionException;
use App\Models\User;
use App\Models\Wallet;
use App\ValueObjects\ConversionLimits;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;

final class ConversionPolicyService implements ConversionPolicyContract
{
    public function __construct(private readonly Config $config) {}

    public function evaluate(ConversionRequestData $request): ConversionPolicyDecisionData
    {
        $checks = [];
        $currency = (string) $this->config->get('conversions.currency', $request->currency->value);
        $enabled = (bool) $this->config->get('conversions.enabled', false);
        $directionEnabled = (bool) $this->config->get('conversions.directions.'.$request->direction->value.'.enabled', false);
        $checks['feature_enabled'] = $enabled;
        $checks['direction_enabled'] = $directionEnabled;
        if (! $enabled) {
            return ConversionPolicyDecisionData::deny(Reason::FeatureDisabled, $checks);
        }
        if (! $directionEnabled) {
            return ConversionPolicyDecisionData::deny(Reason::DirectionDisabled, $checks);
        }
        if ($request->currency->value !== $currency) {
            return ConversionPolicyDecisionData::deny(Reason::CurrencyMismatch, $checks, ['configured_currency' => $currency]);
        }
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $request->amount) || bccomp($request->amount, '0', 2) <= 0) {
            return ConversionPolicyDecisionData::deny(Reason::AmountInvalid, $checks);
        }

        $limits = ConversionLimits::fromConfig((array) $this->config->get('conversions.limits', []), $request->currency);
        $violations = $limits->transactionViolations($request->amount);
        if ($violations !== []) {
            return ConversionPolicyDecisionData::deny($violations[0], $checks, ['amount' => $request->amount]);
        }

        $user = User::query()->find($request->userId);
        $checks['user_exists'] = $user instanceof User;
        if (! $user) {
            return ConversionPolicyDecisionData::deny(Reason::AccountRestricted, $checks);
        }
        if ((bool) $this->config->get('conversions.gates.require_kyc', true) && $user->kycStatus() !== KycStatus::Verified) {
            return ConversionPolicyDecisionData::deny(Reason::KycNotVerified, $checks);
        }
        if (! $user->isActive()) {
            return ConversionPolicyDecisionData::deny(Reason::AccountRestricted, $checks);
        }
        if ((bool) $this->config->get('conversions.gates.block_if_self_excluded', true) && $user->isSelfExcluded()) {
            return ConversionPolicyDecisionData::deny(Reason::SelfExclusionActive, $checks);
        }

        $source = Wallet::query()->where('user_id', $request->userId)->where('type', $request->fromWalletType()->value)->where('currency', $request->currency->value)->first();
        $destination = Wallet::query()->where('user_id', $request->userId)->where('type', $request->toWalletType()->value)->where('currency', $request->currency->value)->first();
        $checks['source_wallet_exists'] = $source instanceof Wallet;
        $checks['destination_wallet_exists'] = $destination instanceof Wallet;
        if (! $source || ! $destination) {
            return ConversionPolicyDecisionData::deny(Reason::InsufficientBalance, $checks);
        }
        if ($source->status !== WalletStatus::Active || $destination->status !== WalletStatus::Active) {
            return ConversionPolicyDecisionData::deny(Reason::WalletLocked, $checks);
        }
        if (bccomp((string) $source->balance, $request->amount, 2) < 0) {
            return ConversionPolicyDecisionData::deny(Reason::InsufficientBalance, $checks, ['available' => (string) $source->balance]);
        }

        $daily = $this->completedTotal($request->userId, CarbonImmutable::now()->startOfDay(), CarbonImmutable::now());
        $monthly = $this->completedTotal($request->userId, CarbonImmutable::now()->startOfMonth(), CarbonImmutable::now());
        $rolling = $limits->rollingViolations($request->amount, $daily, $monthly);
        if ($rolling !== []) {
            return ConversionPolicyDecisionData::deny($rolling[0], $checks, ['daily_total' => $daily, 'monthly_total' => $monthly]);
        }
        $checks['limits'] = true;
        return ConversionPolicyDecisionData::allow($checks, ['daily_total' => $daily, 'monthly_total' => $monthly]);
    }

    public function assertAllowed(ConversionRequestData $request): void
    {
        $decision = $this->evaluate($request);
        if (! $decision->allowed) {
            $reason = $decision->reason;
            throw ConversionException::failure($reason?->value ?? 'conversion_refused', $decision->reasonLabel(), $decision->context);
        }
    }

    private function completedTotal(int $userId, \DateTimeInterface $from, \DateTimeInterface $to): string
    {
        $value = DB::table('wallet_conversions')->where('user_id', $userId)
            ->where('status', WalletConversionStatus::Completed->value)
            ->whereBetween('completed_at', [$from, $to])->sum('amount_in');
        return bcadd((string) $value, '0', 2);
    }
}
