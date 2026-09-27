<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\ContactMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The contact workflow, start to finish (PROMPT 10).
 *
 * ORDER IS THE DESIGN
 * ---------------------------------------------------------------------------
 *   1. sanitise      - strip what cannot legally be in a header or a body
 *   2. spam checks   - honeypot, limiter, duplicate window
 *   3. PERSIST       - inside a transaction, committed before anything else
 *   4. deliver       - outside that transaction, allowed to fail
 *   5. report        - the true state, never a flattering one
 *
 * STEP 3 COMMITS BEFORE STEP 4 RUNS, AND THAT IS DELIBERATE. If delivery were
 * inside the transaction, an SMTP outage would roll back the visitor's message
 * and the platform would lose a support request because a mail server was
 * down. The message is the asset; the email is a convenience.
 *
 * WHAT THE CALLER IS TOLD. An outcome array with a status, a delivery state
 * and a public reference. No exception text, no provider detail, no row id.
 * The controller turns it into a translated sentence and can add nothing to
 * it, because there is nothing else in it.
 */
class ContactMessageService
{
    public const OUTCOME_RECEIVED = 'RECEIVED';

    public const OUTCOME_DUPLICATE = 'DUPLICATE';

    public const OUTCOME_SPAM = 'SPAM';

    public const OUTCOME_RATE_LIMITED = 'RATE_LIMITED';

    public const OUTCOME_DISABLED = 'DISABLED';

    public const OUTCOME_FAILED = 'FAILED';

    public function __construct(
        private readonly ContactPrivacyService $privacy,
        private readonly ContactSpamProtectionService $spam,
        private readonly ContactDeliveryService $delivery,
    ) {}

    /**
     * @param  array{name: string, email: string, subject: string, message: string, website?: string|null}  $input
     * @return array{
     *     outcome: string,
     *     reference: string|null,
     *     status: string|null,
     *     delivery_state: string|null
     * }
     */
    public function submit(array $input, Request $request): array
    {
        if ((bool) config('contact.enabled', true) === false) {
            return $this->outcome(self::OUTCOME_DISABLED);
        }

        // 1. Sanitise. Header fields lose CR/LF entirely; the body keeps its
        //    newlines because a support message has paragraphs.
        $name = $this->privacy->sanitiseHeaderValue((string) ($input['name'] ?? ''));
        $email = $this->privacy->sanitiseHeaderValue((string) ($input['email'] ?? ''));
        $subject = $this->privacy->sanitiseHeaderValue((string) ($input['subject'] ?? ''));
        $message = $this->privacy->sanitiseBody((string) ($input['message'] ?? ''));

        $senderFingerprint = $this->privacy->fingerprint($request);

        // 2a. Honeypot. Recorded as SPAM rather than refused, so an automated
        //     submitter learns nothing from the response about how it was
        //     caught. Nothing is delivered.
        if ($this->spam->isHoneypotTripped($input[$this->honeypotField()] ?? null)) {
            $stored = $this->persist($name, $email, $subject, $message, $senderFingerprint, ContactMessage::STATUS_SPAM);

            return $stored === null
                ? $this->outcome(self::OUTCOME_FAILED)
                : $this->outcome(self::OUTCOME_SPAM, $stored);
        }

        // 2b. Ceilings.
        if ($this->spam->tooManyAttempts($senderFingerprint, $email)) {
            return $this->outcome(self::OUTCOME_RATE_LIMITED);
        }

        // 2c. The double-click, the retry, the reconnect. Identity is sender +
        //     subject + body together, so a genuinely different second message
        //     always gets through.
        $contentFingerprint = $this->privacy->contentFingerprint($senderFingerprint, $email, $subject, $message);

        // FAST PATH ONLY. This SELECT keeps the ordinary double-click from
        // reaching the database as an exception. It is NOT the guarantee:
        // two requests arriving together both find nothing here. The UNIQUE
        // index on content_fingerprint settles that case, and persist()
        // below turns the violation back into the same duplicate answer.
        $existing = $contentFingerprint === null
            ? null
            : $this->spam->findRecentDuplicate($contentFingerprint);

        if ($existing instanceof ContactMessage) {
            // The visitor sees the same reference and the same reassurance as
            // the first time. From their side nothing went wrong, because
            // nothing did.
            return [
                'outcome' => self::OUTCOME_DUPLICATE,
                'reference' => $existing->public_reference,
                'status' => $existing->status,
                'delivery_state' => $existing->delivery_state,
            ];
        }

        // 3. Persist, and commit.
        $stored = $this->persist(
            $name,
            $email,
            $subject,
            $message,
            $senderFingerprint,
            ContactMessage::STATUS_RECEIVED,
            $contentFingerprint,
        );

        if ($stored === null) {
            return $this->outcome(self::OUTCOME_FAILED);
        }

        // persist() returns an EXISTING row when the unique index caught a
        // concurrent twin. Recognising that here keeps the outcome honest and
        // stops a second delivery attempt for one message.
        if (! $stored->wasRecentlyCreated) {
            return [
                'outcome' => self::OUTCOME_DUPLICATE,
                'reference' => $stored->public_reference,
                'status' => $stored->status,
                'delivery_state' => $stored->delivery_state,
            ];
        }

        $this->spam->recordAttempt($senderFingerprint, $email);

        Log::info('contact_received', $this->privacy->safeLogContext(
            $stored->public_reference,
            $stored->status,
            $stored->delivery_state,
        ));

        // 4. Deliver. Outside the transaction, and allowed to fail: the
        //    message already exists and stays.
        $state = $this->delivery->deliver($stored);

        Log::info('contact_delivery_attempted', $this->privacy->safeLogContext(
            $stored->public_reference,
            $stored->status,
            $state,
        ));

        return [
            'outcome' => self::OUTCOME_RECEIVED,
            'reference' => $stored->public_reference,
            'status' => $stored->status,
            'delivery_state' => $state,
        ];
    }

