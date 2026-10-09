<?php

declare(strict_types=1);

namespace App\Http\Requests\Draw;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the CHECKER-side confirmation payload of a draw result.
 *
 * WHAT MAKES A CONFIRMATION "VALID"
 * It names the draw it speaks about, it declares EXACTLY which first prize /
 * bottom-two pairing the checker claims they saw — and it carries the
 * confirmation context the maker/checker court needs: the checker supplies a
 * reason-by-rule (`notes`) so every confirmation carries its own
 * documentary, and the checker identity stamp comes from the authenticated
 * operator's session, never from the body. A confirmation whose payload
 * disputes the ingestion lane is refused upstream (and stays a checked-again
 * no-op inside the confirmation service's re-assert flow, never a fork).
 *
 * WHAT THIS REQUEST KEEPS OUT
 * Client-submitted broker identity fingerprints and self-published
 * confirmation metadata that the server derives for itself.
 */
final class ConfirmDrawResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        return (bool) ($user->isAdmin() || $user->isSuperAdmin());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'first_prize' => ['required', 'string', 'regex:/^\d{6}$/'],

            // REQUIRED, not nullable. A checker who omits the two-digit prize has
            // not attested to it — and the two-digit prize is a SEPARATELY DRAWN
            // number, so it cannot be inferred from the first prize by either the
            // checker or the platform. The confirmation service already refuses
            // such a claim ("did not state bottom_two"), so a nullable rule here
            // did not make the field optional; it only moved the refusal from a
            // field-level validation error to a 422 that named no field. The
            // rules are the contract the client reads, so they now say what the
            // service enforces, and the service keeps its own check as defence in
            // depth rather than as the only one.
            'bottom_two' => ['required', 'string', 'regex:/^\d{2}$/'],

            // The checker MUST attest with a note: confirmation without
            // context is confirmation without a paper trail.
            'notes' => ['required', 'string', 'min:5', 'max:500'],

            // Assertions the server derives for itself.
            'checker_user_id' => ['prohibited'],
            'operator_id' => ['prohibited'],
            'confirmed_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_prize.regex' => 'The confirmed first prize must be exactly six numeric characters.',
            'bottom_two.required' => 'The checker must state the two-digit prize being attested: it is a '
                .'separately drawn number and the platform does not derive it from the first prize.',
            'bottom_two.regex' => 'The confirmed bottom two must be exactly two numeric characters.',
            'notes.required' => 'A checker must attach the confirmation notes that justify the check.',
            'notes.min' => 'Confirmation notes must say more than nothing.',
            'checker_user_id.prohibited' => 'The checking operator is derived from the authenticated session.',
            'operator_id.prohibited' => 'The checking operator is derived from the authenticated session.',
            'confirmed_at.prohibited' => 'The confirmation timestamp is server-derived.',
        ];
    }

    /**
     * The claimed result the checker's payload asserts it saw.
     *
     * BOTH numbers, always. The conditional that used to drop an absent
     * bottom_two is gone with the nullable rule: with the field required, a
     * conditional write could only ever produce the shape the service refuses,
     * and silence about an omission is exactly how a checker ends up attesting to
     * a number they never looked at.
     *
     * @return array{first_prize: string, bottom_two: string}
     */
    public function claimedResult(): array
    {
        return [
            'first_prize' => (string) $this->validated('first_prize'),
            'bottom_two' => (string) $this->validated('bottom_two'),
        ];
    }

    /**
     * The documentary: the checker's notes, trimmed and scrubbed of
     * surrounding whitespace.
     */
    public function confirmationNotes(): string
    {
        return trim((string) $this->validated('notes'));
    }
}
