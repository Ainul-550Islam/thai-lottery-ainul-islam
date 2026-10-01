<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\Currency;
use App\Services\Finance\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Web Withdrawal Payout Request.
 *
 * Validates payout amounts against balance thresholds, withdrawal channels,
 * and destination account details.
 */
class WithdrawRequest extends FormRequest
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
        $limits = (array) config('payment.withdrawal');
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
                            $fail((string) trans('player.invalid_withdrawal_amount'));
                        }
                    } catch (\Throwable) {
                        $fail((string) trans('player.invalid_withdrawal_amount'));
                    }
                },
            ],
            'method' => ['required', 'string', Rule::in($methods)],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'account_number' => ['required', 'string', 'max:50', 'regex:/^[0-9A-Za-z\-]+$/'],
            'account_name' => ['required', 'string', 'max:150'],
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
            'amount.required' => (string) trans('player.invalid_withdrawal_amount'),
            'amount.regex' => (string) trans('player.invalid_withdrawal_amount'),
            'amount.*' => (string) trans('player.invalid_withdrawal_amount'),
            'account_number.required' => (string) trans('player.account_label'),
            'account_name.required' => (string) trans('player.account_holder_name'),
        ];
    }
}
