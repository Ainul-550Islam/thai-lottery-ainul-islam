<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Models\Withdrawal;

/** A non-secret server-owned merchant-account label, not merchant/country authorization. */
final class WithdrawalProviderAccountService
{
    public const METADATA_KEY = 'outbound_provider_account';

    public const LEGACY = 'legacy-primary';

    public function canonical(string $provider): string
    {
        if (PaymentMethod::tryFrom($provider) === null) {
            throw $this->refused();
        }

        return $provider === 'manual' ? 'bank_transfer' : $provider;
    }

    public function current(string $provider): string
    {
        $all = config('payment.withdrawal_reference_accounts', []);
        if (! is_array($all)) {
            throw $this->refused();
        }
        $provider = $this->canonical($provider);

        return array_key_exists($provider, $all) ? $this->label($all[$provider]) : self::LEGACY;
    }

    /** Read historical context without reinterpreting it as today's configured account. */
    public function stored(Withdrawal $row): string
    {
        $metadata = is_array($row->metadata) ? $row->metadata : [];
        $scope = array_key_exists(self::METADATA_KEY, $metadata) ? $this->label($metadata[self::METADATA_KEY]) : self::LEGACY;
        if (array_key_exists(WithdrawalDisbursementService::CLAIM, $metadata)) {
            $claim = $metadata[WithdrawalDisbursementService::CLAIM];
            if (! is_array($claim) || $this->label(array_key_exists('account_scope', $claim) ? $claim['account_scope'] : self::LEGACY) !== $scope) {
                throw $this->refused();
            }
        }

        return $scope;
    }

    public function assertActive(Withdrawal $row): string
    {
        $scope = $this->stored($row);
        if ($scope !== $this->current($row->method->value)) {
            throw $this->refused();
        }

        return $scope;
    }

    private function label(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/D', $value) !== 1) {
            throw $this->refused();
        }

        return $value;
    }

    private function refused(): FinancialException
    {
        return FinancialException::withCode('withdrawal_provider_account_conflict', 'Withdrawal provider account context is missing, malformed, changed or no longer active; reconcile without sending or releasing funds.');
    }
}
