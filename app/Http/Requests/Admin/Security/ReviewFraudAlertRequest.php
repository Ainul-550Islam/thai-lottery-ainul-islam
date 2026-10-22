<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Security;

use App\Models\FraudAlert;
use Illuminate\Foundation\Http\FormRequest;

class ReviewFraudAlertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('review', FraudAlert::class) ?? true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:RELEASE_ALLOW,REJECT_DENY,FREEZE_ACCOUNT,TERMINATE'],
            'decision_reason' => ['required', 'string', 'min:5', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
