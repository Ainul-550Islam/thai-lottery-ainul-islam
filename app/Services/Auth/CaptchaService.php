<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/*
 * PROMPT 3 — server-authoritative CAPTCHA.
 *
 * CHALLENGE: a server-generated arithmetic question rendered into the
 * form. VERIFICATION: the answer is hashed (sha256) the moment the
 * challenge is issued and ONLY the hash ever exists server-side in the
 * session; the plaintext answer exists solely inside the challenge
 * string shown to the human.
 *
 * GUARANTEES:
 * - one-time use: a consumed token can never verify again (replay
 *   resistance by construction, not by flag);
 * - expiry: every challenge carries a TTL;
 * - failure counting + cool-down per session;
 * - the answer is never logged, never stored in plaintext, never sent
 *   to the client outside the rendered question;
 * - a client-supplied "captcha=true"/"captcha=1" boolean is NEVER
 *   accepted as proof — only the answer to the issued challenge.
 *
 * The driver is configurable. 'local' is the production driver; a
 * different driver name swaps the challenge generator only — the
 * verification contract stays identical.
 */
final class CaptchaService
{
    private const SESSION_KEY = 'auth_member.captcha';

    public function __construct()
    {
    }

    /**
     * Issue (or reissue) a challenge for a form render.
     *
     * @return array{token: string, question: string}
     */
    public function issue(Request $request): array
    {
        [$question, $answer] = $this->challenge();

        $token = Str::random(48);
        $ttl = max(30, (int) config('auth_security.captcha.ttl_seconds', 600));

        $challenges = $this->sessionChallenges($request);
        $challenges[$token] = [
            'answer_hash' => hash('sha256', mb_strtolower((string) $answer)),
            'expires_at' => Carbon::now()->getTimestamp() + $ttl,
            'used' => false,
        ];

        // Keep the store small: drop expired/used entries, cap the rest.
        $challenges = array_filter(
            $challenges,
            static fn (array $entry): bool => $entry['used'] === false
                && $entry['expires_at'] > Carbon::now()->getTimestamp(),
        );
        if (count($challenges) > 10) {
            $challenges = array_slice($challenges, -10, preserve_keys: true);
        }

        $request->session()->put(self::SESSION_KEY.'.challenges', $challenges);

        return [
            'token' => $token,
            'question' => $question,
        ];
    }

    /**
     * Is the CAPTCHA gate active for this request cycle?
     *
     * FALSE only when the operator disabled it via config. It is never
     * disabled by anything a client sends.
     */
    public function isEnabled(): bool
    {
        return (bool) config('auth_security.captcha.enabled', true);
    }

    /**
     * Verify the answer for a consumed-or-live token.
     *
     * Semantics: consumes the token in EVERY outcome (success, wrong
     * answer, unknown token) so a captured challenge can never be
     * retried; counts failures; enforces the cool-down when the
     * per-session failure budget is exhausted.
     */
    public function verify(Request $request, ?string $token, ?string $answer): bool
    {
        if (! $this->isEnabled()) {
            // Disabled by configuration only — a client boolean is
            // never consulted, and never proves anything.
            return true;
        }

        $state = $this->sessionState($request);
        $now = Carbon::now()->getTimestamp();

        // Cool-down window after too many failures.
        $maxFailures = max(1, (int) config('auth_security.captcha.max_failures', 5));
        $cooldown = max(0, (int) config('auth_security.captcha.cooldown_seconds', 60));

        if (($state['blocked_until'] ?? 0) > $now) {
            return false;
        }

        $challenges = $this->sessionChallenges($request);
        $entry = is_string($token) && $token !== '' ? ($challenges[$token] ?? null) : null;

        // Unknown, expired or already-used token: fail + consume if known.
        if (! is_array($entry)) {
            $this->countFailure($request, $state, $maxFailures, $cooldown, $now);

            return false;
        }

        if ($entry['used'] || ($entry['expires_at'] ?? 0) <= $now) {
            unset($challenges[$token]);
            $request->session()->put(self::SESSION_KEY.'.challenges', $challenges);
            $this->countFailure($request, $state, $maxFailures, $cooldown, $now);

            return false;
        }

        // Consume FIRST — one-time use even on success.
        unset($challenges[$token]);
        $request->session()->put(self::SESSION_KEY.'.challenges', $challenges);

        $provided = mb_strtolower(trim((string) $answer));
        $expected = (string) $entry['answer_hash'];

        if ($provided === '' || ! hash_equals($expected, hash('sha256', $provided))) {
            $this->countFailure($request, $state, $maxFailures, $cooldown, $now);

            return false;
        }

        // A successful verification resets the failure budget.
        $state['failures'] = 0;
        $state['blocked_until'] = 0;
        $request->session()->put(self::SESSION_KEY.'.failures', 0);
        $request->session()->put(self::SESSION_KEY.'.blocked_until', 0);

        return true;
    }

    /**
     * The remaining cool-down seconds, for the "try again later" copy.
     */
    public function cooldownRemaining(Request $request): int
    {
        $state = $this->sessionState($request);
        $until = (int) ($state['blocked_until'] ?? 0);
        $now = Carbon::now()->getTimestamp();

        return $until > $now ? $until - $now : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionState(Request $request): array
    {
        /** @var array<string, mixed> $state */
        $state = $request->session()->get(self::SESSION_KEY, []);

        return is_array($state) ? $state : [];
    }

    /**
     * @return array<string, array{answer_hash: string, expires_at: int, used: bool}>
     */
    private function sessionChallenges(Request $request): array
    {
        /** @var mixed $challenges */
        $challenges = $request->session()->get(self::SESSION_KEY.'.challenges', []);

        return is_array($challenges) ? $challenges : [];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function countFailure(Request $request, array $state, int $maxFailures, int $cooldown, int $now): void
    {
        $failures = (int) ($state['failures'] ?? 0) + 1;
        $request->session()->put(self::SESSION_KEY.'.failures', $failures);

        if ($failures >= $maxFailures && $cooldown > 0) {
            $request->session()->put(self::SESSION_KEY.'.blocked_until', $now + $cooldown);
            $request->session()->put(self::SESSION_KEY.'.failures', 0);
        }
    }

    /**
     * Generate one challenge: [question, answer].
     *
     * The arithmetic stays human-simple by design. Only the question
     * string is returned to the surface; the answer is immediately
     * hashed by issue().
     *
     * @return array{0: string, 1: string}
     */
    private function challenge(): array
    {
        $driver = (string) config('auth_security.captcha.driver', 'local');

        if ($driver === 'fixed') {
            // Deterministic challenge for scripted operational probes
            // (still verified through the identical hash path).
            return ['2 + 3', '5'];
        }

        $mode = random_int(0, 2);

        if ($mode === 0) {
            $a = random_int(2, 12);
            $b = random_int(2, 12);

            return [sprintf('%d + %d', $a, $b), (string) ($a + $b)];
        }

        if ($mode === 1) {
            $a = random_int(10, 30);
            $b = random_int(2, 9);

            return [sprintf('%d − %d', $a, $b), (string) ($a - $b)];
        }

        $a = random_int(2, 9);
        $b = random_int(2, 9);

        return [sprintf('%d × %d', $a, $b), (string) ($a * $b)];
    }
}
