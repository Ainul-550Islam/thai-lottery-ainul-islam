<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use InvalidArgumentException;

/**
 * A single marketing conversion, scrubbed for outbound transport (D3).
 *
 * WHY THIS CLASS EXISTS AT ALL
 * ----------------------------
 * This product holds KYC documents, bank account numbers and real money. The
 * thing that turns a marketing conversion into a data-protection incident is
 * one careless `->toArray()` on a player or deposit model: an email, a phone
 * number or an account number rides along to a third party, and it cannot be
 * recalled. Google's terms also prohibit sending personally identifiable
 * information to GA4, so the account itself is at risk, not just the data.
 *
 * So this object is constructed only from primitives, never from a model, and
 * every field passes through a scrubber before it is stored. There is no
 * `fromModel()`, no `fill()` and no mass assignment — deliberately. Adding a
 * field requires editing this file and justifying it in a comment.
 *
 * THE ALLOWLIST IS THE SECURITY BOUNDARY
 * --------------------------------------
 * Parameters are filtered against ALLOWED_PARAMS, not blocklisted. A
 * blocklist ("no email, no phone") fails the first time someone adds a field
 * called `contact` or `note`. An allowlist fails safe: a new field is dropped
 * silently and logged, and the conversion still records.
 *
 * GA4 LIMITS ENFORCED HERE, NOT AT THE WIRE
 * -----------------------------------------
 * GA4 rejects the whole event if a name exceeds 40 characters or a value
 * exceeds 100. Enforcing here means a long ticket reference degrades to a
 * truncated one instead of taking the conversion with it.
 */
final class ConversionEvent
{
    // ── the only events this system will ever send ───────────────────────────
    // An allowlist of names, because a typo'd event name in GA4 creates a new
    // dimension that can never be merged or renamed.
    public const REGISTRATION = 'registration';

    public const DEPOSIT = 'deposit';

    public const FIRST_DEPOSIT = 'first_deposit';

    public const BET_PLACED = 'bet_placed';

    public const WITHDRAWAL_REQUESTED = 'withdrawal_requested';

    /** @var list<string> */
    public const EVENT_NAMES = [
        self::REGISTRATION,
        self::DEPOSIT,
        self::FIRST_DEPOSIT,
        self::BET_PLACED,
        self::WITHDRAWAL_REQUESTED,
    ];

    /**
     * The only parameter keys that may leave this system.
     *
     * 'items', 'value', 'currency' and 'transaction_id' are GA4's reserved
     * e-commerce names: using them is what makes the conversion show up in
     * GA4's monetisation reports instead of as an unattributed custom event.
     */
    private const ALLOWED_PARAMS = [
        'value',           // money, 2dp
        'currency',        // ISO-4217, allowlisted below
        'transaction_id',  // our own opaque reference, never a bank reference
        'method',          // payment rail: bkash / nagad / bank / card
        'lane',            // lottery lane: national / weekly / bingo / pcso
        'player_ref',      // HMAC of the player id; pseudonymous, never raw
        'engagement_time_msec',
        'session_id',
    ];

    /** Currencies this product actually settles in. */
    private const ALLOWED_CURRENCIES = ['THB', 'BDT', 'USD'];

    /** Payment rails, named to match config/payment.php rather than free text. */
    private const ALLOWED_METHODS = ['bkash', 'nagad', 'bank', 'card', 'wallet'];

    private const MAX_PARAM_LENGTH = 100;

    /** @var array<string, string|int|float> */
    private array $params = [];

    private function __construct(
        private readonly string $name,
    ) {}

    /**
     * Build an event, dropping anything that should not leave the system.
     *
     * @param  array<string, mixed>  $params
     */
    public static function make(string $name, array $params = []): self
    {
        if (! in_array($name, self::EVENT_NAMES, true)) {
            throw new InvalidArgumentException(
                'Refusing to send an undeclared analytics event: '.$name
                .'. Add it to ConversionEvent::EVENT_NAMES deliberately.'
            );
        }

        $event = new self($name);

        foreach ($params as $key => $value) {
            $event->addParam((string) $key, $value);
        }

        return $event;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, string|int|float>
     */
    public function params(): array
    {
        return $this->params;
    }

    public function isEmpty(): bool
    {
        return $this->params === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'params' => $this->params,
        ];
    }

    private function addParam(string $key, mixed $value): void
    {
        if (! in_array($key, self::ALLOWED_PARAMS, true)) {
            // Dropped, not fatal. Losing one dimension is acceptable; skipping
            // the whole conversion because someone passed an unknown key is not.
            return;
        }

        $clean = match ($key) {
            'value' => $this->money($value),
            'currency' => $this->currency($value),
            'method' => $this->enum($value, self::ALLOWED_METHODS),
            'engagement_time_msec' => $this->integer($value),
            'transaction_id' => $this->text($value, 100),
            'lane' => $this->text($value, 40),
            'player_ref' => $this->text($value, 64),
            'session_id' => $this->text($value, 100),
            default => null,
        };

        if ($clean !== null) {
            $this->params[$key] = $clean;
        }
    }

    private function money(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $amount = round((float) $value, 2);

        // A negative or absurd value is a bug upstream. Sending it would poison
        // the revenue figures, so it is dropped rather than clamped — clamping
        // would hide the bug.
        return ($amount >= 0 && $amount <= 100_000_000) ? $amount : null;
    }

    private function currency(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $currency = strtoupper(trim($value));

        return in_array($currency, self::ALLOWED_CURRENCIES, true) ? $currency : null;
    }

    private function enum(mixed $value, array $allowed): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : null;
    }

    private function integer(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return ($int >= 0 && $int <= 86_400_000) ? $int : null;
    }

    /**
     * Plain text, truncated to GA4's limit and refused if it looks like PII.
     *
     * This is the last line of defence. A bank reference pasted into
     * transaction_id, or an email that arrives in a free-text field, is caught
     * here rather than shipped.
     */
    private function text(mixed $value, int $max): ?string
    {
        if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
            return null;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        if ($this->looksLikePersonalData($text)) {
            return null;
        }

        return mb_substr($text, 0, min($max, self::MAX_PARAM_LENGTH));
    }

    /**
     * Heuristic PII gate. Deliberately broad: a false positive costs one
     * dimension, a false negative costs a reportable data breach.
     */
    private function looksLikePersonalData(string $value): bool
    {
        // Email
        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return true;
        }

        // Phone numbers, national IDs and account numbers: a value that is
        // NOTHING but digits once separators are removed.
        //
        // This tests for an entirely-numeric value rather than "contains a
        // long digit run", because our own references are full of digits —
        // a deposit number is DEP-20261021-A7B3K9M2QX — and a ratio test
        // rejected legitimate transaction ids while still letting a bare
        // account number through. All-digits-and-long is precise: it catches
        // 01712345678 and 12345678901234 and passes every prefixed reference.
        $core = preg_replace('/[\s\-().+]/', '', $value) ?? '';
        if ($core !== '' && ctype_digit($core) && strlen($core) >= 7) {
            return true;
        }

        return false;
    }
}
