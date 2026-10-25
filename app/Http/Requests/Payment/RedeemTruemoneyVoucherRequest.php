<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

final class RedeemTruemoneyVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $host = preg_quote(strtolower(trim((string) config('truemoney.allowed_host', 'gift.truemoney.com'))), '/');

        return [
            'voucher_url' => ['required', 'string', 'max:200', 'regex:/\Ahttps:\/\/'.$host.'\/campaign\/?\?v=[A-Za-z0-9]{32,64}\z/D'],
            'user_id' => ['prohibited'],
            'userId' => ['prohibited'],
        ];
    }
}
