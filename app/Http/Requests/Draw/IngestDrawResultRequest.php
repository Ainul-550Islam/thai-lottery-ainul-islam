<?php

declare(strict_types=1);

namespace App\Http\Requests\Draw;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for controlled draw-result INGESTION.
 *
 * THE RULES THAT MUST NOT BE BYPASSED AT THIS ENDPOINT
 * - Ingestion is a one-payload-at-a-time, source-attributed step: someone
 *   from operations statement "GLO announced: first prize = 123456". This
 *   request's rules mirror the ingestion service's own canonicalization so
 *   malformed paper never leaves the HTTP layer.
 * - first_prize must be EXACTLY six numeric characters; leading zeros are
 *   preserved as data (lottery numbers are strings, not integers — a prize
 *   of 003412 is a different winner from 3412).
 * - bottom_two is REQUIRED. It is a separately drawn two-digit number, not a
 *   slice of the first prize, and this platform does not derive it — deriving
 *   it would settle the two-digit market against a number the GLO never drew.
 *   The service re-checks only the SHAPE; the two numbers are independent and a
 *   real announcement will normally have them differ. (The previous docblock
 *   here claimed the service "re-checks that it matches the first prize's tail,
 *   per the GLO rule" — there is no such GLO rule, and the service no longer
 *   does that.)
 * - NOTHING THE INGESTION SAYS BECOMES A PROMISE: this request's docblock
 *   state what the endpoint is not (a finalization path) is enforced by the
 *   entire draw pipeline. This is validation only; the confirm endpoint
 *   carries its own maker/checker separation at the operator layer.
 *
 * WHO MAY INGEST
 * This request returns authorize=false for non-operators so the 403 story
 * matches the draw console reality: ingestion is an administrative act
 * behind the admin middleware (the controller AND the route double-bound
 * this), not a public gesture.
 */
final class IngestDrawResultRequest extends FormRequest
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
            // The canonical six-digit GLO announcement, leading zeros kept.
            'first_prize' => ['required', 'string', 'regex:/^\d{6}$/'],

            // The announcement's own two-digit prize. REQUIRED: it is a
            // separate draw, so an announcement without it is incomplete, and
            // this platform would rather refuse an incomplete announcement than
            // complete it with an invented number.
            'bottom_two' => ['required', 'string', 'regex:/^\d{2}$/'],

            // The operator-declared provenance feed tag the pipeline groups
            // results by (manual / feed / correction), with a closed
            // alphabet so HTML-shaped feed blobs are unable to reach
            // downstream canonical serialization.
            'source' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],

            // Optional auxiliary announcements (three-digit / last-two etc.)
            // that the ingestion canonicalizer distills from the first prize
            // — the structure is bounded rather than free-form JSON.
            'optional_prizes' => ['nullable', 'array', 'min:1'],
            'optional_prizes.*' => ['nullable', 'string', 'regex:/^\d{1,6}$/'],

            // Client-submitted values the server never consults. A client
            // asserting either of these has stepped outside its lane.
            'result_id' => ['prohibited'],
            'fingerprint' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_prize.regex' => 'The first prize must be exactly six numeric characters, leading zeros preserved.',
            'bottom_two.required' => 'The bottom two must be stated: it is a separately drawn number, and the platform '
                .'does not derive it from the first prize.',
            'bottom_two.regex' => 'The bottom two must be exactly two numeric characters.',
            'source.regex' => 'The source tag may only carry letters, digits, dashes and underscores.',
            'result_id.prohibited' => 'The result identity is server-derived; the client may not assert it.',
            'fingerprint.prohibited' => 'The ingestion fingerprint is server-derived; the client may not assert it.',
        ];
    }

    /**
     * The operator payload the ingestion service receives, scrubbed and
     * canonical at this layer so the service sees a clean shape.
     *
     * @return array{first_prize: string, bottom_two: string, optional_prizes?: array<int, string>}
     */
    public function resultPayload(): array
    {
        $payload = [
            'first_prize' => (string) $this->validated('first_prize'),
            // Set unconditionally. The rule above makes bottom_two required and
            // two digits, so there is no branch in which it is absent — and a
            // conditional write here would silently drop the number the rule
            // just insisted on, which is the derivation-by-omission this change
            // exists to stop.
            'bottom_two' => (string) $this->validated('bottom_two'),
        ];

        $optional = $this->validated('optional_prizes');

        if (is_array($optional) && count($optional) > 0) {
            $payload['optional_prizes'] = array_values(array_filter(
                $optional,
                static fn (mixed $item): bool => is_string($item) && $item !== '',
            ));
        }

        return $payload;
    }

    /**
     * The validated provenance feed tag.
     */
    public function resultSource(): string
    {
        return (string) $this->validated('source');
    }
}
