<?php

declare(strict_types=1);

namespace App\Http\Requests\Wallet;

use App\DTOs\Wallet\ConversionAuditContextData;
use App\Enums\WalletConversionChannel;
use App\Models\User;
use App\Models\WalletConversion;
use App\Http\Responses\ApiResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * An operator undoing a completed conversion.
 *
 * WHY THE REASON IS VALIDATED HERE AND AGAIN IN THE SERVICE
 * The service enforces a minimum reason length because a reversal written by any
 * caller — a console command, a workflow job, a future integration — must carry
 * an explanation. This request enforces the same rule because an operator typing
 * into a form deserves to be told before submitting, and because a validation
 * message is far cheaper than an exception travelling back out of a transaction
 * that has already taken wallet locks. The two read the same configuration key.
 *
 * WHY THE REVERSAL DOES NOT ACCEPT AN AMOUNT, A DIRECTION OR A WALLET
 * All of them come from the original conversion, and taking any of them from the
 * client would make it possible to "reverse" something other than what actually
 * happened — a different amount, into a different wallet. The only thing an
 * operator supplies is the reason and, optionally, the idempotency key of their
 * own action so a double-submitted form does not reverse the same conversion
 * twice.
 *
 * WHO MAY CALL IT IS DELEGATED, NEVER INVENTED
 * `authorize()` does not decide anything itself and it never grants on its own
 * authority: it loads the conversion named by the route and asks
 * WalletConversionPolicy — the single place that knows what an operator is and
 * which wallet permissions exist. An earlier version of this class returned a
 * flat false, on the reasoning that a FormRequest cannot tell a member from an
 * operator. That reasoning was wrong in one direction and fatal in practice: the
 * only route that uses this class belongs to the operator API, so a flat false
 * made the endpoint refuse every caller, including the operator the feature
 * exists for, with a 403 that no amount of correct permission could clear. The
 * class CAN tell the caller apart — it has the authenticated actor and it can
 * read the row — so leaving the decision to the policy is both honest and the
 * only version of this that works.
 *
 * A MISSING ROW IS NOT A PERMISSION PROBLEM
 * When the reference does not resolve, authorise() returns true and lets the
 * controller answer 404 with the same code it uses for every unknown reference.
 * Refusing here would turn "no such conversion" into "you may not do this",
 * which is a worse answer for an operator debugging an integration and a
 * different factual claim. The controller asks the policy once more against the
 * row it holds, so the double check that the endpoint documents still happens.
 */
class ConversionReversalRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        if (! $actor instanceof User) {
            return false;
        }

        $reference = (string) $this->route('reference');

        $conversion = WalletConversion::query()->where('reference', '=', $reference)->first();

        if (! $conversion instanceof WalletConversion) {
            return true;
        }

        return $actor->can('reverse', $conversion);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $minimumReason = (int) config('conversions.reversal.min_reason_length', 10);
        $minimumKey = (int) config('conversions.idempotency.min_key_length', 16);
        $maximumKey = (int) config('conversions.idempotency.max_key_length', 128);

        return [
            'reason' => ['required', 'string', 'min:'.$minimumReason, 'max:500'],

            // Optional: the operator's own client may supply one. When absent the
            // service derives a deterministic key from the original reference, so
            // a retried console reversal is still refused a second time.
            'idempotency_key' => ['nullable', 'string', 'min:'.$minimumKey, 'max:'.$maximumKey],

            'actor_user_id' => ['prohibited'],
            'conversion_id' => ['prohibited'],
            'amount' => ['prohibited'],
            'direction' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $minimumReason = (int) config('conversions.reversal.min_reason_length', 10);

        return [
            'reason.required' => 'A reversal must state why it is being performed; this reason is permanent.',
            'reason.min' => "Describe the reason in at least {$minimumReason} characters so the record explains itself later.",
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->validated('idempotency_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    public function auditContext(): ConversionAuditContextData
    {
        return ConversionAuditContextData::fromRequest($this, WalletConversionChannel::Admin, $this->user()?->getKey());
    }

    /**
     * JSON callers get the platform envelope with the stable code
     * `reversal_not_permitted`; web callers keep the framework's 403.
     */
    protected function failedAuthorization(): void
    {
        if (! $this->expectsJson()) {
            parent::failedAuthorization();
        }

        throw new HttpResponseException(ApiResponse::error(
            code: 'reversal_not_permitted',
            message: 'Only an operator with the wallet permission can reverse a conversion.',
            status: 403,
        ));
    }
}
