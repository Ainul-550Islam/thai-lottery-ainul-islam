<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\ContactMessage;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The public "where is my ticket?" lookup.
 *
 * =========================================================================
 * THE HARD PART IS WHAT IT REFUSES TO SAY
 * =========================================================================
 * A visitor supplies a reference and an address. There are four possible
 * worlds: both correct, wrong address, no such reference, and a reference that
 * belongs to somebody else. This service answers ONE shape for all four. It
 * never says "no such ticket", never says "that address does not match", and
 * never returns a message body, a name, a subject or an internal state.
 *
 * HOW THE ADDRESS IS COMPARED. contact_messages stores the sender's address
 * (it is the platform's own record of who wrote in), so the comparison hashes
 * BOTH sides and compares the digests with hash_equals(): equal-time, and no
 * plaintext address is ever used as a lookup value or a cache key.
 *
 * HOW TIMING IS KEPT FLAT. The reference query runs for a hit and a miss alike,
 * and the address digest is computed before the branch, so the miss path does
 * the same work as the hit path. A response that is fast for "no such ticket"
 * and slow for "wrong address" is an enumeration oracle even when the bodies
 * are identical.
 *
 * WHAT THE CALLER GETS BACK
 *   reference              what was asked for, echoed
 *   status                 a translation KEY for a public label, or null
 *   status_key             the same key (explicit name for API consumers)
 *   last_updated_at        ISO timestamp, or null
 *   delivery_acknowledged  bool: a provider accepted the outbound copy
 */
final class TicketStatusLookupService
{
    /** One shape for every outcome. */
    public const STATE_NEUTRAL = 'NEUTRAL';

    /**
     * @return array{
     *     state: string,
     *     reference: string,
     *     status: string|null,
     *     status_key: string|null,
     *     last_updated_at: string|null,
     *     delivery_acknowledged: bool
     * }
     */
    public function lookup(string $reference, string $email, ?string $ip = null): array
    {
        $reference = trim($reference);
        $digest = self::digest($email);

        // The query runs whether or not the reference looks plausible, so the
        // work done (and therefore the time taken) does not depend on whether a
        // ticket exists.
        $message = ContactMessage::query()
            ->where('public_reference', $reference)
            ->first();

        $matched = false;

        if ($message instanceof ContactMessage) {
            $stored = self::digest((string) $message->email);
            $matched = hash_equals($stored, $digest);
        } else {
            // A constant-time comparison against a throwaway digest on the miss
            // path: the code path and the CPU cost match the mismatch case.
            hash_equals($digest, self::digest('no-such-ticket@example.invalid'));
        }

        $this->recordAttempt($ip, $reference);

        if (! $matched || ! $message instanceof ContactMessage) {
            return $this->neutral($reference);
        }

        $statusKey = $this->statusKeyFor($message->status);

        return [
            'state' => 'MATCHED',
            'reference' => (string) $message->public_reference,
            'status' => $statusKey,
            'status_key' => $statusKey,
            'last_updated_at' => $message->updated_at?->toIso8601String(),
            'delivery_acknowledged' => $message->delivery_state === ContactMessage::DELIVERY_SENT,
        ];
    }

    /**
     * Has this connection exhausted its lookup allowance?
     *
     * Kept public so the controller can answer with the project's throttled
     * envelope instead of a domain sentence.
     */
    public function tooManyAttempts(?string $ip, string $reference): bool
    {
        $perMinute = max(1, (int) config('support_tickets.rate_limits.lookup_per_minute', 5));
        $perHour = max(1, (int) config('support_tickets.rate_limits.lookup_per_hour', 20));

        return RateLimiter::tooManyAttempts($this->ipKey($ip), $perMinute)
            || RateLimiter::tooManyAttempts($this->referenceKey($ip, $reference), $perMinute)
            || RateLimiter::tooManyAttempts($this->ipHourKey($ip), $perHour);
    }

    /**
     * The neutral answer, shaped exactly like a match.
     *
     * @return array{
     *     state: string,
     *     reference: string,
     *     status: string|null,
     *     status_key: string|null,
     *     last_updated_at: string|null,
     *     delivery_acknowledged: bool
     * }
     */
    public function neutral(string $reference): array
    {
        return [
            'state' => self::STATE_NEUTRAL,
            'reference' => $reference,
            'status' => null,
            'status_key' => null,
            'last_updated_at' => null,
            'delivery_acknowledged' => false,
        ];
    }

    /**
     * A public label key for an internal state.
     *
     * SPAM and ARCHIVED both read as "closed": the visitor is not told their
     * ticket was classified, only that it is not being worked any more.
     */
    public function statusKeyFor(string $status): ?string
    {
        $map = (array) config('support_tickets.status_label_keys', []);

        return isset($map[$status]) && is_string($map[$status]) ? $map[$status] : null;
    }

    /**
     * The digest both sides of the comparison are hashed into.
     *
     * A plain sha256 of the normalised address, which is what makes the
     * comparison equal-time and what keeps the address out of any cache key.
     */
    public static function digest(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    private function recordAttempt(?string $ip, string $reference): void
    {
        $perMinuteTtl = 60;
        $perHourTtl = 3600;

        RateLimiter::hit($this->ipKey($ip), $perMinuteTtl);
        RateLimiter::hit($this->referenceKey($ip, $reference), $perMinuteTtl);
        RateLimiter::hit($this->ipHourKey($ip), $perHourTtl);
    }

    private function ipKey(?string $ip): string
    {
        return 'support-status:ip:'.substr(hash('sha256', (string) $ip), 0, 32);
    }

    private function ipHourKey(?string $ip): string
    {
        return 'support-status:ip-hour:'.substr(hash('sha256', (string) $ip), 0, 32);
    }

    /**
     * The reference is part of the key as a digest, never in clear.
     */
    private function referenceKey(?string $ip, string $reference): string
    {
        return 'support-status:ref:'.substr(hash('sha256', (string) $ip.'|'.$reference), 0, 32);
    }
}
