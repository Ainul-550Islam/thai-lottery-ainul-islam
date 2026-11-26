<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Services\MyBets\MyBetsQueryService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /me/bets — status tab, date window, page.
 *
 * The status vocabulary comes from MyBetsQueryService::filters(), which is also what
 * the service's applyFilter() switches on, so a tab cannot be listed here and
 * unhandled there.
 */
final class MyBetsIndexRequest extends FormRequest
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
            'status' => ['nullable', 'string', 'in:'.implode(',', MyBetsQueryService::filters())],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
