<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Payment\WithdrawalReconciliationAccess;
use Illuminate\Foundation\Http\FormRequest;

final class StoreWithdrawalReconciliationRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(WithdrawalReconciliationAccess::class)->authorize($this->user(), WithdrawalReconciliationAccess::REQUEST);

        return true;
    }

    public function rules(): array
    {
        return ['withdrawal_id' => ['required', 'integer', 'min:1'],
            'internal_reference' => ['required', 'string', 'max:128'], 'provider' => ['required', 'string', 'max:40'],
            'provider_reference' => ['required', 'string', 'max:128'], 'currency' => ['required', 'string', 'size:3'],
            'gross_amount' => ['required', 'string', 'max:16'], 'net_amount' => ['required', 'string', 'max:16'],
            'outcome' => ['required', 'in:completed,definitively_rejected'], 'observed_at' => ['required', 'string', 'max:25'],
            'evidence' => ['required', 'file', 'max:2048', 'mimetypes:application/pdf,application/json,text/plain']];
    }
}
