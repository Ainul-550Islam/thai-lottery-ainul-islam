<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\WalletFeedType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /me/wallet/transactions — filters and a cursor.
 *
 * `per_page` is capped, not merely validated: an uncapped page size on a table that
 * grows with every bet is a denial-of-service waiting for somebody to notice. The
 * cap is applied here AND in the service, because a form request is only a guard
 * when every caller goes through it.
 *
 * The `type` list is derived from WalletFeedType::filterValues(), so adding a feed
 * bucket can never leave this validator behind.
 */
final class WalletTransactionFeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', 'in:all,'.implode(',', WalletFeedType::filterValues())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:255'],
        ];
    }
}
