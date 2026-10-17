<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use LogicException;

/**
 * A form-bound, single-use honeypot challenge.
 *
 * WHAT A HONEYPOT IS FOR
 * A field a human never sees and a naive bot always fills. The verdict is
 * deterministic — presence, emptiness, binding and expiry — so the whole
 * control can be tested without any third-party anti-bot service. A CAPTCHA
 * that no deployment has keys for is decoration; this works with nothing
 * configured, which is the state this repository is actually in.
 *
 * WHY FORM-BINDING AND A TOKEN
 * A static hidden field is replayable and shareable: a bot author reads the
 * markup once and posts `website=""` forever after. Each issued challenge
 * binds a random field NAME to a random token stored in the member's own
 * session, is accepted only on the form/purpose it was issued for, only via
 * the request BODY (a query-string "proof" is not a proof — it leaks through
 * referrers and logs), and only once. Clearing the trap consumes the
 * challenge, so a captured proof cannot be replayed.
 *
 * SINGLE-USE IS THE POINT
 * `inspect()` consumes any challenge it can evaluate — CLEAR, FILLED or
 * PURPOSE_MISMATCH — so double-submits and replays land on INVALID_FORM and
 * the operator sees an observation instead of a silent second pass.
 *
 * WHAT THIS CLASS DECIDES
 * Only the verdict codes; whether a verdict blocks anything is the calling
 * middleware's policy (log by default, enforce is an explicit operator choice).
 */
final class HoneypotService
{
    /** Reserved input name carrying the challenge token. */
    public const TOKEN_FIELD = '_hp_token';

    /** Session namespace for the bounded challenge store. */
    public const SESSION_KEY = 'security.honeypot.forms';

    /** Verdict: the trap field is present and empty — a human-shaped request. */
    public const CLEAR = 'CLEAR';

    /** Verdict: the trap field carries a value — the form was filled. */
    public const FILLED = 'FILLED';

    /** Verdict: the token is present but its trap field is absent entirely. */
    public const MISSING_FIELD = 'MISSING_FIELD';

    /** Verdict: no challenge, an unknown/consumed challenge, or a body-only mismatch. */
    public const INVALID_FORM = 'INVALID_FORM';

    /** Verdict: the challenge was issued for a different form purpose. */
    public const PURPOSE_MISMATCH = 'PURPOSE_MISMATCH';

    /** Verdict: the challenge outlived its TTL. */
    public const EXPIRED_FORM = 'EXPIRED_FORM';

    /** Verdict: the control is switched off by configuration. */
    public const DISABLED = 'DISABLED';

    /** Verdict: no session to bind the challenge to. */
    public const UNAVAILABLE = 'UNAVAILABLE';

    /** Config `honeypot.mode`: observe only. */
    public const MODE_LOG = 'log';

    /** Config `honeypot.mode`: observe and refuse suspicious requests. */
    public const MODE_ENFORCE = 'enforce';

    /** Seconds a challenge stays valid. Bounded to one hour. */
    public const DEFAULT_TTL_SECONDS = 600;

    /** How many live challenges one session may hold; oldest evicted. */
    public const DEFAULT_MAX_FORMS = 25;

    public function __construct(private readonly Repository $config)
    {
    }

    /**
     * Issue a challenge for one form purpose; null when the control is off or
     * there is no session to bind it to.
     *
     * @return array{field: string, token: string}|null
     */
    public function issue(Request $request, string $purpose): ?array
    {
        $policy = $this->policy();

        if (! $policy['enabled'] || ! $request->hasSession()) {
            return null;
        }

        $now = now()->getTimestamp();
        $forms = $this->prune($request, $policy['ttl_seconds'], $now);

        while (count($forms) >= $policy['max_forms']) {
            array_shift($forms); // oldest issued first
        }

        $token = bin2hex(random_bytes(16));
        while (isset($forms[$token])) {
            $token = bin2hex(random_bytes(16));
        }

        $forms[$token] = [
            'field' => 'hp_'.bin2hex(random_bytes(12)), // ^hp_[a-f0-9]{24}$
            'purpose' => $purpose,
            'issued_at' => $now,
        ];

        $request->session()->put(self::SESSION_KEY, $forms);

        return [
            'field' => $forms[$token]['field'],
            'token' => $token,
        ];
    }

