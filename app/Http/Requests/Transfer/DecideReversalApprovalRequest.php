<?php

declare(strict_types=1);

namespace App\Http\Requests\Transfer;

use App\Enums\TransferReversalApprovalStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an operator's DECISION on a four-eyes reversal request.
 *
 * This is the checker's half of the lane that ReverseBalanceTransferRequest opens. It
 * deliberately mirrors that class rather than extending it: the two endpoints gate
 * different permissions (`transfer.approve_reverse` against `transfer.reverse`), so
 * sharing an authorize() would be exactly the collapse the RolePermissionSeeder comment
 * warns about.
 *
 * WHY ONE CLASS SERVES BOTH DECISIONS
 * Approve and reject differ in one rule - whether the reason is mandatory. Everything
 * else, including the reason normalisation below, is identical, and duplicating that
 * normalisation into two files is how one of them drifts. The decision arrives as a
 * route default set in routes/web.php, which keeps it out of the request body: a caller
 * cannot turn an approval into a rejection by adding a field, because the field is not
 * read.
 *
 * WHY A REJECTION REQUIRES A REASON AND AN APPROVAL DOES NOT
 * A rejection is terminal - the record cannot be re-opened, so the maker's only way
 * forward is to investigate - and a terminal decision with no recorded cause is one
 * nobody can act on. An approval needs no note because the substantive justification
 * already exists: it is the maker's reason, captured when the request was opened and
 * preserved verbatim on the approval record. Requiring a second text there would only
 * invite "ok" and "approved", which is noise in the audit log.
 *
 * WHAT THIS LAYER DOES NOT CHECK
 * Whether the maker and the checker are the same human being, whether the request is
 * still pending, and whether the reversal can still execute. Each needs the row under a
 * lock to answer truthfully, so each lives in TransferReversalApprovalService - and a
 * "valid" verdict from here would be stale before the service ran.
 */
final class DecideReversalApprovalRequest extends FormRequest
{
    /** Shortest accepted reason, in characters. Matches the maker's floor. */
    public const REASON_MIN = 10;

    /** Longest accepted reason, in characters. A note is a sentence, not an essay. */
    public const REASON_MAX = 500;

    /**
     * The route default carrying this endpoint's decision.
     *
     * Set in routes/web.php on both routes, so it is never absent in practice. The
     * fallback below is Rejected: if a route ever forgets the default, the endpoint
     * must fail towards the decision that moves no money.
     */
    public const ROUTE_KEY = 'approval_decision';

    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        // Read from config exactly as the maker's request does, so the route middleware,
        // this class and the seeder cannot disagree about what gates the decision.
        return $user->can((string) config('transfer.permissions.approve_reverse', 'transfer.approve_reverse'));
    }

    /**
     * The decision this endpoint represents.
     */
    public function decision(): TransferReversalApprovalStatus
    {
        $value = $this->route(self::ROUTE_KEY);

        return TransferReversalApprovalStatus::tryFrom(is_string($value) ? $value : '')
            ?? TransferReversalApprovalStatus::Rejected;
    }

    public function isRejection(): bool
    {
        return $this->decision() === TransferReversalApprovalStatus::Rejected;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reason = ['string', 'max:'.self::REASON_MAX];

        if ($this->isRejection()) {
            array_unshift($reason, 'required', 'min:'.self::REASON_MIN);
        } else {
            // Nullable, not absent: an approval may carry a note, and when it does it
            // must obey the same ceiling as every other reason in this lane.
            array_unshift($reason, 'nullable');
        }

        return ['reason' => $reason];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => __('transfer.desk.rejection_reason_required'),
            'reason.string' => __('transfer.desk.rejection_reason_required'),
            'reason.min' => __('transfer.desk.reason_too_short', ['min' => self::REASON_MIN]),
            'reason.max' => __('transfer.desk.reason_too_long', ['max' => self::REASON_MAX]),
        ];
    }

    /**
     * The checker's note, trimmed with runs of whitespace collapsed, or null when they
     * wrote nothing.
     *
     * Identical normalisation to the maker's request: both texts end up side by side on
     * one approval record and in one audit row, and a reason pasted from a ticket arrives
     * with newlines that would render as one unreadable line. The text itself is never
     * rewritten.
     */
    public function reason(): ?string
    {
        $reason = trim((string) $this->input('reason', ''));

        if ($reason === '') {
            return null;
        }

        return (string) preg_replace('/\s+/u', ' ', $reason);
    }

    protected function prepareForValidation(): void
    {
        $reason = $this->input('reason');

        if (is_string($reason)) {
            // Trimmed before validation so "          " fails `required` on a rejection
            // instead of passing a min-length check on whitespace.
            $this->merge(['reason' => trim($reason)]);
        }
    }
}
