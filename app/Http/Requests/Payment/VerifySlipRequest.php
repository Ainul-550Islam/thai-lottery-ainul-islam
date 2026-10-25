<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

final class VerifySlipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // EasySlip documents a 4 MB image limit; 3906 KiB stays below 4,000,000 bytes.
        // SlipOK accepts the application-wide 5 MiB upload ceiling.
        $provider = strtolower((string) config('slip_verification.provider', 'easyslip'));
        $imageLimitKilobytes = $provider === 'slipok' ? 5120 : 3906;

        return [
            'qr_payload' => ['nullable', 'string', 'max:1000', 'required_without:slip_image', 'prohibited_with:slip_image'],
            'slip_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:'.$imageLimitKilobytes, 'required_without:qr_payload', 'prohibited_with:qr_payload'],
            'amount' => ['required', 'string', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/D'],
            'deposit_reference' => ['nullable', 'string', 'max:64'],
            'user_id' => ['prohibited'],
            'userId' => ['prohibited'],
        ];
    }
}
