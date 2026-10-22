<?php

declare(strict_types=1);

namespace App\Http\Requests\Transfer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an operator's request to reverse a completed balance transfer.
 *
 * WHAT THIS LAYER OWNS
 * Two things and only two: that the caller holds `transfer.reverse`, and that the
 * stated reason is present and usable. Everything about the money - is the original
 * reversible, does the compensating lane exist, are the limits satisfied, is the
 * balance there - is answered by BalanceTransferReversalService and the transfer
 * service underneath it, because each of those needs a row lock to answer truthfully
 * and a "valid" verdict from here would be stale before the service ran.
 *
 * WHY THE REASON IS MANDATORY
 * This permission MOVES MONEY and is held by a handful of staff accounts. A reversal
 * is written into the audit log with the operator's id and their reason, and that
 * pair is the only thing standing between a legitimate correction and an
 * unexplainable one six months later. An optional reason is a reason that is never
 * filled in, so it is not optional.
 *
 * WHY THE MINIMUM LENGTH IS ENFORCED
 * A ten-character floor exists to stop ".", "n/a" and "asdfsdf" from satisfying a
 * required field. It cannot guarantee a meaningful sentence and does not pretend to;
 * it guarantees that whoever typed it had to type something, and the audit reader
 * gets to judge it.
 */
final class ReverseBalanceTransferRequest extends FormRequest
{
    /** Shortest accepted reason, in characters. */
    public const REASON_MIN = 10;

    /** Longest accepted reason, in characters. The metadata column is json, but a reason is a sentence, not an essay. */
    public const REASON_MAX = 500;

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        // The permission NAME comes from config, exactly as the route middleware
        // spells it, so the two can never disagree about what gates this endpoint.
        return $user->can((string) config('transfer.permissions.reverse', 'transfer.reverse'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => [
                'required',
                'string',
                'min:'.self::REASON_MIN,
                'max:'.self::REASON_MAX,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('transfer.desk.reason_required'),
            'reason.string' => __('transfer.desk.reason_required'),
            'reason.min' => __('transfer.desk.reason_too_short', ['min' => self::REASON_MIN]),
            'reason.max' => __('transfer.desk.reason_too_long', ['max' => self::REASON_MAX]),
        ];
    }

    /**
     * The reason, trimmed and with runs of whitespace collapsed.
     *
     * Collapsing matters: a reason pasted from a ticket arrives with newlines and
     * indentation, and stored verbatim it renders as one unreadable line in the
     * audit view. The text itself is never rewritten.
     */
    public function reason(): string
    {
        $reason = trim((string) $this->input('reason', ''));

        return (string) preg_replace('/\s+/u', ' ', $reason);
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (is_string($reason)) {
            // Trimmed before validation so "          " fails `required` instead of
            // passing a min-length check on whitespace.
            $this->merge(['reason' => trim($reason)]);
        }
    }
}
