<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\Currency;
use App\Enums\GatewayIntegrationStatus;
use App\Enums\PaymentDirection;
use App\Enums\PaymentMethod;
use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentProviderStatus;
use App\Exceptions\FinancialException;
use App\Models\PaymentMethodConfig;
use App\Models\PaymentProvider;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Drivers\BankTransferGateway;
use App\Services\Payment\Drivers\BkashGateway;
use App\Services\Payment\Drivers\CryptoGateway;
use App\Services\Payment\Drivers\NagadGateway;
use App\Services\Payment\Drivers\PromptPayGateway;
use App\Services\Payment\Drivers\SandboxDeterministicGateway;
use App\Services\Payment\Drivers\StripeGateway;
use App\Services\Payment\Drivers\ThaiBankPayoutGateway;
use App\Services\Operations\ProviderReadinessService;
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

    public function isDepositCapable(string|PaymentMethod $name, ?Currency $currency = null): bool
    {
        $key = $name instanceof PaymentMethod ? $name->value : strtolower(trim($name));
        if (! (bool) $this->config->get('payment.deposit.enabled', false)
            || ! in_array($key, (array) $this->config->get('payment.deposit.allowed_methods', []), true)) {
            return false;
        }
        $currency ??= Currency::tryFromCode((string) $this->config->get('payment.currency.default', 'THB'));
        try {
            $driver = $this->driver($key);
            $readiness = app(ProviderReadinessService::class)->gateway($key, 'deposit', $currency);
        } catch (\Throwable) {
            return false;
        }

        return $currency !== null && $driver->supportsDeposit() && $driver->supportsCurrency($currency)
            && $this->isEnabled($key) && $readiness->ready && $this->matchesSiteMarket($key, $currency);
    }

    public function isWithdrawalCapable(string|PaymentMethod $name, ?Currency $currency = null): bool
    {
        if (! (bool) $this->config->get('payment.withdrawal.enabled', false)) {
            return false;
        }
        $key = $name instanceof PaymentMethod ? $name->value : strtolower(trim($name));
        $rail = $key === 'promptpay' ? 'thai_bank' : $key;
        $currency ??= Currency::tryFromCode((string) $this->config->get('payment.currency.default', 'THB'));
        try {
            $driver = $this->driver($rail);
            $readiness = app(ProviderReadinessService::class)->gateway($rail, 'withdrawal', $currency);
        } catch (\Throwable) {
            return false;
        }

        $promptPaySupported = $key !== 'promptpay'
            || ((bool) config('thai_payout.promptpay.enabled', false)
                && strtolower((string) config('thai_payout.default_bank', '')) === 'scb'
                && (bool) config('thai_payout.promptpay.banks.scb', false)
                && in_array('mobile', (array) config('thai_payout.promptpay.proxy_types', []), true)
                && in_array('national_id', (array) config('thai_payout.promptpay.proxy_types', []), true));

        return $currency !== null && $driver->supportsCurrency($currency)
            && ($driver->supportsWithdrawal() || $this->supportsManualDisbursement($key)) && $this->isEnabled($rail)
            && $readiness->ready && $this->matchesSiteMarket($key, $currency) && $promptPaySupported;
    }

    /**
     * The legacy database registry is an inventory, not a second readiness
     * authority. A row is offerable only when its canonical driver, configured
     * merchant market, currency, direction and gateway manager all agree.
     * The relation must be eager-loaded; missing evidence fails closed.
     */
    public function isRegistryOfferCapable(PaymentMethodConfig $offer, ?PaymentDirection $direction = null): bool
    {
        $method = strtolower(trim((string) $offer->method_code));
        $gateway = match ($method) {
            'stripe', 'card' => 'stripe',
            'bkash' => 'bkash',
            'nagad' => 'nagad',
            'bank_transfer', 'manual' => 'bank_transfer',
            'promptpay' => 'promptpay',
            default => null,
        };
        $currency = Currency::tryFromCode((string) $offer->currency);
        $provider = $offer->relationLoaded('provider') ? $offer->getRelation('provider') : null;
        if ($gateway === null || $currency === null || ! $provider instanceof PaymentProvider
            || $offer->status !== PaymentMethodStatus::Enabled || $provider->status !== PaymentProviderStatus::Active
            || strtolower(trim((string) $provider->code)) !== $gateway
            || strtolower(trim((string) $provider->driver)) !== $gateway) {
            return false;
        }
        $providerCurrencies = array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            array_filter((array) $provider->supported_currencies, 'is_string'),
        );
        if (! in_array($currency->value, $providerCurrencies, true)) {
            return false;
        }

        $depositAllowed = in_array($gateway, (array) $this->config->get('payment.deposit.allowed_methods', []), true);
        $withdrawalAllowed = in_array($gateway, (array) $this->config->get('payment.withdrawal.allowed_methods', []), true);

        return (($direction === null || $direction === PaymentDirection::Deposit)
                && $depositAllowed && $this->isDepositCapable($gateway, $currency))
            || (($direction === null || $direction === PaymentDirection::Withdrawal)
                && $withdrawalAllowed && $this->isWithdrawalCapable($gateway, $currency));
    }

    private function supportsManualDisbursement(string $key): bool
    {
        return in_array($key, ['bkash', 'nagad', 'bank_transfer'], true);
    }

    private function matchesSiteMarket(string $key, Currency $currency): bool
    {
        $market = (array) $this->config->get('payment.markets.'.$key, []);
        $currencies = array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            array_filter((array) ($market['currencies'] ?? []), 'is_string'),
        );
        $countries = array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            array_filter((array) ($market['countries'] ?? []), 'is_string'),
        );
        $siteCountry = strtoupper(trim((string) $this->config->get('payment.country', 'TH')));

        return in_array($currency->value, $currencies, true)
            && in_array($siteCountry, $countries, true);
    }

    /**
     * Resolve a driver for a DEPOSIT and refuse anything not enabled,
     * not configured or not deposit-capable BEFORE a single record is
     * created. Web and API initiation paths must use this, never the bare
     * driver() resolver.
     *
     * @throws FinancialException with a stable code per refusal reason
     */
    public function depositDriver(PaymentMethod $method, ?Currency $currency = null): PaymentGatewayInterface
    {
        $driver = $this->driver($method);

        if (! (bool) $this->config->get('payment.gateways.'.$method->value.'.enabled', false)) {
            throw FinancialException::withCode(
                'payment_gateway_disabled',
                sprintf('Payment gateway [%s] is not enabled.', $driver->name()),
                ['gateway' => $driver->name()],
            );
        }

        $currency ??= Currency::tryFromCode((string) $this->config->get('payment.currency.default', 'THB'));
        $readiness = app(ProviderReadinessService::class)->gateway($method->value, 'deposit', $currency);
        if (! in_array($driver->status(), [GatewayIntegrationStatus::FullyImplemented, GatewayIntegrationStatus::ConfiguredOnly], true)) {
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
        if (! (bool) $this->config->get('payment.deposit.enabled', false)) {
            throw FinancialException::withCode('deposits_disabled', 'Deposits are disabled on this deployment.');
        }
        if (! in_array($method->value, (array) $this->config->get('payment.deposit.allowed_methods', []), true)) {
            throw FinancialException::withCode('payment_method_not_allowed', 'The payment method is not allowed for deposits.');
        }

        if ($currency === null || ! $driver->supportsCurrency($currency)) {
            throw FinancialException::withCode(
                'unsupported_gateway_currency',
                sprintf('Gateway [%s] does not support the requested currency.', $driver->name()),
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
    public function withdrawalDriver(string|PaymentMethod $method, ?Currency $currency = null): PaymentGatewayInterface
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

        $currency ??= Currency::tryFromCode((string) $this->config->get('payment.currency.default', 'THB'));
        if ($currency === null || ! $driver->supportsCurrency($currency)) {
            throw FinancialException::withCode(
                'unsupported_gateway_currency',
                sprintf('Gateway [%s] does not support the requested currency.', $driver->name()),
                ['gateway' => $key, 'currency' => $currency?->value],
            );
        }

        $readiness = app(ProviderReadinessService::class)->gateway($rail, 'withdrawal', $currency);
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
