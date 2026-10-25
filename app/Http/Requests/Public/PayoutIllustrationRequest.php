<?php

declare(strict_types=1);

namespace App\Http\Requests\Public;

use App\Services\Lottery\PayoutIllustrationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Public payout-illustration input.
 *
 * WHAT A CLIENT MAY CHOOSE, AND WHAT IT MAY NOT
 * Two fields: which published bet type, and how much stake. That is the whole
 * input surface. A client cannot send a multiplier, a rate, a currency, a
 * return figure, a payout, a draw, a number or an outcome — none of those is
 * accepted here, and the service derives every one of them from published
 * configuration. A number field is deliberately absent: an illustration that
 * let a caller name the numbers would be a simulator by the back door.
 *
 * Money never arrives as a float. A JSON number is accepted for convenience
 * and converted to its decimal string form on the way in (no arithmetic is
 * ever performed on it); the service then validates the string and does all
 * arithmetic in bcmath.
 */
final class PayoutIllustrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('bet_type') && is_string($this->input('bet_type'))) {
            $this->merge(['bet_type' => strtolower(trim((string) $this->input('bet_type')))]);
        }

        if ($this->has('stake') && (is_int($this->input('stake')) || is_float($this->input('stake')))) {
            $this->merge(['stake' => (string) $this->input('stake')]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $service = app(PayoutIllustrationService::class);
        $stakePattern = (string) config('payout_illustration.stake_pattern', '/^\d{1,13}(\.\d{1,2})?$/');
        $maximum = (string) config('payout_illustration.max_stake', '');

        $stakeRules = ['required', 'string', 'regex:'.$stakePattern];

        return [
            'bet_type' => ['required', 'string', Rule::in($service->supportedBetTypes())],
            // The ceiling is checked here for a fast, local 422 and again in the
            // service, which owns the authoritative bound.
            'stake' => $maximum !== '' && is_numeric($maximum)
                ? [...$stakeRules, 'lte:'.$maximum]
                : $stakeRules,
            'locale' => ['sometimes', 'string', 'in:en,th,bn'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bet_type.required' => (string) trans('payout_illustration.unsupported_bet_type'),
            'bet_type.string' => (string) trans('payout_illustration.unsupported_bet_type'),
            'bet_type.in' => (string) trans('payout_illustration.unsupported_bet_type'),
            'stake.required' => (string) trans('payout_illustration.invalid_amount'),
            'stake.string' => (string) trans('payout_illustration.invalid_amount'),
            'stake.regex' => (string) trans('payout_illustration.invalid_amount'),
            'stake.lte' => (string) trans('payout_illustration.stake_above_maximum', [
                'max' => (string) config('payout_illustration.max_stake', ''),
                'currency' => (string) config('payout_illustration.currency', 'THB'),
            ]),
            'locale.in' => (string) trans('payout_illustration.unsupported_locale'),
        ];
    }
}
