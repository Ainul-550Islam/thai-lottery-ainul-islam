<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\GatewayDepositResponse;
use App\Enums\PaymentMethod;
use App\Exceptions\DepositException;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Wallet;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;

/**
 * Service orchestrating deposit initiation across payment gateway providers.
 */
class PaymentInitiationService
{
    public function __construct(
        private readonly DepositService $depositService,
        private readonly PaymentGatewayManager $gateways,
        private readonly ?DepositCheckoutService $checkout = null,
    ) {}

    /**
     * Initiate a deposit with a payment gateway.
     *
     * @param  array<string, mixed>  $options
     * @return array{deposit: Deposit, payment: Payment, gateway_response: GatewayDepositResponse}
     *
     * @throws DepositException
     * @throws FinancialException
     */
    public function initiateDeposit(
        Wallet $wallet,
        Money $amount,
        PaymentMethod $method,
        ?string $idempotencyKey = null,
        array $options = [],
    ): array {
        $allowedMethods = (array) config('payment.deposit.allowed_methods', []);

        if ($allowedMethods !== [] && ! in_array($method->value, $allowedMethods, true)) {
            throw FinancialException::withCode(
                'payment_method_not_allowed',
                sprintf('Payment method [%s] is not allowed for deposits.', $method->value),
                ['method' => $method->value, 'allowed' => $allowedMethods],
            );
        }

        $driver = $this->gateways->depositDriver($method);

        if (! $driver->supportsCurrency($amount->currency())) {
            throw FinancialException::withCode(
                'unsupported_gateway_currency',
                sprintf('Gateway [%s] does not support currency [%s].', $driver->name(), $amount->currency()->value),
                ['gateway' => $driver->name(), 'currency' => $amount->currency()->value],
            );
        }

        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            throw FinancialException::withCode(
                'checkout_request_key_required',
                'A stable idempotency key is required for deposit checkout initiation.',
            );
        }

        $checkout = $this->checkout ?? app(DepositCheckoutService::class);
        $checkout->assertTopLevel();

        return $checkout->execute(
            $wallet,
            $amount,
            $method,
            $idempotencyKey,
            $options,
            $driver,
        );
    }

    /**
     * Read-only recovery of an already-recorded durable checkout outcome.
     *
     * @return array{deposit: Deposit, payment: Payment, gateway_response: GatewayDepositResponse}
     *
     * @throws FinancialException
     */
    public function recover(Deposit $deposit): array
    {
        return ($this->checkout ?? app(DepositCheckoutService::class))->recover($deposit);
    }
}
