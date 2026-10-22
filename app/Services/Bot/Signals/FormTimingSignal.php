<?php

declare(strict_types=1);

namespace App\Services\Bot\Signals;

use App\Services\Bot\Contracts\BotSignal;
use App\Services\Bot\DTOs\SignalResult;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Signed, session/purpose-bound, sequentially single-use form timing evidence.
 * Issue only on session-backed form renders, never on an API or public cache hit.
 * Call score once per submission from the future central detection pipeline.
 * Concurrent replay requires route session blocking with a shared lock driver;
 * session read/forget alone is NOT a distributed atomic consume guarantee.
 */
final class FormTimingSignal implements BotSignal
{
    private const SESSION_KEY = 'bot.form_timing';

    public function __construct(private readonly Repository $config) {}

    public function issue(Request $request, string $purpose): string
    {
        $settings = $this->settings();
        $this->validatePurpose($purpose);
        if (! $request->hasSession() || $request->session()->getId() === '') {
            throw new \RuntimeException('Form timing requires a session.');
        }

        $session = $request->session();
        $secret = $session->get(self::SESSION_KEY.'.secret');
        if (! is_string($secret) || ! preg_match('/^[a-f0-9]{64}$/D', $secret)) {
            $secret = bin2hex(random_bytes(32));
            $session->put(self::SESSION_KEY.'.secret', $secret);
            $session->forget(self::SESSION_KEY.'.tokens');
        }

        $now = Carbon::now()->getTimestamp();
        $tokens = $session->get(self::SESSION_KEY.'.tokens', []);
        $tokens = is_array($tokens) ? $tokens : [];
        $tokens = array_filter($tokens, static fn (mixed $entry): bool => is_array($entry)
            && is_int($entry['issued'] ?? null)
            && is_string($entry['purpose'] ?? null)
            && $entry['issued'] <= $now
            && $now - $entry['issued'] <= $settings['ttl']);
        $nonce = bin2hex(random_bytes(16));
        $tokens[$nonce] = ['issued' => $now, 'purpose' => $purpose];
        $session->put(self::SESSION_KEY.'.tokens', array_slice($tokens, -$settings['max'], null, true));
        $unsigned = $nonce.'.'.$now;

        return $unsigned.'.'.$this->signature($unsigned, $purpose, $session->getId(), $secret);
    }

    public function score(Request $request): SignalResult
    {
        // Trusted route metadata; neither POST parameters nor headers can enable it.
        // API/JSON clients without a rendered form are skipped by default.
        if ($request->attributes->get('bot.form_timing_required') !== true) {
            return new SignalResult('form_timing', 0);
        }

        $settings = $this->settings();
        $purpose = $request->attributes->get('bot.form_purpose');
        if (! is_string($purpose)) {
            throw new InvalidArgumentException('A trusted form purpose must be configured.');
        }
        $this->validatePurpose($purpose);
        if (! $request->hasSession() || $request->session()->getId() === '') {
            return $this->risk('FORM_SESSION_MISSING');
        }
        $token = $request->input($settings['field']);
        if ($token === null || $token === '') {
            return $this->risk('FORM_TOKEN_MISSING');
        }
        if (! is_string($token) || ! preg_match('/^([a-f0-9]{32})\.([0-9]{1,12})\.([a-f0-9]{64})$/D', $token, $parts)) {
            return $this->risk('FORM_TOKEN_INVALID');
        }

        $session = $request->session();
        $secret = $session->get(self::SESSION_KEY.'.secret');
        if (! is_string($secret) || ! preg_match('/^[a-f0-9]{64}$/D', $secret)) {
            return $this->risk('FORM_TOKEN_INVALID');
        }
        $unsigned = $parts[1].'.'.$parts[2];
        if (! hash_equals($this->signature($unsigned, $purpose, $session->getId(), $secret), $parts[3])) {
            return $this->risk('FORM_TOKEN_INVALID');
        }

        $tokens = $session->get(self::SESSION_KEY.'.tokens', []);
        $entry = is_array($tokens) ? ($tokens[$parts[1]] ?? null) : null;
        if (! is_array($entry)) {
            return $this->risk('FORM_TOKEN_REPLAYED_OR_EVICTED');
        }
        if (($entry['issued'] ?? null) !== (int) $parts[2] || ($entry['purpose'] ?? null) !== $purpose) {
            return $this->risk('FORM_TOKEN_INVALID');
        }

        // A correctly signed attempt is consumed even when too fast or expired.
        unset($tokens[$parts[1]]);
        $session->put(self::SESSION_KEY.'.tokens', $tokens);
        $age = Carbon::now()->getTimestamp() - (int) $parts[2];
        if ($age < 0) {
            return $this->risk('FORM_TOKEN_FUTURE');
        }
        if ($age > $settings['ttl']) {
            return $this->risk('FORM_TOKEN_EXPIRED');
        }
        if ($age < $settings['min']) {
            return $this->risk('FORM_SUBMITTED_TOO_FAST');
        }

        return new SignalResult('form_timing', 0);
    }

    private function risk(string $reason): SignalResult
    {
        return new SignalResult('form_timing', 100, [$reason]);
    }

    private function signature(string $unsigned, string $purpose, string $sessionId, string $secret): string
    {
        $data = 'form-timing-v1';
        foreach ([$unsigned, $purpose, $sessionId] as $value) {
            $data .= pack('N', strlen($value)).$value;
        }

        return hash_hmac('sha256', $data, $secret);
    }

    private function validatePurpose(string $purpose): void
    {
        if (! preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $purpose)) {
            throw new InvalidArgumentException('Form purpose must be a stable route-owned identifier.');
        }
    }

    /** @return array{field: string, min: int, ttl: int, max: int} */
    private function settings(): array
    {
        $field = $this->config->get('bot.form_timing.field', '_bot_timing');
        $min = $this->config->get('bot.form_timing.min_submit_seconds', 2);
        $ttl = $this->config->get('bot.form_timing.ttl_seconds', 600);
        $max = $this->config->get('bot.form_timing.max_tokens_per_session', 10);
        if (! is_string($field) || ! preg_match('/^_[a-z][a-z0-9_]{0,63}$/D', $field)
            || ! is_int($min) || $min < 0 || $min > 60
            || ! is_int($ttl) || $ttl < max(1, $min) || $ttl > 86400
            || ! is_int($max) || $max < 1 || $max > 100) {
            throw new InvalidArgumentException('Invalid bot form timing configuration.');
        }

        return compact('field', 'min', 'ttl', 'max');
    }
}