    /**
     * Evaluate the body-only proof for one purpose. Consumes the challenge
     * whenever one can be evaluated at all (see SINGLE-USE IS THE POINT).
     */
    public function inspect(Request $request, string $purpose): string
    {
        $policy = $this->policy();

        if (! $policy['enabled']) {
            return self::DISABLED;
        }

        if (! $request->hasSession()) {
            return self::UNAVAILABLE;
        }

        // BODY ONLY. A proof in the query string leaks through referrers,
        // proxies and logs, so it is never accepted as one.
        $body = $this->body($request);

        $token = $body[self::TOKEN_FIELD] ?? null;
        if (! is_string($token) || $token === '') {
            return self::INVALID_FORM;
        }

        $forms = $request->session()->get(self::SESSION_KEY, []);
        if (! is_array($forms) || ! isset($forms[$token]) || ! is_array($forms[$token])) {
            return self::INVALID_FORM;
        }

        $entry = $forms[$token];
        $field = (string) ($entry['field'] ?? '');
        $issuedAt = (int) ($entry['issued_at'] ?? 0);

        if (now()->getTimestamp() - $issuedAt >= $policy['ttl_seconds']) {
            unset($forms[$token]);
            $request->session()->put(self::SESSION_KEY, $forms);

            return self::EXPIRED_FORM;
        }

        if (($entry['purpose'] ?? null) !== $purpose) {
            unset($forms[$token]);
            $request->session()->put(self::SESSION_KEY, $forms);

            return self::PURPOSE_MISMATCH;
        }

        $present = $field !== '' && array_key_exists($field, $body);
        $value = $present ? $body[$field] : null;

        // A missing field is distinct from a present null: Laravel's input
        // normalisation collapses the two everywhere else, and the difference
        // is exactly what separates "the human saw no field" from "the bot
        // posted an explicit empty".
        $verdict = ! $present
            ? self::MISSING_FIELD
            : (self::hasValue($value) ? self::FILLED : self::CLEAR);

        unset($forms[$token]);
        $request->session()->put(self::SESSION_KEY, $forms);

        return $verdict;
    }

    /**
     * The active mode ('log' by default; 'enforce' refuses suspicious forms).
     */
    public function mode(): string
    {
        return $this->policy()['mode'];
    }

    /**
     * Whether a submitted trap value counts as "filled". An empty, or purely
     * whitespace, string is the only nothing — `null`, `''` and `'  '` clear
     * the trap; `'0'`, `false`, `0` and `[]` are all posts a human form never
     * produces, so they trip it.
     */
    public static function hasValue(mixed $value): bool
    {
        return ! ($value === null || (is_string($value) && trim($value) === ''));
    }

    /**
     * Read the input a request actually carries, without the query string.
     *
     * @return array<string, mixed>
     */
    private function body(Request $request): array
    {
        $body = $request->request->all();
        if ($request->isJson()) {
            $body = array_merge($body, $request->json()->all());
        }

        return $body;
    }

    /**
     * Drop expired challenges; returns the surviving store.
     *
     * @param  array<string, mixed>  $forms
     * @return array<string, mixed>
     */
    private function prune(Request $request, int $ttl, int $now): array
    {
        $forms = $request->session()->get(self::SESSION_KEY, []);
        if (! is_array($forms)) {
            $forms = [];
        }

        foreach ($forms as $token => $entry) {
            if (! is_array($entry) || $now - (int) ($entry['issued_at'] ?? 0) >= $ttl) {
                unset($forms[$token]);
            }
        }

        return $forms;
    }

    /**
     * The validated policy. Any malformed value is a configuration error and
     * must fail LOUDLY: a bad limit silently turning into "no limit" is how a
     * control quietly stops existing. Defaults apply only to absent keys.
     *
     * @return array{enabled: bool, mode: string, ttl_seconds: int, max_forms: int}
     */
    private function policy(): array
    {
        $enabled = $this->config->get('honeypot.enabled', true);
        $mode = $this->config->get('honeypot.mode', self::MODE_LOG);
        $ttl = $this->config->get('honeypot.ttl_seconds', self::DEFAULT_TTL_SECONDS);
        $max = $this->config->get('honeypot.max_forms', self::DEFAULT_MAX_FORMS);

        if (! is_bool($enabled)) {
            throw new LogicException('honeypot.enabled must be a boolean.');
        }

        if (! is_string($mode) || ! in_array($mode, [self::MODE_LOG, self::MODE_ENFORCE], true)) {
            throw new LogicException('honeypot.mode must be "log" or "enforce".');
        }

        if ((! is_int($ttl) && ! (is_string($ttl) && preg_match('/^(?:[1-9][0-9]{0,3})$/D', $ttl)))) {
            throw new LogicException('honeypot.ttl_seconds must be an integer.');
        }

        $ttl = (int) $ttl;
        if ($ttl < 1 || $ttl > 3600) {
            throw new LogicException('honeypot.ttl_seconds must be between 1 and 3600.');
        }

        if ((! is_int($max) && ! (is_string($max) && preg_match('/^(?:[1-9][0-9]*)$/D', $max)))) {
            throw new LogicException('honeypot.max_forms must be an integer.');
        }

        $max = (int) $max;
        if ($max < 1 || $max > 50) {
            throw new LogicException('honeypot.max_forms must be between 1 and 50.');
        }

        return [
            'enabled' => $enabled,
            'mode' => $mode,
            'ttl_seconds' => $ttl,
            'max_forms' => $max,
        ];
    }
}
