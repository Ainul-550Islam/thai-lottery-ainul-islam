<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MemberBankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MemberBankAccount
 */
final class MemberBankAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MemberBankAccount $account */
        $account = $this->resource;

        return [
            'id' => (int) $account->getKey(),
            'bank_code' => $account->bank_code,
            'account_name' => $account->account_name,
            'last4' => $account->last4,
            'masked_number' => $account->masked_number,
            'status' => $account->status->value,
            'is_default' => (bool) $account->is_default,
            'name_mismatch' => (bool) $account->name_mismatch,
            'usable_for_withdrawal' => $account->isUsableForWithdrawal(),
            'verified_at' => $account->verified_at?->toIso8601String(),
            'created_at' => $account->created_at?->toIso8601String(),
        ];
    }
}
