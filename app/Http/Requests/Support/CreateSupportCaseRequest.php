<?php

// TYPE: Form request
// PURPOSE: Validate authenticated support-case creation without accepting owner identity from the browser.

declare(strict_types=1);

namespace App\Http\Requests\Support;

use Illuminate\Foundation\Http\FormRequest;

final class CreateSupportCaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'in:account,payment,withdrawal,bet,lottery,security,other'],
            'priority' => ['nullable', 'string', 'in:low,normal,high'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'body' => ['required', 'string', 'min:1', 'max:10000'],
        ];
    }
}
