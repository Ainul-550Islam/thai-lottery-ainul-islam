<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Enums\GatewayIntegrationStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Services\Payment\Contracts\PaymentGatewayInterface;
use App\Services\Payment\Drivers\BankTransferGateway;
use App\Services\Payment\Drivers\BkashGateway;
use App\Services\Payment\Drivers\CryptoGateway;
use App\Services\Payment\Drivers\NagadGateway;
use App\Services\Payment\Drivers\StripeGateway;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

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
    ) {
    }

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

            // ── A CONFIGURED RAIL WITH NO DRIVER GETS ITS OWN REFUSAL. ──────
            //
            // 'promptpay' appears in config/payment.php with an enabled flag, a
            // target, a webhook secret and a signature header — so it LOOKS
            // integrated. It has no driver, no PaymentMethod case and no callback
            // route. Falling through to the generic arm below would report
            // `unsupported_payment_gateway` for it, which reads as "somebody
            // mistyped a name" and sends an operator to check their spelling
            // instead of to the status block that explains the lane is unfinished.
            //
            // It still THROWS. Nothing here makes the rail work; the difference is
            // only that the refusal says which of the two problems it is.
            'promptpay' => throw FinancialException::withCode(
                'payment_gateway_not_implemented',
                'The [promptpay] rail is configured but has no driver: the Thai QR inbound lane is not built. '
                .'See the status block in config/payment.php for what wiring it requires.',
                ['gateway' => $key, 'reason' => 'configured_but_unimplemented'],
            ),

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

        return in_array($key, ['stripe', 'bkash', 'nagad', 'crypto', 'bank_transfer', 'manual'], true);
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
        try {
            $driver = $this->driver($name);
        } catch (FinancialException) {
            return false;
        }

        return $driver->supportsDeposit() && $this->isEnabled($name);
    }

    public function isWithdrawalCapable(string|PaymentMethod $name): bool
    {
        try {
            $driver = $this->driver($name);
        } catch (FinancialException) {
            return false;
        }

        return $driver->supportsWithdrawal() && $this->isEnabled($name);
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

        if (! $driver->isEnabled()) {
            throw FinancialException::withCode(
                'payment_gateway_disabled',
                sprintf('Payment gateway [%s] is not enabled.', $driver->name()),
                ['gateway' => $driver->name()],
            );
        }

        if (! $driver->supportsDeposit()) {
            throw FinancialException::withCode(
                'payment_gateway_not_deposit_capable',
                sprintf('Payment gateway [%s] does not support deposits.', $driver->name()),
                ['gateway' => $driver->name()],
            );
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
    public function withdrawalDriver(PaymentMethod $method): PaymentGatewayInterface
    {
        $driver = $this->driver($method);

        if (! $driver->isEnabled()) {
            throw FinancialException::withCode(
                'payment_gateway_disabled',
                sprintf('Payment gateway [%s] is not enabled.', $driver->name()),
                ['gateway' => $driver->name()],
            );
        }

        if (! $driver->supportsWithdrawal()) {
            throw FinancialException::withCode(
                'payment_gateway_not_withdrawal_capable',
                sprintf('Payment gateway [%s] does not support withdrawals.', $driver->name()),
                ['gateway' => $driver->name()],
            );
        }

        return $driver;
    }
}
