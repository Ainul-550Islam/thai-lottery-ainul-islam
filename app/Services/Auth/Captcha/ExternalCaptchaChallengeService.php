<?php

declare(strict_types=1);

namespace App\Services\Auth\Captcha;

use App\Services\Auth\Captcha\DTOs\ExternalCaptchaResult;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use LogicException;
use Throwable;

/**
 * Session-bound adapter used by CaptchaService on the three member entry forms.
 * Provider/purpose MUST come from server policy, never submitted driver fields.
 * Production requires a shared cache with atomic add; ArrayStore is test-only.
 */
final class ExternalCaptchaChallengeService
{
    private const SESSION_KEY = 'auth_member.external_captcha';

    private const TTL = 300;

    public function __construct(
        private readonly Repository $config,
        private readonly CacheRepository $claims,
        private readonly ExternalCaptchaVerifier $verifier,
    ) {}

    /**
     * Public widget metadata only. Secret/provider response never enters session.
     * Fresh nonce required after every attempted verification, including outage.
     *
     * @return array{nonce: string, provider: string, site_key: string, action: ?string}
     */
    public function issue(Request $request, string $provider, string $purpose): array
    {
        $widget = $this->widget($provider, $purpose);
        if ($widget === null || ! $request->hasSession()) {
            throw new LogicException('External CAPTCHA challenge is not configured.');
        }
        $now = Carbon::now()->getTimestamp();
        $entries = $this->entries($request);
        $entries = array_filter($entries, static fn (mixed $entry): bool => is_array($entry)
            && is_int($entry['expires_at'] ?? null) && $entry['expires_at'] > $now);
        $nonce = bin2hex(random_bytes(32));
        $entries[$nonce] = [
            'provider' => $provider,
            'purpose' => $purpose,
            'widget' => $widget,
            'expires_at' => $now + self::TTL,
        ];
        // Bounded per-session storage, oldest render evicted first.
        $request->session()->put(self::SESSION_KEY, array_slice($entries, -10, preserve_keys: true));

        return ['nonce' => $nonce, 'provider' => $provider, ...$widget];
    }

    public function verify(Request $request, string $provider, string $purpose, mixed $nonce, mixed $response): ExternalCaptchaResult
    {
        $widget = $this->widget($provider, $purpose);
        if ($widget === null) {
            return new ExternalCaptchaResult('misconfigured', 'CHALLENGE_CONFIGURATION_INVALID');
        }
        if (! $request->hasSession() || ! is_string($nonce) || ! preg_match('/^[a-f0-9]{64}$/D', $nonce)) {
            return new ExternalCaptchaResult('rejected', 'CHALLENGE_INVALID');
        }
        $entries = $this->entries($request);
        $entry = $entries[$nonce] ?? null;
        // Consume before checking answer/binding or calling a remote provider.
        unset($entries[$nonce]);
        $request->session()->put(self::SESSION_KEY, $entries);
        if (! is_array($entry)) {
            return new ExternalCaptchaResult('rejected', 'CHALLENGE_INVALID');
        }
        if (($entry['provider'] ?? null) !== $provider || ($entry['purpose'] ?? null) !== $purpose
            || ($entry['widget'] ?? null) !== $widget) {
            return new ExternalCaptchaResult('rejected', 'CHALLENGE_BINDING_MISMATCH');
        }
        $expires = $entry['expires_at'] ?? null;
        $now = Carbon::now()->getTimestamp();
        if (! is_int($expires) || $expires <= $now || $expires > $now + self::TTL) {
            return new ExternalCaptchaResult('rejected', 'CHALLENGE_EXPIRED');
        }
        try {
            // Claim survives stale parallel session copies; no raw nonce/token in key.
            // Never release a claim after timeout: vendor may have consumed its token.
            if (! $this->claims->add('auth:captcha:claim:'.hash('sha256', $nonce), true, self::TTL + 30)) {
                return new ExternalCaptchaResult('rejected', 'CHALLENGE_ALREADY_USED');
            }
        } catch (Throwable) {
            return new ExternalCaptchaResult('unavailable', 'CHALLENGE_STORE_UNAVAILABLE');
        }

        return $this->verifier->verify($provider, $response, $purpose);
    }

    /** @return array<string, mixed> */
    private function entries(Request $request): array
    {
        $entries = $request->session()->get(self::SESSION_KEY, []);

        return is_array($entries) ? $entries : [];
    }

    /** @return array{site_key: string, action: ?string}|null */
    private function widget(string $provider, string $purpose): ?array
    {
        if (! in_array($provider, ['turnstile', 'hcaptcha'], true)
            || ! in_array($purpose, ['login', 'register', 'password_reset'], true)) {
            return null;
        }
        $siteKey = $this->config->get('services.captcha.'.$provider.'.site_key');
        if (! is_string($siteKey) || $siteKey === '' || strlen($siteKey) > 4096
            || preg_match('/[\x00-\x20\x7f]/', $siteKey)) {
            return null;
        }
        $action = null;
        if ($provider === 'turnstile') {
            $action = $this->config->get('services.captcha.turnstile.actions.'.$purpose);
            if (! is_string($action) || ! preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $action)) {
                return null;
            }
        }

        return ['site_key' => $siteKey, 'action' => $action];
    }
}
