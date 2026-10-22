<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Models\Deposit;

/** Non-secret server-owned account label. It is not a provider credential or country/merchant approval. */
final class DepositProviderAccountService
{
    public const KEY = 'inbound_provider_account';

    public function current(string $provider): string
    {
        $provider = app(WithdrawalProviderAccountService::class)->canonical($provider);
        $all = config('payment.deposit_reference_accounts', []);
        if (! is_array($all)) {
            app(OutboundReferenceHistoryService::class)->refuse();
        }

        return $this->label(array_key_exists($provider, $all) ? $all[$provider] : WithdrawalProviderAccountService::LEGACY);
    }

    public function stored(Deposit $row): string
    {
        $m = $row->metadata ?? [];

        return $this->label(array_key_exists(self::KEY, $m) ? $m[self::KEY] : WithdrawalProviderAccountService::LEGACY);
    }

    public function assertActive(Deposit $row): string
    {
        $scope = $this->stored($row);
        if ($scope !== $this->current($row->method->value)) {
            app(OutboundReferenceHistoryService::class)->refuse();
        }

        return $scope;
    }

    private function label(mixed $scope): string
    {
        if (! is_string($scope) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/D', $scope) !== 1) {
            app(OutboundReferenceHistoryService::class)->refuse();
        }

        return $scope;
    }
}
