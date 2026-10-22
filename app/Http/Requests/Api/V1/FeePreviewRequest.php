<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Services\PublicPages\FeesPageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Structural validation for POST /api/v1/fees/preview.
 *
 * THIS CLASS IS THE LIVE VALIDATOR, AND IT WAS NOT ALWAYS
 * The controller used to read the three fields straight off the request and cast them:
 * `(string) $request->input('base_amount', '')`. This class sat beside it, fully written, with
 * its own accessors - referenced by nothing. Everything it promised in the comment below was
 * therefore not happening, and one case measurably broke: `base_amount` sent as an ARRAY reached
 * the service as "Array" and the endpoint answered 500 `transaction_failed` on an anonymous,
 * rate-limited public route. With the rules actually applied, the `string` rule refuses it and
 * the answer is 422 like every other bad input.
 *
 * So the fix was to wire this class, not to restate its rules in the controller. The controller
 * now type-hints it and reads only through feeCategory(), feeProvider() and baseAmount().
 *
 * THE CLIENT NEVER QUOTES A FEE.
 * The only inputs accepted are the fee category, an optional provider key and the base amount.
 * There is deliberately NO rule for any fee amount, fee rate, currency or rule version: a
 * `fee_amount` sent by a browser is ignored wholesale, and the server's own calculation is the
 * only one that can ever be returned.
 *
 * WHITELISTS, NOT FREE TEXT
 * - category must be one of the currently public fee rows, resolved live from FeesPageService
 *   (internal, disabled and expired rows answer 422);
 * - provider must be one of the stable provider keys declared by the payment layer's public fee
 *   map - and because that list is read from the same service the controller used to consult with
 *   isKnownProvider(), that controller branch was DELETED rather than kept: a whitelist derived
 *   from the same source as the check cannot disagree with it, and a guard that can never fire is
 *   one more thing for the next reader to keep honest;
 * - base_amount must be a plain decimal string: digits with at most two fractional digits.
 *   Exponent notation ('1e3'), signs ('-50.00', '+5'), thousands separators ('1,000'), malformed
 *   strings, non-scalars and values too large to be a sane money amount are all rejected here,
 *   before any service is reached.
 *
 * ONE ENVELOPE, NOT ONE PER ENDPOINT
 * Nothing overrides failedValidation() here. The app-wide handler renders a ValidationException as
 * {"success":false,"error":{"code":"validation_failed","message":…,"details":{"fields":…}}} for
 * every endpoint, and an earlier draft of this file replaced that with this endpoint's own shape so
 * the page's JavaScript would keep finding a top-level "message". That would have made ONE route
 * speak differently from the rest of the API to work around a client that read one key - so the
 * client was taught to read the envelope instead (see previewErrorText in
 * resources/js/public-pages.js), and validation failures on every endpoint now reach the user.
 *
 * WHAT THE `in:` RULE CHANGED ON PURPOSE
 * A category that is not public used to answer with the service's wording, "Fee category is not
 * configured: deposit". It now answers with this class's messages(), "The selected fee category is
 * not available." Same status, same envelope, and the message a caller sees is the one declared
 * here - which is the point of having declared it.
 *
 * The route itself is anonymous and rate-limited (throttle:api); this request adds no
 * authentication of its own because a preview discloses nothing beyond the already-public
 * schedule.
 */
final class FeePreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalise before validating.
     *
     * The controller used to do `strtolower(trim($provider))` and then ask the service, so a
     * caller sending 'PAYPAL' was accepted. Rule order is validate-then-transform, which would
     * have made the case-sensitive `in:` whitelist reject that caller - a silent tightening of a
     * public endpoint, on a request the previous code honoured. Trimming and lowercasing here
     * keeps the tolerance, and it keeps it in ONE place: the accessors below no longer need to
     * re-normalise, and the cross-field rule compares values that have already been normalised.
     */
    protected function prepareForValidation(): void
    {
        $normalise = static function (mixed $value): ?string {
            if (! is_string($value) && ! is_int($value)) {
                // Left as-is so the type rules report it, rather than turning an array into a
                // string here and hiding the real problem from the validator.
                return null;
            }

            $value = strtolower(trim((string) $value));

            return $value === '' ? null : $value;
        };

        $category = $normalise($this->input('category'));
        $provider = $normalise($this->input('provider'));
        $baseAmount = $this->input('base_amount');

        $merged = [];

        if ($category !== null) {
            $merged['category'] = $category;
        }

        if ($provider !== null) {
            $merged['provider'] = $provider;
        } else {
            $merged['provider'] = null;
        }

        if (is_string($baseAmount)) {
            $merged['base_amount'] = trim($baseAmount);
        }

        $this->replace(array_merge($this->all(), $merged));
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
     * Cross-field rule: a provider is only meaningful for the provider families. Rejecting the
     * combination HERE keeps the response a plain 422 validation error;
     * FeesPageService::resolvePreviewCategory() re-enforces the same rule as defense in depth for
     * non-HTTP callers.
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
