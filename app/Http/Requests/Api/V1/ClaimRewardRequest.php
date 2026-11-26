<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\RewardCatalogItem;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class ClaimRewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $headerKey = $this->header('X-Idempotency-Key');
        if (! $this->filled('idempotency_key') && is_string($headerKey) && trim($headerKey) !== '') {
            $this->merge(['idempotency_key' => trim($headerKey)]);
        }

        $routeItem = $this->route('item');
        if (is_string($routeItem) || is_int($routeItem)) {
            $this->merge(['item' => (string) $routeItem]);
        }
    }

    /**
     * @return array<string, list<string|Closure>>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:1', 'max:128', 'regex:/^[A-Za-z0-9_.:\-]+$/'],
            'item' => [
                'required',
                'string',
                'max:64',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    $identifier = trim((string) $value);
                    $query = RewardCatalogItem::query();

                    $catalogItem = ctype_digit($identifier)
                        ? $query->whereKey((int) $identifier)->first()
                        : $query->where('code', $identifier)->first();

                    if (! $catalogItem instanceof RewardCatalogItem) {
                        $fail('The selected reward item does not exist.');

                        return;
                    }

                    if (! $catalogItem->active) {
                        $fail('The selected reward item is currently inactive.');
                    }
                },
            ],
            'points_cost' => ['prohibited'],
            'member_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }

    public function idempotencyKey(): string
    {
        return trim((string) $this->validated('idempotency_key'));
    }
}
