<?php

declare(strict_types=1);

namespace App\Http\Requests\Web;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Validates player-submitted responsible gaming limits on the web profile surface.
 *
 * Canonical field names and bounds align strictly with the API and service contract:
 * - daily_deposit_limit: optional non-negative numeric string/decimal
 * - single_bet_limit: optional non-negative numeric string/decimal
 * - daily_wagering_limit: optional non-negative numeric string/decimal
 */
final class UpdateResponsibleGamingLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'daily_deposit_limit' => ['nullable', 'numeric', 'min:0'],
            'single_bet_limit' => ['nullable', 'numeric', 'min:0'],
            'daily_wagering_limit' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'daily_deposit_limit.numeric' => 'The daily deposit limit must be a valid number.',
            'daily_deposit_limit.min' => 'The daily deposit limit cannot be negative.',
            'single_bet_limit.numeric' => 'The single bet limit must be a valid number.',
            'single_bet_limit.min' => 'The single bet limit cannot be negative.',
            'daily_wagering_limit.numeric' => 'The daily wagering limit must be a valid number.',
            'daily_wagering_limit.min' => 'The daily wagering limit cannot be negative.',
        ];
    }
}
