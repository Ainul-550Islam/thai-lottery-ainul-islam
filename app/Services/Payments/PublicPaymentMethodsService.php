<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentMethod;
use App\Services\Payment\PaymentGatewayManager;

/**
 * Publicly advertised payment methods for the Home page and fees documentation.
 *
 * A method is AVAILABLE only when:
 *  - it is in payment.deposit.allowed_methods (product intent), AND
 *  - PaymentGatewayManager::isDepositCapable($method) reports true, AND
 *  - its gateway driver reports valid required credentials/settlement config.
 *
 * Disabled, sandbox-only missing credentials and unconfigured providers stay hidden.
 * No secrets are ever returned to the view.
 */
class PublicPaymentMethodsService
{
    public function __construct(
        private readonly ?PaymentGatewayManager $gatewayManager = null,
    ) {
    }

    /**
     * @return array{
     *     status: string,
     *     methods: list<array{code: string, label: string, status: string, currencies: list<string>}>,
     *     message: string,
     * }
     */
    public function publicMethods(): array
    {
        if (! (bool) config('payment.deposit.enabled', false)) {
            return [
                'status' => 'NOT_CONFIGURED',
                'methods' => [],
                'message' => 'Deposits are not currently enabled.',
            ];
        }

        $allowed = array_map('strval', (array) config('payment.deposit.allowed_methods', []));
        $methods = [];
        $manager = $this->gatewayManager ?? app(PaymentGatewayManager::class);

        foreach (PaymentMethod::cases() as $case) {
            $code = $case->value;
            if (! in_array($code, $allowed, true)) {
                continue;
            }

            if ($case === PaymentMethod::Manual) {
                // Manual is an internal operator rail — never advertised.
                continue;
            }

            if (! $manager->isDepositCapable($case)) {
                continue;
            }

            try {
                $driver = $manager->driver($case);
                $currencies = array_values(array_map('strval', (array) $driver->supportedCurrencies()));
            } catch (\Throwable) {
                $currencies = ['THB'];
            }

            $methods[] = [
                'code' => $code,
                'label' => $case->label(),
                'status' => 'AVAILABLE',
                'currencies' => $currencies,
            ];
        }

        if ($methods === []) {
            return [
                'status' => 'NOT_CONFIGURED',
                'methods' => [],
                'message' => 'No payment methods are publicly available yet',
            ];
        }

        return [
            'status' => 'AVAILABLE',
            'methods' => $methods,
            'message' => '',
        ];
    }
}
