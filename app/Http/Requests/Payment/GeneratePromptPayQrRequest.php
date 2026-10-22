<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class GeneratePromptPayQrRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1.00', 'max:500000.00'],
            'recipient_id' => ['nullable', 'string', 'max:20'],
        ];
    }
}
