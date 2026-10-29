<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\DTOs\Payment\GatewayDepositResponse;
use App\Enums\PaymentMethod;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Wallet;
use App\Services\Finance\DepositService;
use App\Services\Finance\Money;

/**
 * Orchestrates idempotent deposit creation and provider checkout initiation
 * through the durable fenced checkout owner.
 */
final class PaymentInitiationService
{
    public function __construct(
        private readonly DepositService $depositService,
        private readonly PaymentGatewayManager $gateways,
    ) {}

    /**
     * Initiate a deposit with a payment gateway.
     *
     * @param  array<string, mixed>  $options
     * @return array{deposit: Deposit, payment: Payment, gateway_response: GatewayDepositResponse}
     *
     * @throws FinancialException
     */
    public function initiateDeposit(
        Wallet $wallet,
        Money $amount,
        PaymentMethod $method,
        ?string $idempotencyKey = null,
        array $options = [],
    ): array {
        $allowedMethods = config('payment.deposit.allowed_methods', []);
        if (is_array($allowedMethods) && ! in_array($method->value, $allowedMethods, true)) {
            throw FinancialException::withCode(
                'payment_method_not_allowed',
                sprintf('Payment method [%s] is not allowed for deposits.', $method->value),
                ['method' => $method->value],
            );
        }

        // Check the actual wallet amount against the merchant-market allowlist,
        // not only the site's display/default currency.
        $driver = $this->gateways->depositDriver($method, $amount->currency());

        if (! $driver->supportsCurrency($amount->currency())) {
            throw FinancialException::withCode(
                'unsupported_gateway_currency',
                sprintf('Gateway [%s] does not support currency [%s].', $driver->name(), $amount->currency()->value),
                ['gateway' => $driver->name(), 'currency' => $amount->currency()->value],
            );
        }

        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            throw FinancialException::withCode('checkout_request_key_required',
                'A stable request key is required before a remote checkout can be created.');
        }

        return app(DepositCheckoutService::class)->execute(
            wallet: $wallet,
            amount: $amount,
            method: $method,
            key: $idempotencyKey,
            options: $options,
            driver: $driver,
        );
    }
}
