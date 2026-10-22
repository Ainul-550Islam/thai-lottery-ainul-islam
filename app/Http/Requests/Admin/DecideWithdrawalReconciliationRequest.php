<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Payment\WithdrawalReconciliationAccess;
use Illuminate\Foundation\Http\FormRequest;

final class DecideWithdrawalReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(WithdrawalReconciliationAccess::class)->authorize($this->user(), WithdrawalReconciliationAccess::REVIEW);

        return true;
    }

    public function rules(): array
    {
        return ['decision' => ['required', 'in:apply,reject'], 'note' => ['required', 'string', 'min:15', 'max:1000'],
            'evidence_verified' => ['required_if:decision,apply', 'accepted_if:decision,apply']];
    }
}