    /**
     * Write the message inside a transaction.
     *
     * Three outcomes, and the caller distinguishes them by wasRecentlyCreated:
     * a new row, an EXISTING row when a concurrent twin won the race, or null
     * when the write genuinely failed.
     *
     * Returns null rather than throwing on a real failure: the exception's
     * text can carry SQL and an SQLSTATE, and neither belongs in anything a
     * caller might surface.
     */
    private function persist(
        string $name,
        string $email,
        string $subject,
        string $message,
        ?string $senderFingerprint,
        string $status,
        ?string $contentFingerprint = null,
    ): ?ContactMessage {
        try {
            return DB::transaction(fn (): ContactMessage => ContactMessage::query()->create([
                'name' => $name,
                'email' => $email,
                'subject' => $subject,
                'message' => $message,
                'status' => $status,
                'delivery_state' => ContactMessage::DELIVERY_NOT_ATTEMPTED,
                'sender_fingerprint' => $senderFingerprint,
                'content_fingerprint' => $contentFingerprint,
                'locale' => app()->getLocale(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Lost a race against an identical concurrent submission. The
            // other request has already written the message, so this one is a
            // duplicate rather than a failure - and the visitor must get the
            // same reassurance and the same reference either way.
            //
            // Re-read rather than guess: the winner's row is the record.
            return $contentFingerprint === null
                ? null
                : ContactMessage::query()
                    ->where('content_fingerprint', $contentFingerprint)
                    ->latest('id')
                    ->first();
        } catch (Throwable $exception) {
            Log::warning('contact_persist_failed', ['exception' => $exception::class]);

            return null;
        }
    }

    private function honeypotField(): string
    {
        $field = config('contact.anti_spam.honeypot_field');

        return is_string($field) && $field !== '' ? $field : 'website';
    }

    /**
     * @return array{outcome: string, reference: string|null, status: string|null, delivery_state: string|null}
     */
    private function outcome(string $outcome, ?ContactMessage $message = null): array
    {
        return [
            'outcome' => $outcome,
            'reference' => $message?->public_reference,
            'status' => $message?->status,
            'delivery_state' => $message?->delivery_state,
        ];
    }
}
