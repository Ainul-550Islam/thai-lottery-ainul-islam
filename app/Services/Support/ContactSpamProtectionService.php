<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\ContactMessage;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Baseline abuse control for the public contact form (PROMPT 10).
 *
 * DETERMINISTIC, AND THEREFORE TESTABLE. Every decision here is a counter or a
 * comparison against a configured number. There is no heuristic, no score and
 * no third-party bot service: a rule nobody can predict is a rule nobody can
 * test, and a legitimate visitor blocked by an invisible score has no way to
 * understand why.
 *
 * NO ANTI-BOT DEPENDENCY WAS ADDED. A CAPTCHA that no deployment has keys for
 * is decoration. The honeypot, the limiter and the duplicate window work with
 * nothing configured, which is the state this repository is actually in.
 *
 * WHAT IT DOES NOT CLAIM. This makes casual abuse expensive. It is not
 * spam-proof, and nothing here should be described as making the form secure.
 */
class ContactSpamProtectionService
{
    public const DECISION_ALLOW = 'ALLOW';

    public const DECISION_HONEYPOT = 'HONEYPOT';

    public const DECISION_RATE_LIMITED = 'RATE_LIMITED';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ContactPrivacyService $privacy,
    ) {}

    /**
     * Was the invisible field filled in?
     *
     * A human never sees it, so a value means an automated submitter walked
     * the form. The message is accepted by the HTTP layer and recorded as
     * SPAM rather than refused outright, so a bot learns nothing from the
     * response about how it was detected.
     */
    public function isHoneypotTripped(?string $honeypotValue): bool
    {
        return is_string($honeypotValue) && trim($honeypotValue) !== '';
    }

    /**
     * Three independent ceilings: sender per minute, sender per hour, and a
     * hashed email per hour.
     *
     * The email dimension exists because an IP is cheap to change and an
     * address is the thing a support inbox actually suffers from. It is keyed
     * on a hash, never on the address.
     */
    public function tooManyAttempts(?string $senderFingerprint, string $email): bool
    {
        $perMinute = max(1, (int) $this->config->get('contact.anti_spam.per_minute', 3));

        // NOT max($perMinute, ...). Clamping the hourly ceiling up to the
        // per-minute one silently discards a configured value: set 2 per hour
        // with 3 per minute and the hourly limit would quietly become 3 and
        // never bind. If an operator configures a lower hourly figure they
        // mean it, and it should simply be the limit that binds first.
        $perHour = max(1, (int) $this->config->get('contact.anti_spam.per_hour', 20));
        $emailPerHour = max(1, (int) $this->config->get('contact.anti_spam.email_per_hour', 10));

        $sender = $senderFingerprint ?? 'unknown';
        $emailKey = $this->privacy->emailFingerprint($email);

        return RateLimiter::tooManyAttempts('contact:min:'.$sender, $perMinute)
            || RateLimiter::tooManyAttempts('contact:hour:'.$sender, $perHour)
            || RateLimiter::tooManyAttempts('contact:email:'.$emailKey, $emailPerHour);
    }

    /**
     * Record one accepted submission against all three ceilings.
     *
     * Called only after a submission is accepted, so a visitor who fails
     * validation and corrects their message is not pushed towards the limit by
     * their own typo.
     */
    public function recordAttempt(?string $senderFingerprint, string $email): void
    {
        $sender = $senderFingerprint ?? 'unknown';
        $emailKey = $this->privacy->emailFingerprint($email);

        RateLimiter::hit('contact:min:'.$sender, 60);
        RateLimiter::hit('contact:hour:'.$sender, 3600);
        RateLimiter::hit('contact:email:'.$emailKey, 3600);
    }

    /**
     * Has this exact message from this exact sender just arrived?
     *
     * This is the double-click, the browser retry and the mobile reconnect.
     * The identity is sender + subject + body together, never any one of them:
     * a visitor must be able to send a second, different message immediately,
     * and two different people must be able to ask the same question.
     *
     * The window is short and configured. Outside it the same message is
     * treated as a genuine follow-up, because after five minutes of silence,
     * writing again is what a person reasonably does.
     */
    public function findRecentDuplicate(string $contentFingerprint): ?ContactMessage
    {
        $window = max(0, (int) $this->config->get('contact.anti_spam.duplicate_window_seconds', 300));

        if ($window === 0) {
            return null;
        }

        return ContactMessage::query()
            ->where('content_fingerprint', $contentFingerprint)
            ->where('created_at', '>=', Carbon::now()->subSeconds($window))
            ->latest('id')
            ->first();
    }
}
