<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Enums\PaymentMethod;

/**
 * Publicly advertised payment methods for the Home page.
 *
 * A method is AVAILABLE only when:
 *  - it is in payment.deposit.allowed_methods (product intent), AND
 *  - its gateway block is enabled AND has non-empty credentials, OR
 *  - it is bank_transfer and payment.deposit.enabled is true with the method
 *    explicitly allowed (manual rail operators turn on themselves).
 *
 * Disabled, sandbox-only missing credentials and test providers stay hidden.
 * No secrets are ever returned to the view.
 */
class PublicPaymentMethodsService
{
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
        $gateways = (array) config('payment.gateways', []);
        $methods = [];

        foreach (PaymentMethod::cases() as $case) {
            $code = $case->value;
            if (! in_array($code, $allowed, true)) {
                continue;
            }

            if ($case === PaymentMethod::Manual) {
                // Manual is an internal operator rail — never advertised.
                continue;
            }

            if ($case === PaymentMethod::BankTransfer) {
                $methods[] = [
                    'code' => $code,
                    'label' => $case->label(),
                    'status' => 'AVAILABLE',
                    'currencies' => array_values(array_map('strval', (array) config('payment.currency.supported', ['THB']))),
                ];
                continue;
            }

            $gatewayKey = $this->gatewayKeyFor($case);
            if ($gatewayKey === null) {
                continue;
            }

            $gateway = is_array($gateways[$gatewayKey] ?? null) ? $gateways[$gatewayKey] : [];
            $enabled = (bool) ($gateway['enabled'] ?? false);

            if (! $enabled) {
                continue;
            }

            if (! $this->hasCredentials($gateway)) {
                continue;
            }

            $methods[] = [
                'code' => $code,
                'label' => $case->label(),
                'status' => 'AVAILABLE',
                'currencies' => array_values(array_map('strval', (array) ($gateway['supported_currencies'] ?? []))),
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

    private function gatewayKeyFor(PaymentMethod $method): ?string
    {
        return match ($method) {
            PaymentMethod::Stripe => 'stripe',
            PaymentMethod::Bkash => 'bkash',
            PaymentMethod::Nagad => 'nagad',
            PaymentMethod::Crypto => 'crypto',
            default => null,
        };
    }

    /**
     * True when the gateway block carries at least one non-empty secret-ish
     * field. Values are never echoed — only a boolean decision.
     *
     * @param  array<string, mixed>  $gateway
     */
    private function hasCredentials(array $gateway): bool
    {
        $probeKeys = [
            'key', 'secret', 'app_key', 'app_secret', 'username', 'password',
            'merchant_id', 'merchant_number', 'private_key', 'provider', 'api_key', 'api_secret',
            'target',
        ];

        foreach ($probeKeys as $key) {
            $value = $gateway[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }
}
