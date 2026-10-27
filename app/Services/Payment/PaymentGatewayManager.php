<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Drivers\BankTransferGateway;
use App\Services\Payment\Drivers\BkashGateway;
use App\Services\Payment\Drivers\CryptoGateway;
use App\Services\Payment\Drivers\NagadGateway;
use App\Services\Payment\Drivers\PromptPayGateway;
use App\Services\Payment\Drivers\SandboxDeterministicGateway;
use App\Services\Payment\Drivers\StripeGateway;
use App\Services\Payment\Drivers\ThaiBankPayoutGateway;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;

/**
 * Gateway factory and driver manager.
 */
class PaymentGatewayManager
{
    /**
     * @var array<string, PaymentGatewayInterface>
     */
    private array $drivers = [];

    public function __construct(
        private readonly Container $container,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Resolve a gateway driver by string name or PaymentMethod enum.
     *
     * @throws FinancialException
     */
    public function driver(string|PaymentMethod $name): PaymentGatewayInterface
    {
        $key = $name instanceof PaymentMethod ? $name->value : strtolower(trim($name));

        if (isset($this->drivers[$key])) {
            return $this->drivers[$key];
        }

        $driver = match ($key) {
            'stripe' => $this->container->make(StripeGateway::class),
            'bkash' => $this->container->make(BkashGateway::class),
            'nagad' => $this->container->make(NagadGateway::class),
            'crypto' => $this->container->make(CryptoGateway::class),
            'bank_transfer', 'manual' => $this->container->make(BankTransferGateway::class),

            'promptpay' => $this->container->make(PromptPayGateway::class),
            'thai_bank' => $this->container->make(ThaiBankPayoutGateway::class),
            'sandbox' => $this->container->make(SandboxDeterministicGateway::class),

            default => throw FinancialException::withCode(
                'unsupported_payment_gateway',
                sprintf('Payment gateway driver [%s] is not supported.', $key),
                ['gateway' => $key],
            ),
        };

        $this->drivers[$key] = $driver;

        return $driver;
    }

    /**
     * Resolve gateway for a payment method.
     */
    public function forMethod(PaymentMethod $method): PaymentGatewayInterface
    {
        return $this->driver($method->value);
    }

    /**
     * Check if a gateway driver exists.
     */
    public function hasDriver(string $name): bool
    {
        $key = strtolower(trim($name));

        return in_array($key, ['stripe', 'bkash', 'nagad', 'crypto', 'bank_transfer', 'promptpay', 'thai_bank', 'manual', 'sandbox'], true);
    }

    /**
     * Operator-only inventory. Internal routing keys are not client methods.
     *
     * @return array<string, array{status: string, enabled: bool, supports_deposit: bool, supports_withdrawal: bool}>
     */
    public function availability(): array
    {
        $availability = [];
        foreach (['stripe', 'bkash', 'nagad', 'crypto', 'bank_transfer', 'promptpay', 'thai_bank'] as $key) {
            try {
                $driver = $this->driver($key);
                $availability[$key] = [
                    'status' => $driver->status()->value,
                    'enabled' => $this->isEnabled($key),
                    'supports_deposit' => $driver->supportsDeposit(),
                    'supports_withdrawal' => $driver->supportsWithdrawal(),
                ];
            } catch (FinancialException) {
                $availability[$key] = [
                    'status' => GatewayIntegrationStatus::ConfiguredOnly->value,
                    'enabled' => false,
                    'supports_deposit' => $key !== 'thai_bank',
                    'supports_withdrawal' => $key !== 'promptpay',
                ];
            }
        }

        return $availability;
    }

    /**
     * Whether the gateway is switched on AND has its credentials present.
     *
     * Enabled means the operator turned it on; configured means the driver
     * reports it can actually be used (credentials, required settings).
     * A driver that merely EXISTS in the container must never be
     * invocable just because its class resolves.
     */
    public function isEnabled(string|PaymentMethod $name): bool
    {
        try {
            $driver = $this->driver($name);
        } catch (FinancialException) {
            return false;
        }

        // Integration maturity must back the flag: a simulated, partial or
        // unsupported driver is not invocable no matter what the flag says.
        $usableMaturity = in_array(
            $driver->status(),
            [GatewayIntegrationStatus::FullyImplemented, GatewayIntegrationStatus::ConfiguredOnly],
            true,
        );

        return $driver->isEnabled() && $usableMaturity;
    }

    public function isDepositCapable(string|PaymentMethod $name): bool
    {
        $key = $name instanceof PaymentMethod ? $name->value : strtolower(trim($name));
        try {
            $driver = $this->driver($key);
            $readiness = app(PaymentReadinessService::class)->evaluate($key, 'deposit');
        } catch (\Throwable) {
            return false;
        }

        $currency = Currency::tryFrom((string) $this->config->get('payment.currency.default', 'THB'));

        return $currency !== null && $driver->supportsDeposit() && $driver->supportsCurrency($currency)
            && $this->isEnabled($key) && $readiness->ready && $this->matchesSiteMarket($key, $currency);
    }

    public function isWithdrawalCapable(string|PaymentMethod $name): bool
    {
        $key = $name instanceof PaymentMethod ? $name->value : strtolower(trim($name));
        $rail = $key === 'promptpay' ? 'thai_bank' : $key;
        try {
            $driver = $this->driver($rail);
            $readiness = app(PaymentReadinessService::class)->evaluate($rail, 'withdrawal');
            $currency = Currency::tryFrom((string) $this->config->get('payment.currency.default', 'THB'));
        } catch (\Throwable) {
            return false;
        }

        $promptPaySupported = $key !== 'promptpay'
            || ((bool) config('thai_payout.promptpay.enabled', false)
                && strtolower((string) config('thai_payout.default_bank', '')) === 'scb'
                && (bool) config('thai_payout.promptpay.banks.scb', false)
                && in_array('mobile', (array) config('thai_payout.promptpay.proxy_types', []), true)
                && in_array('national_id', (array) config('thai_payout.promptpay.proxy_types', []), true));

        return $currency !== null && ($driver->supportsWithdrawal() || $this->supportsManualDisbursement($key)) && $this->isEnabled($rail)
            && $readiness->ready && $this->matchesSiteMarket($key, $currency) && $promptPaySupported;
    }

    private function supportsManualDisbursement(string $key): bool
    {
        return in_array($key, ['bkash', 'nagad', 'bank_transfer'], true);
    }

    private function matchesSiteMarket(string $key, Currency $currency): bool
    {
        $market = (array) $this->config->get('payment.markets.'.$key, []);
        $currencies = (array) ($market['currencies'] ?? []);
        $countries = (array) ($market['countries'] ?? []);
        $siteCountry = strtoupper((string) $this->config->get('payment.country', 'TH'));

        return in_array($currency->value, $currencies, true)
            && in_array($siteCountry, array_map('strtoupper', $countries), true);
    }

    /**
     * Resolve a driver for a DEPOSIT and refuse anything not enabled,
     * not configured or not deposit-capable BEFORE a single record is
     * created. Web and API initiation paths must use this, never the bare
     * driver() resolver.
     *
     * @throws FinancialException with a stable code per refusal reason
     */
    public function depositDriver(PaymentMethod $method): PaymentGatewayInterface
    {
        $driver = $this->driver($method);

        if (! (bool) $this->config->get('payment.gateways.'.$method->value.'.enabled', false)) {
            throw FinancialException::withCode(
                'payment_gateway_disabled',
                sprintf('Payment gateway [%s] is not enabled.', $driver->name()),
                ['gateway' => $driver->name()],
            );
        }

        $readiness = app(PaymentReadinessService::class)->evaluate($method->value, 'deposit');
        if ($driver->status() === GatewayIntegrationStatus::Unsupported) {
            throw FinancialException::withCode('payment_gateway_not_ready', trans('payment_ops.errors.payment_gateway_not_ready'), [
                'gateway' => $method->value,
                'issues' => $readiness->issues,
            ]);
        }

        if (! $driver->supportsDeposit()) {
            throw FinancialException::withCode(
                'payment_gateway_not_deposit_capable',
                sprintf('Payment gateway [%s] does not support deposits.', $driver->name()),
                ['gateway' => $driver->name()],
            );
        }

        $currency = Currency::tryFrom((string) $this->config->get('payment.currency.default', 'THB'));
        if ($currency === null || ! $driver->supportsCurrency($currency)) {
            throw FinancialException::withCode(
                'unsupported_gateway_currency',
                sprintf('Gateway [%s] does not support the configured site currency.', $driver->name()),
                ['gateway' => $driver->name(), 'currency' => $currency?->value],
            );
        }

        if (! $readiness->ready || ! $this->matchesSiteMarket($method->value, $currency)) {
            throw FinancialException::withCode('payment_gateway_not_ready', trans('payment_ops.errors.payment_gateway_not_ready'), ['gateway' => $method->value, 'issues' => $readiness->issues]);
        }

        return $driver;
    }

    /**
     * Resolve a driver for a WITHDRAWAL payout with the same fail-closed
     * contract, additionally honouring the manual-approval design: the
     * guard only resolves the driver; approval state transitions remain
     * owned by WithdrawalApprovalService.
     *
     * @throws FinancialException with a stable code per refusal reason
     */
    public function withdrawalDriver(string|PaymentMethod $method): PaymentGatewayInterface
    {
        $key = $method instanceof PaymentMethod ? $method->value : strtolower(trim($method));
        $rail = $key === 'promptpay' ? 'thai_bank' : $key;
        $driver = $this->driver($rail);
        if (! $driver->isEnabled()) {
            throw FinancialException::withCode(
                'payment_gateway_disabled',
                sprintf('Payment gateway [%s] is not enabled.', $driver->name()),
                ['gateway' => $driver->name()],
            );
        }

        if (! $driver->supportsWithdrawal() && ! $this->supportsManualDisbursement($key)) {
            throw FinancialException::withCode(
                'payment_gateway_not_withdrawal_capable',
                sprintf('Payment gateway [%s] does not support withdrawals.', $driver->name()),
                ['gateway' => $driver->name()],
            );
        }

        $currency = Currency::tryFrom((string) $this->config->get('payment.currency.default', 'THB'));
        if ($currency === null || ! $driver->supportsCurrency($currency)) {
            throw FinancialException::withCode(
                'unsupported_gateway_currency',
                sprintf('Gateway [%s] does not support the configured site currency.', $driver->name()),
                ['gateway' => $key, 'currency' => $currency?->value],
            );
        }

        $readiness = app(PaymentReadinessService::class)->evaluate($rail, 'withdrawal');
        if (! $readiness->ready || ! $this->matchesSiteMarket($key, $currency)) {
            throw FinancialException::withCode('payment_gateway_not_ready', trans('payment_ops.errors.payment_gateway_not_ready'), ['gateway' => $rail, 'issues' => $readiness->issues]);
        }

        if ($key === 'promptpay'
            && (! (bool) config('thai_payout.promptpay.enabled', false)
                || strtolower((string) config('thai_payout.default_bank', '')) !== 'scb'
                || ! (bool) config('thai_payout.promptpay.banks.scb', false)
                || ! in_array('mobile', (array) config('thai_payout.promptpay.proxy_types', []), true)
                || ! in_array('national_id', (array) config('thai_payout.promptpay.proxy_types', []), true))) {
            throw FinancialException::withCode('payment_gateway_not_withdrawal_capable', trans('payment_ops.errors.payment_gateway_not_ready'), ['gateway' => $key]);
        }

        return $driver;
    }
}
