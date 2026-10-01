<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\Currency;
use App\Services\Finance\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Web Deposit Submission Form Request.
 *
 * Validates deposit amount limits, payment gateway selection, and idempotency tokens.
 */
class DepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $currency = Currency::from((string) config('payment.currency.default'));
        $limits = (array) config('payment.deposit');
        $methods = array_values((array) ($limits['allowed_methods'] ?? []));

        return [
            'amount' => [
                'required',
                'string',
                'regex:/^\d{1,12}(?:\.\d{1,2})?$/',
                function (string $attribute, mixed $value, \Closure $fail) use ($limits, $currency): void {
                    try {
                        $candidate = Money::of((string) $value, $currency);
                        $minimum = (string) ($limits['min'] ?? '');
                        $maximum = (string) ($limits['max'] ?? '');
                        if (($minimum !== '' && $candidate->isLessThan(Money::of($minimum, $currency)))
                            || ($maximum !== '' && $candidate->isGreaterThan(Money::of($maximum, $currency)))) {
                            $fail((string) trans('player.invalid_deposit_amount'));
                        }
                    } catch (\Throwable) {
                        $fail((string) trans('player.invalid_deposit_amount'));
                    }
                },
            ],
            'method' => ['required', 'string', Rule::in($methods)],
            'currency' => ['nullable', 'string', Rule::in([$currency->value])],
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => (string) trans('player.invalid_deposit_amount'),
            'amount.regex' => (string) trans('player.invalid_deposit_amount'),
            'amount.*' => (string) trans('player.invalid_deposit_amount'),
            'method.required' => (string) trans('player.payment_methods_not_configured'),
            'idempotency_key.required' => (string) trans('player.deposit_request_failed'),
        ];
    }
}
