<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        /** @var array<string, string> $banks */
        $banks = (array) config('rewards.bank_accounts.supported_banks', []);
        $allowedCodes = array_keys($banks);

        $bankRule = ['required', 'string', 'max:32'];
        if ($allowedCodes !== []) {
            $bankRule[] = 'in:'.implode(',', array_merge($allowedCodes, array_map('strtolower', $allowedCodes)));
        }

        $minDigits = max(1, (int) config('rewards.bank_accounts.account_number_min_digits', 6));
        $maxDigits = max($minDigits, (int) config('rewards.bank_accounts.account_number_max_digits', 24));

        return [
            'bank_code' => $bankRule,
            'account_name' => ['required', 'string', 'min:2', 'max:160'],
            'account_number' => ['required', 'string', 'regex:/^[0-9\-\s]{'.$minDigits.',}$/'],
            'is_default' => ['nullable', 'boolean'],
            'status' => ['prohibited'],
            'verified_at' => ['prohibited'],
            'member_id' => ['prohibited'],
        ];
    }
}
