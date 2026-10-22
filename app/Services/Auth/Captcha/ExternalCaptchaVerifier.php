<?php

declare(strict_types=1);

namespace App\Services\Auth\Captcha;

use App\Services\Auth\Captcha\DTOs\ExternalCaptchaResult;
use DateTimeImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Shared Siteverify transport/validation. No form, session, auth or money side effects.
 * Called through the session-bound adapter by CaptchaService for external drivers.
 * A provider callback alone never authorizes a user.
 */
final class ExternalCaptchaVerifier
{
    private const ENDPOINTS = [
        'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'hcaptcha' => 'https://api.hcaptcha.com/siteverify',
    ];

    public function __construct(private readonly Repository $config, private readonly Factory $http) {}

    public function verify(string $provider, mixed $token, string $purpose): ExternalCaptchaResult
    {
        if (! isset(self::ENDPOINTS[$provider])) {
            return new ExternalCaptchaResult('misconfigured', 'UNSUPPORTED_PROVIDER');
        }
        if (! in_array($purpose, ['login', 'register', 'password_reset'], true)) {
            return new ExternalCaptchaResult('misconfigured', 'UNSUPPORTED_PURPOSE');
        }
        $limit = $provider === 'turnstile' ? 2048 : 8192;
        if (! is_string($token) || $token === '' || strlen($token) > $limit || preg_match('/[^\x21-\x7e]/', $token)) {
            return new ExternalCaptchaResult('rejected', 'TOKEN_INVALID');
        }
        $prefix = 'services.captcha.'.$provider;
        $secret = $this->config->get($prefix.'.secret');
        $sitekey = $this->config->get($prefix.'.site_key');
        if (! $this->credential($secret) || ! $this->credential($sitekey)) {
            return new ExternalCaptchaResult('misconfigured', 'PROVIDER_CONFIGURATION_INVALID');
        }
        $hosts = [];
        $action = null;
        if ($provider === 'turnstile') {
            $configuredHosts = $this->config->get($prefix.'.allowed_hostnames', []);
            $action = $this->config->get($prefix.'.actions.'.$purpose);
            if (! is_array($configuredHosts) || ! array_is_list($configuredHosts) || count($configuredHosts) < 1 || count($configuredHosts) > 20
                || ! is_string($action) || ! preg_match('/^[a-zA-Z0-9_-]{1,32}$/D', $action)) {
                return new ExternalCaptchaResult('misconfigured', 'PROVIDER_CONFIGURATION_INVALID');
            }
            foreach ($configuredHosts as $hostname) {
                $host = $this->hostname($hostname);
                if ($host === null) {
                    return new ExternalCaptchaResult('misconfigured', 'PROVIDER_CONFIGURATION_INVALID');
                }
                $hosts[] = $host;
            }
        }
        $connect = $this->config->get('auth_security.captcha.external.connect_timeout_seconds', 2);
        $timeout = $this->config->get('auth_security.captcha.external.timeout_seconds', 5);
        $skew = $this->config->get('auth_security.captcha.external.clock_skew_seconds', 5);
        $nativeTtl = $provider === 'turnstile' ? 300 : 120;
        $maxAge = $this->config->get('auth_security.captcha.external.max_age_seconds.'.$provider, $nativeTtl);
        if (! is_int($connect) || $connect < 1 || $connect > 10
            || ! is_int($timeout) || $timeout < $connect || $timeout > 30
            || ! is_int($skew) || $skew < 0 || $skew > 30
            || ! is_int($maxAge) || $maxAge < 1 || $maxAge > $nativeTtl) {
            return new ExternalCaptchaResult('misconfigured', 'VERIFICATION_POLICY_INVALID');
        }

        $form = ['secret' => $secret, 'response' => $token];
        if ($provider === 'hcaptcha') {
            // Provider-side sitekey binding, not the untrusted hostname response field.
            $form['sitekey'] = $sitekey;
        }
        // No remoteip until trusted-proxy handling is reviewed. No implicit retries:
        // verification is single-use; a timeout requires a fresh challenge from the UI.
        try {
            $response = $this->http->asForm()->acceptJson()->connectTimeout($connect)
                ->timeout($timeout)->withoutRedirecting()->post(self::ENDPOINTS[$provider], $form);
            if (! $response->successful()) {
                return new ExternalCaptchaResult('unavailable', 'PROVIDER_HTTP_ERROR');
            }
            if (strlen($response->body()) > 65536) {
                return new ExternalCaptchaResult('unavailable', 'PROVIDER_RESPONSE_INVALID');
            }
            $data = $response->json();
        } catch (Throwable) {
            return new ExternalCaptchaResult('unavailable', 'PROVIDER_UNAVAILABLE');
        }
        if (! is_array($data) || ! is_bool($data['success'] ?? null)) {
            return new ExternalCaptchaResult('unavailable', 'PROVIDER_RESPONSE_INVALID');
        }
        $errors = $data['error-codes'] ?? [];
        if (! is_array($errors) || ! array_is_list($errors) || count($errors) > 32) {
            return new ExternalCaptchaResult('unavailable', 'PROVIDER_RESPONSE_INVALID');
        }
        foreach ($errors as $error) {
            if (! is_string($error) || strlen($error) > 128) {
                return new ExternalCaptchaResult('unavailable', 'PROVIDER_RESPONSE_INVALID');
            }
        }
        if (! $data['success']) {
            if (array_intersect($errors, ['missing-input-secret', 'invalid-input-secret', 'sitekey-secret-mismatch', 'not-using-dummy-passcode']) !== []) {
                return new ExternalCaptchaResult('misconfigured', 'PROVIDER_CREDENTIALS_INVALID');
            }
            if (in_array('internal-error', $errors, true)) {
                return new ExternalCaptchaResult('unavailable', 'PROVIDER_UNAVAILABLE');
            }

            return new ExternalCaptchaResult('rejected', 'PROVIDER_REJECTED');
        }
        if ($errors !== []) {
            return new ExternalCaptchaResult('unavailable', 'PROVIDER_RESPONSE_INVALID');
        }
        if ($provider === 'turnstile') {
            if (! in_array($this->hostname($data['hostname'] ?? null), $hosts, true)) {
                return new ExternalCaptchaResult('rejected', 'HOSTNAME_MISMATCH');
            }
            if (($data['action'] ?? null) !== $action) {
                return new ExternalCaptchaResult('rejected', 'ACTION_MISMATCH');
            }
        }
        // hCaptcha explicitly documents hostname as browser-derived/not authentication.
        $timestamp = $this->timestamp($data['challenge_ts'] ?? null);
        if ($timestamp === null) {
            return new ExternalCaptchaResult('rejected', 'TIMESTAMP_INVALID');
        }
        $age = Carbon::now()->getTimestamp() - $timestamp;
        if ($age < -$skew) {
            return new ExternalCaptchaResult('rejected', 'TOKEN_FROM_FUTURE');
        }
        if ($age > $maxAge) {
            return new ExternalCaptchaResult('rejected', 'TOKEN_TOO_OLD');
        }

        return new ExternalCaptchaResult('verified', 'VERIFIED');
    }

    private function credential(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 4096
            && trim($value) === $value && ! preg_match('/[\x00-\x20\x7f]/', $value);
    }

    private function hostname(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 254) {
            return null;
        }
        $value = strtolower($value);
        if (str_ends_with($value, '.')) {
            $value = substr($value, 0, -1);
        }
        if (strlen($value) > 253) {
            return null;
        }
        foreach (explode('.', $value) as $label) {
            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label)) {
                return null;
            }
        }

        return $value;
    }

    private function timestamp(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:?\d{2})$/D', $value)) {
            return null;
        }
        try {
            $time = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return null;
            }

            return $time->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
