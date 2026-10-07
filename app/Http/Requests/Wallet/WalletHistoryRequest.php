<?php

declare(strict_types=1);

namespace App\Http\Requests\Wallet;

use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bounded filters for an authenticated player's wallet history.
 */
final class WalletHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', Rule::enum(TransactionType::class)],
            'status' => ['nullable', 'string', Rule::enum(TransactionStatus::class)],
            'currency' => ['nullable', 'string', Rule::enum(Currency::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'user_id' => ['prohibited'],
            'wallet_id' => ['prohibited'],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
