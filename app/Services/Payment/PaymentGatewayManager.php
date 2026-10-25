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
use App\Services\Payment\Drivers\PromptPayGateway;
use App\Services\Payment\Drivers\StripeGateway;
use App\Services\Payment\Drivers\ThaiBankPayoutGateway;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Gateway factory and driver manager.
 *
 * The keys resolved here are the payment rails this deployment operates. Most
 * of them are named by an App\Enums\PaymentMethod case and are therefore
 * client-selectable; 'thai_bank' is the one exception — it is an INTERNAL
 * payout key resolved only by the withdrawal routing, reconciliation, polling
 * and webhook paths after the disbursement has persisted it on the withdrawal.
 * No request body can name it, because no PaymentMethod case carries it.
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

            'promptpay' => $this->container->make(PromptPayGateway::class),

            // The Thai bank payout rail is resolved BY KEY, never by a client's
            // method choice: App\Enums\PaymentMethod deliberately has no
            // 'thai_bank' case, so no request can name this driver directly.
            // WithdrawalDisbursementService routes eligible withdrawals here
            // and persists the key on the withdrawal so reconciliation,
            // polling and webhooks resolve the same driver later.
            'thai_bank' => $this->container->make(ThaiBankPayoutGateway::class),

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
     *
     * 'thai_bank' is an internal payout key: it exists here so the routing,
     * reconciliation, polling and webhook paths can resolve the same driver the
     * disbursement persisted, and it is deliberately NOT reachable from a
     * PaymentMethod value.
     */
    public function hasDriver(string $name): bool
    {
        $key = strtolower(trim($name));

        return in_array($key, ['stripe', 'bkash', 'nagad', 'crypto', 'bank_transfer', 'promptpay', 'manual', 'thai_bank'], true);
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
     * Resolve a payout driver by its INTERNAL gateway key.
     *
     * WithdrawalDisbursementService persists the key it routed a withdrawal
     * through ('thai_bank' for the automated Thai bank rail, the method value
     * for the operator lane), and reconciliation, polling and webhooks must
     * resolve the very same driver afterwards. The key is a string, not a
     * PaymentMethod: the enum deliberately has no thai_bank case, so no client
     * input can ever name this rail.
     *
     * @throws FinancialException with a stable code per refusal reason
     */
    public function withdrawalDriverByKey(string $key): PaymentGatewayInterface
    {
        $key = strtolower(trim($key));

        if ($key === '') {
            throw FinancialException::withCode(
                'unsupported_payment_gateway',
                'No payout gateway key was persisted for this withdrawal.',
                ['gateway' => $key],
            );
        }

        return $this->withdrawalDriverByResolvedKey($key);
    }

    /**
     * @throws FinancialException
     */
    private function withdrawalDriverByResolvedKey(string $key): PaymentGatewayInterface
    {
        $driver = $this->driver($key);

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
