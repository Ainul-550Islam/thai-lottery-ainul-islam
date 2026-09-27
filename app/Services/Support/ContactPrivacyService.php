<?php

declare(strict_types=1);

namespace App\Services\Support;

use Illuminate\Http\Request;

/**
 * Decides what a contact submission is allowed to leave behind (PROMPT 10).
 *
 * THE POINT OF THIS CLASS IS SUBTRACTION. A support form does not need a
 * visitor's address, browser, headers or session to answer their question. It
 * needs to recognise a repeat sender. Those are different requirements, and
 * only the second one survives here.
 *
 * SO NOTHING IDENTIFYING IS RETURNED. fingerprint() produces a keyed hash of
 * the IP; the address itself is never returned, never stored and never
 * logged. The key is the application key, so a fingerprint from one
 * deployment is meaningless in another and a stolen database yields nothing
 * that can be reversed into an address.
 *
 * Header sanitisation lives here too, because the same rule drives it: text
 * that reaches a mail header must be incapable of BECOMING a header.
 */
class ContactPrivacyService
{
    /**
     * A non-reversible identifier for "the same sender, roughly".
     *
     * Deliberately coarse. It exists so a limiter and a duplicate check can
     * work, not so anyone can be traced.
     */
    public function fingerprint(Request $request): ?string
    {
        $ip = $request->ip();

        if (! is_string($ip) || $ip === '') {
            return null;
        }

        return hash_hmac('sha256', 'contact|ip|'.$ip, $this->key());
    }

    /**
     * A non-reversible identifier for "the same message, from the same sender,
     * right now".
     *
     * Subject and body are both included. Using either alone would silence a
     * visitor writing a second, different message about the same subject -
     * which is a normal thing to do and must not look like spam.
     *
     * A COARSE TIME BUCKET IS PART OF THE HASH, and it is what lets the
     * database column be UNIQUE. A permanent unique hash would mean a visitor
     * who writes the same words again next month is silently merged into a
     * months-old record; bucketing keeps the constraint real for the
     * collision that matters - two requests milliseconds apart - while a
     * genuine resend later simply lands in a different bucket.
     *
     * The bucket edge is the honest limitation: two submissions straddling it
     * produce two records. The service's time-window SELECT still catches that
     * case in practice, and being slightly conservative at a boundary is the
     * right way to be wrong here - a duplicate support message is a nuisance,
     * a lost one is a failure.
     *
     * Returns null when deduplication is switched off, so the unique index
     * simply does not bind.
     */
    public function contentFingerprint(?string $senderFingerprint, string $email, string $subject, string $message): ?string
    {
        $window = (int) config('contact.anti_spam.duplicate_window_seconds', 300);

        if ($window < 1) {
            return null;
        }

        $payload = implode('|', [
            'contact-content',
            $senderFingerprint ?? '',
            mb_strtolower(trim($email)),
            trim($subject),
            trim($message),
            // intdiv, not a formatted date: no timezone and no locale can
            // shift a bucket edge.
            (string) intdiv(time(), $window),
        ]);

        return hash_hmac('sha256', $payload, $this->key());
    }

    /**
     * A non-reversible identifier for an email address, for rate limiting.
     *
     * The raw address is never a cache key. A limiter store is a cache, caches
     * are dumped and inspected during debugging, and a cache full of visitors'
     * addresses is a personal-data store nobody agreed to.
     */
    public function emailFingerprint(string $email): string
    {
        return hash_hmac('sha256', 'contact|email|'.mb_strtolower(trim($email)), $this->key());
    }

    /**
     * Make a value safe to place in a mail header.
     *
     * CR and LF are removed rather than escaped. A newline inside a Subject is
     * how a header-injection attack adds a Bcc: or rewrites Content-Type, and
     * there is no legitimate subject line that contains one. Other C0 control
     * characters go with them; tab is kept because it is ordinary whitespace.
     */
    public function sanitiseHeaderValue(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
        $value = str_replace(["\r", "\n"], ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * Make a value safe to store and display as a message body.
     *
     * Newlines SURVIVE here - a support message has paragraphs, and stripping
     * them would mangle what the visitor wrote. Only control characters with
     * no meaning in prose are removed.
     */
    public function sanitiseBody(string $value): string
    {
        $value = str_replace("\r\n", "\n", $value);
        $value = str_replace("\r", "\n", $value);

        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '');
    }

    /**
     * Event metadata that is safe to log.
     *
     * The body, the address, the name and the fingerprints are all absent. An
     * audit trail answers "did a message arrive and what happened to it",
     * which needs none of them.
     *
     * @return array<string, string|null>
     */
    public function safeLogContext(string $publicReference, string $status, string $deliveryState): array
    {
        return [
            'public_reference' => $publicReference,
            'status' => $status,
            'delivery_state' => $deliveryState,
        ];
    }

    private function key(): string
    {
        $key = config('app.key');

        return is_string($key) && $key !== '' ? $key : 'contact-fallback-key';
    }
}
