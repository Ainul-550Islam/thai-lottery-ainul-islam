<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Services\Finance\Money;
use App\Services\Payment\PaymentGatewayManager;
use App\Services\Payout\ThaiBank\ThaiBankDestinationValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class WithdrawalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(PaymentGatewayManager $gateways): array
    {
        $currency = Currency::tryFrom((string) config('payment.currency.default', 'THB')) ?? Currency::THB;
        $methods = array_values(array_filter((array) config('payment.withdrawal.allowed_methods', []), static function ($method) use ($gateways): bool {
            $enum = is_string($method) ? PaymentMethod::tryFrom($method) : null;

            return $enum !== null && $enum !== PaymentMethod::Crypto && $gateways->isWithdrawalCapable($enum);
        }));
        $rules = [
            'amount' => ['required', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/D', function (string $attribute, mixed $value, \Closure $fail) use ($currency): void {
                try {
                    $amount = Money::of((string) $value, $currency);
                    if ($amount->isLessThan(Money::of((string) config('payment.withdrawal.min'), $currency))
                        || $amount->isGreaterThan(Money::of((string) config('payment.withdrawal.max'), $currency))) {
                        $fail(trans('player.invalid_withdrawal_amount'));
                    }
                } catch (\Throwable) {
                    $fail(trans('player.invalid_withdrawal_amount'));
                }
            }],
            'method' => ['required', 'string', Rule::in($methods)],
            'account_name' => ['required', 'string', 'max:120'],
            'idempotency_key' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_code' => ['nullable', 'string', 'max:32'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'destination_type' => ['nullable', 'string', Rule::in(['bank_account', 'promptpay_mobile', 'promptpay_national_id'])],
            'mobile' => ['nullable', 'string', 'max:32'],
            'national_id' => ['nullable', 'string', 'max:32'],
        ];
        $method = (string) $this->input('method', '');
        if ($method === PaymentMethod::BankTransfer->value) {
            $banks = (array) config('payment.supported_banks', []);
            $rules['bank_name'] = ['required', 'string', Rule::in(array_values(array_unique(array_merge(array_keys($banks), array_values($banks)))))];
            $rules['account_number'] = ['required', 'string', 'regex:/\A[0-9]{8,15}\z/D'];
        } elseif ($method === PaymentMethod::PromptPay->value) {
            $type = (string) $this->input('destination_type', 'promptpay_mobile');
            $rules['destination_type'] = ['required', Rule::in(['promptpay_mobile', 'promptpay_national_id'])];
            $rules['account_number'] = ['prohibited'];
            $rules['bank_name'] = ['prohibited'];
            $rules['bank_code'] = ['prohibited'];
            if ($type === 'promptpay_national_id') {
                $rules['mobile'] = ['prohibited'];
                $rules['national_id'] = ['required', 'string', 'regex:/\A[0-9]{13}\z/D', function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! ThaiBankDestinationValidator::hasValidThaiNationalIdChecksum((string) $value)) {
                        $fail(trans('payment_ops.promptpay_national_id_invalid'));
                    }
                }];
            } else {
                $rules['national_id'] = ['prohibited'];
                $rules['mobile'] = ['required', 'string', 'regex:/\A0[689][0-9]{8}\z/D'];
            }
        } elseif (in_array($method, [PaymentMethod::Bkash->value, PaymentMethod::Nagad->value], true)) {
            $rules['account_number'] = ['required', 'string', 'regex:/\A01[3-9][0-9]{8}\z/D'];
            $rules['bank_name'] = ['prohibited'];
        } else {
            $rules['account_number'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $method = PaymentMethod::tryFrom((string) $this->input('method', ''));
            if ($method === null || $method === PaymentMethod::Crypto) {
                $validator->errors()->add('method', trans('payment_ops.payment_method_unavailable'));
            }
            if (in_array($method, [PaymentMethod::Bkash, PaymentMethod::Nagad], true)
                && strtoupper((string) config('payment.currency.default', 'THB')) !== 'BDT') {
                $validator->errors()->add('method', trans('payment_ops.payment_method_unavailable'));
            }
            if ($method === PaymentMethod::PromptPay && strtoupper((string) config('payment.currency.default', 'THB')) !== 'THB') {
                $validator->errors()->add('method', trans('payment_ops.payment_method_unavailable'));
            }
        });
    }
}
