<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Security;

use App\Models\BlockedIp;
use Illuminate\Foundation\Http\FormRequest;

class StoreBlockedIpRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('create', BlockedIp::class);
    }

    public function rules(): array
    {
        return [
            'ip_or_cidr' => ['required', 'string', 'max:64'],
            'reason_code' => ['required', 'string', 'in:BRUTE_FORCE,REPEATED_AUTH_FAIL,FRAUD_ATTEMPT,BOT_ABUSE,MANUAL_ADMIN,THREAT_FEED'],
            'source' => ['nullable', 'string', 'in:MANUAL,AUTOMATED,THREAT_FEED'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
