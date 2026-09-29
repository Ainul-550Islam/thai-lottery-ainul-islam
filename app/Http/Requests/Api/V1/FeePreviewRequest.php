<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Services\PublicPages\FeesPageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Structural validation for POST /api/v1/fees/preview.
 *
 * THE CLIENT NEVER QUOTES A FEE.
 * The only inputs accepted are the fee category, an optional provider key
 * and the base amount. There is deliberately NO rule for any fee amount,
 * fee rate, currency or rule version: a `fee_amount` sent by a browser is
 * ignored wholesale, and the server's own calculation is the only one that
 * can ever be returned.
 *
 * WHITELISTS, NOT FREE TEXT
 * - category must be one of the currently public fee rows, resolved live
 *   from FeesPageService (internal, disabled and expired rows answer 422);
 * - provider must be one of the stable provider keys declared by the
 *   payment layer's public fee map;
 * - base_amount must be a plain decimal string: digits with at most two
 *   fractional digits. Exponent notation ('1e3'), signs ('-50.00', '+5'),
 *   thousands separators ('1,000'), malformed strings and values too large
 *   to be a sane money amount are all rejected here, before any service is
 *   reached.
 *
 * The route itself is anonymous and rate-limited (throttle:api); this
 * request adds no authentication of its own because a preview discloses
 * nothing beyond the already-public schedule.
 */
final class FeePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $categories = app(FeesPageService::class)->previewCategories();
        $providers = app(FeesPageService::class)->providers();

        return [
            'category' => [
                'required',
                'string',
                'max:64',
                'in:'.implode(',', $categories),
            ],
            'provider' => [
                'nullable',
                'string',
                'max:32',
                'in:'.implode(',', $providers),
            ],
            'base_amount' => [
                'required',
                'string',
                'max:16',
                'regex:/^\d{1,13}(\.\d{1,2})?$/',
            ],
        ];
    }

    /**
     * Cross-field rule: a provider is only meaningful for the provider
     * families. Rejecting the combination HERE keeps the response a plain
     * 422 validation error; FeesPageService::resolvePreviewCategory()
     * re-enforces the same rule as defense in depth for non-HTTP callers.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $provider = $this->input('provider');
            $category = $this->input('category');

            if (is_string($provider) && trim($provider) !== ''
                && is_string($category) && ! in_array(strtolower(trim($category)), ['withdrawal', 'cash_in'], true)) {
                $validator->errors()->add(
                    'provider',
                    'A provider is only valid for the withdrawal or cash-in fee categories.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.in' => 'The selected fee category is not available.',
            'provider.in' => 'The selected fee provider is not available.',
            'base_amount.regex' => 'The base amount must be a plain decimal string with at most two decimal places.',
        ];
    }

    public function feeCategory(): string
    {
        return strtolower((string) $this->validated()['category']);
    }

    public function feeProvider(): ?string
    {
        $provider = $this->validated()['provider'] ?? null;

        return is_string($provider) && $provider !== '' ? strtolower($provider) : null;
    }

    public function baseAmount(): string
    {
        return (string) $this->validated()['base_amount'];
    }
}
