<?php

declare(strict_types=1);

namespace App\DTOs\Chat;

/**
 * Everything needed to open a conversation, or resume the one already open.
 *
 * ============================================================================
 * WHY A DTO AND NOT AN ARRAY
 * ============================================================================
 * The start endpoint accepts input from a form request, a JSON body and
 * (indirectly) the session. Assembling that into a typed object at the edge
 * means the service never has to ask "is this key present, and is it a
 * string?" — the questions that produce the array-key-typo bugs that only
 * appear in production.
 *
 * ============================================================================
 * @phpstan-type ChatStartMeta array<string, scalar|null>
 */
final readonly class ChatStartData
{
    /**
     * @param  array<string, scalar|null>  $meta
     */
    public function __construct(
        /**
         * The signed-in member, when there is one. Null for a guest.
         */
        public ?int $userId,

        /**
         * SHA-256 of the browser token for a guest. The raw token never
         * reaches this object: it stays in the HttpOnly cookie, and only its
         * hash is ever compared with a stored value.
         */
        public ?string $guestTokenHash,

        /**
         * What the visitor typed as their name. Trimmed, length-checked and
         * display-only. Never used for authorisation.
         */
        public ?string $guestName,

        /**
         * Optional. Used ONLY to email the transcript on close, and shown to
         * the agent so they know whether a written follow-up is possible.
         */
        public ?string $guestEmail,

        /**
         * Operator-owned context: page URL, locale, referrer, user agent
         * family. Recorded, never trusted, never used for routing.
         *
         * @var array<string, scalar|null>
         */
        public array $meta = [],
    ) {}

    /**
     * Build the DTO from an incoming HTTP request.
     *
     * The identity half (user id + guest token hash) comes from the request
     * and the identity service; the name/email/meta halves come from the
     * body. Keeping both halves in one factory means no controller ever
     * assembles the DTO by hand — and therefore no controller can forget
     * the guest hash, which is the piece a guest conversation cannot be
     * found without.
     *
     * The raw guest token never enters this object: only its hash does.
     */
    public static function fromRequest(
        \Illuminate\Http\Request $request,
        \App\Services\Chat\ChatIdentityService $identity,
    ): self {
        $user = $request->user();

        $meta = $request->input('meta');

        $allowedMeta = [];

        if (is_array($meta)) {
            foreach (['page_url', 'referrer', 'locale', 'user_agent_family', 'screen'] as $key) {
                if (array_key_exists($key, $meta)) {
                    $allowedMeta[$key] = $meta[$key];
                }
            }
        }

        // A signed-in visitor who also presents a guest cookie keeps BOTH
        // identities on the DTO: the conversation service uses that to claim
        // a guest-started conversation into the account without losing the
        // browser that opened it.
        return new self(
            userId: $user === null ? null : (int) $user->getAuthIdentifier(),
            guestTokenHash: $identity->tokenHashFromRequest($request),
            guestName: is_string($request->input('guest_name'))
                ? $request->input('guest_name')
                : null,
            guestEmail: is_string($request->input('guest_email'))
                ? $request->input('guest_email')
                : null,
            meta: $allowedMeta,
        );
    }

    /**
     * Is this conversation being opened by an anonymous visitor?
     */
    public function isGuest(): bool
    {
        return $this->userId === null;
    }

    /**
     * A name safe to store: trimmed, collapsed, and cut to the configured
     * maximum. Returns null rather than an empty string so "the visitor gave
     * no name" stays distinguishable from "the visitor gave a blank name".
     */
    public function normalisedGuestName(): ?string
    {
        if ($this->guestName === null) {
            return null;
        }

        $name = trim(preg_replace('/\s+/', ' ', $this->guestName) ?? '');

        if ($name === '') {
            return null;
        }

        $max = (int) config('chat.guest.display_name_max', 60);

        return mb_substr($name, 0, $max);
    }

    /**
     * A lower-cased email, or null when it is absent or blank.
     *
     * Lower-cased because the transcript send and any future de-duplication
     * both compare addresses, and "A@x.com" and "a@x.com" are the same
     * mailbox. Validation of the FORMAT is the form request's job; this is
     * normalisation only.
     */
    public function normalisedGuestEmail(): ?string
    {
        if ($this->guestEmail === null) {
            return null;
        }

        $email = mb_strtolower(trim($this->guestEmail));

        if ($email === '') {
            return null;
        }

        return mb_substr($email, 0, (int) config('chat.guest.email_max', 190));
    }

    /**
     * The meta as it will be stored.
     *
     * Keys are restricted to an explicit allow-list. An endpoint that stored
     * whatever keys a client sent would let a caller write arbitrary JSON
     * into the operator console, which renders some of these values.
     *
     * @return array<string, scalar|null>
     */
    public function sanitisedMeta(): array
    {
        $allowed = ['page_url', 'referrer', 'locale', 'user_agent_family', 'screen'];
        $out = [];

        foreach ($allowed as $key) {
            if (! array_key_exists($key, $this->meta)) {
                continue;
            }

            $value = $this->meta[$key];

            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            if (is_string($value)) {
                // Bounded so a 50 KB "referrer" cannot be stored.
                $value = mb_substr($value, 0, 500);
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
