<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Services\Finance\Money;
use App\Services\Payment\PaymentGatewayManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class DepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(PaymentGatewayManager $gateways): array
    {
        $currency = Currency::tryFrom((string) config('payment.currency.default', 'THB')) ?? Currency::THB;
        $methods = array_values(array_filter((array) config('payment.deposit.allowed_methods', []), static function ($method) use ($gateways): bool {
            $enum = is_string($method) ? PaymentMethod::tryFrom($method) : null;

            return $enum !== null && $enum !== PaymentMethod::Crypto && $gateways->isDepositCapable($enum);
        }));

        return [
            'amount' => ['required', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/D', function (string $attribute, mixed $value, \Closure $fail) use ($currency): void {
                try {
                    $amount = Money::of((string) $value, $currency);
                    if ($amount->isLessThan(Money::of((string) config('payment.deposit.min'), $currency))
                        || $amount->isGreaterThan(Money::of((string) config('payment.deposit.max'), $currency))) {
                        $fail(trans('player.invalid_deposit_amount'));
                    }
                } catch (\Throwable) {
                    $fail(trans('player.invalid_deposit_amount'));
                }
            }],
            'method' => ['required', 'string', Rule::in($methods)],
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $method = PaymentMethod::tryFrom((string) $this->input('method', ''));
            if ($method === null || $method === PaymentMethod::Crypto) {
                $validator->errors()->add('method', trans('payment_ops.payment_method_unavailable'));
            }
        });
    }
}
