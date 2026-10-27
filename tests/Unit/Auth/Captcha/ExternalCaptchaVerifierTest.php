<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Captcha;

use App\Services\Auth\Captcha\ExternalCaptchaVerifier;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalCaptchaVerifierTest extends TestCase
{
    private Repository $config;

    private Factory $http;

    private ExternalCaptchaVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'UTC'));
        $this->config = new Repository([
            'auth_security' => require dirname(__DIR__, 4).'/config/auth_security.php',
            'services' => require dirname(__DIR__, 4).'/config/services.php',
        ]);
        $this->config->set('auth_security.captcha.external', [
            'connect_timeout_seconds' => 2, 'timeout_seconds' => 5, 'clock_skew_seconds' => 5,
            'max_age_seconds' => ['turnstile' => 300, 'hcaptcha' => 120],
        ]);
        foreach (['turnstile', 'hcaptcha'] as $provider) {
            $this->config->set('services.captcha.'.$provider.'.secret', 'test-only-secret');
            $this->config->set('services.captcha.'.$provider.'.site_key', 'test-only-site-key');
        }
        $this->config->set('services.captcha.turnstile.allowed_hostnames', ['example.test']);
        $this->config->set('services.captcha.turnstile.actions', [
            'login' => 'login', 'register' => 'register', 'password_reset' => 'password_reset',
        ]);
        $this->http = new Factory;
        $this->http->preventStrayRequests();
        $this->verifier = new ExternalCaptchaVerifier($this->config, $this->http);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function valid(array $overrides = []): array
    {
        return array_replace([
            'success' => true, 'hostname' => 'example.test', 'action' => 'login',
            'challenge_ts' => Carbon::now()->toISOString(), 'error-codes' => [],
        ], $overrides);
    }

    public static function providers(): array
    {
        return [['turnstile'], ['hcaptcha']];
    }

    #[DataProvider('providers')]
    public function test_valid_form_post_pinned_endpoint_and_timeouts(string $provider): void
    {
        $this->config->set('services.captcha.'.$provider.'.verify_url', 'http://127.0.0.1/never-call');
        $expectedUrl = $provider === 'turnstile' ? 'https://challenges.cloudflare.com/turnstile/v0/siteverify' : 'https://api.hcaptcha.com/siteverify';
        $this->http->fake(function (Request $request, array $options) use ($provider, $expectedUrl) {
            self::assertSame($expectedUrl, $request->url());
            self::assertSame('POST', $request->method());
            self::assertTrue($request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
            self::assertSame('test-only-secret', $request['secret']);
            self::assertSame('token+reserved&value=1', $request['response']);
            self::assertFalse(isset($request['remoteip']));
            self::assertSame(2, $options['connect_timeout']);
            self::assertSame(5, $options['timeout']);
            self::assertFalse($options['allow_redirects']);
            if ($provider === 'hcaptcha') {
                self::assertSame('test-only-site-key', $request['sitekey']);
            } else {
                self::assertFalse(isset($request['sitekey']));
            }

            return Factory::response($this->valid(), 200);
        });
        $result = $this->verifier->verify($provider, 'token+reserved&value=1', 'login');
        self::assertTrue($result->isVerified());
        self::assertSame(['status' => 'verified', 'code' => 'VERIFIED'], $result->toArray());
        $this->http->assertSentCount(1);
    }

    public static function invalidTokens(): array
    {
        return [[null], [true], [false], [[]], [''], [' token'], ['token '], ["token\nvalue"], [str_repeat('x', 2049)]];
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_token_never_calls_provider(mixed $token): void
    {
        self::assertSame('TOKEN_INVALID', $this->verifier->verify('turnstile', $token, 'login')->code);
        $this->http->assertNothingSent();
    }

    public function test_hcaptcha_has_explicit_application_token_budget(): void
    {
        self::assertSame('TOKEN_INVALID', $this->verifier->verify('hcaptcha', str_repeat('x', 8193), 'login')->code);
        $this->http->assertNothingSent();
    }

    public static function badConfig(): array
    {
        return [
            ['services.captcha.turnstile.secret', null, 'PROVIDER_CONFIGURATION_INVALID'],
            ['services.captcha.turnstile.secret', "bad\nsecret", 'PROVIDER_CONFIGURATION_INVALID'],
            ['services.captcha.turnstile.site_key', '', 'PROVIDER_CONFIGURATION_INVALID'],
            ['services.captcha.turnstile.allowed_hostnames', [], 'PROVIDER_CONFIGURATION_INVALID'],
            ['services.captcha.turnstile.allowed_hostnames', ['*.example.test'], 'PROVIDER_CONFIGURATION_INVALID'],
            ['services.captcha.turnstile.allowed_hostnames', ['https://example.test'], 'PROVIDER_CONFIGURATION_INVALID'],
            ['services.captcha.turnstile.actions.login', '', 'PROVIDER_CONFIGURATION_INVALID'],
            ['auth_security.captcha.external.connect_timeout_seconds', 0, 'VERIFICATION_POLICY_INVALID'],
            ['auth_security.captcha.external.timeout_seconds', 1, 'VERIFICATION_POLICY_INVALID'],
            ['auth_security.captcha.external.timeout_seconds', 31, 'VERIFICATION_POLICY_INVALID'],
            ['auth_security.captcha.external.timeout_seconds', '5', 'VERIFICATION_POLICY_INVALID'],
            ['auth_security.captcha.external.clock_skew_seconds', -1, 'VERIFICATION_POLICY_INVALID'],
            ['auth_security.captcha.external.max_age_seconds.turnstile', 301, 'VERIFICATION_POLICY_INVALID'],
        ];
    }

    #[DataProvider('badConfig')]
    public function test_invalid_config_does_not_send_credentials(string $key, mixed $value, string $code): void
    {
        $this->config->set($key, $value);
        $result = $this->verifier->verify('turnstile', 'token', 'login');
        self::assertSame('misconfigured', $result->status);
        self::assertSame($code, $result->code);
        $this->http->assertNothingSent();
    }

    public function test_unknown_provider_or_purpose_cannot_select_arbitrary_url(): void
    {
        self::assertSame('UNSUPPORTED_PROVIDER', $this->verifier->verify('http://127.0.0.1', 'token', 'login')->code);
        self::assertSame('UNSUPPORTED_PURPOSE', $this->verifier->verify('turnstile', 'token', 'arbitrary')->code);
        $this->http->assertNothingSent();
    }

    public static function rejectedResponses(): array
    {
        return [
            [['success' => false, 'error-codes' => ['timeout-or-duplicate']], 'rejected', 'PROVIDER_REJECTED'],
            [['success' => false, 'error-codes' => ['already-seen-response']], 'rejected', 'PROVIDER_REJECTED'],
            [['success' => false, 'error-codes' => ['invalid-input-secret']], 'misconfigured', 'PROVIDER_CREDENTIALS_INVALID'],
            [['success' => false, 'error-codes' => ['sitekey-secret-mismatch']], 'misconfigured', 'PROVIDER_CREDENTIALS_INVALID'],
            [['success' => false, 'error-codes' => ['internal-error']], 'unavailable', 'PROVIDER_UNAVAILABLE'],
            [['success' => 'true'], 'unavailable', 'PROVIDER_RESPONSE_INVALID'],
            [['success' => 1], 'unavailable', 'PROVIDER_RESPONSE_INVALID'],
            [[], 'unavailable', 'PROVIDER_RESPONSE_INVALID'],
            [['success' => true, 'error-codes' => ['contradictory']], 'unavailable', 'PROVIDER_RESPONSE_INVALID'],
            [['success' => false, 'error-codes' => 'invalid'], 'unavailable', 'PROVIDER_RESPONSE_INVALID'],
            [['success' => false, 'error-codes' => [false]], 'unavailable', 'PROVIDER_RESPONSE_INVALID'],
        ];
    }

    #[DataProvider('rejectedResponses')]
    public function test_provider_errors_or_malformed_response_never_verify(array $body, string $status, string $code): void
    {
        $this->http->fake(['*' => Factory::response($body, 200)]);
        $result = $this->verifier->verify('turnstile', 'token', 'login');
        self::assertFalse($result->isVerified());
        self::assertSame($status, $result->status);
        self::assertSame($code, $result->code);
    }

    public static function responseHosts(): array
    {
        return [['example.test', true], ['EXAMPLE.TEST.', true], ['example.test.evil.test', false], ['evil-example.test', false], ['example.test..', false], ['example.test:443', false], [null, false]];
    }

    #[DataProvider('responseHosts')]
    public function test_turnstile_hostname_binding(mixed $host, bool $verified): void
    {
        $this->http->fake(['*' => Factory::response($this->valid(['hostname' => $host]), 200)]);
        self::assertSame($verified, $this->verifier->verify('turnstile', 'token', 'login')->isVerified());
    }

    public function test_turnstile_action_bound_to_server_purpose(): void
    {
        $this->http->fake(['*' => Factory::response($this->valid(['action' => 'register']), 200)]);
        self::assertSame('ACTION_MISMATCH', $this->verifier->verify('turnstile', 'token', 'login')->code);
        self::assertTrue($this->verifier->verify('turnstile', 'fresh-token', 'register')->isVerified());
    }

    public function test_hcaptcha_hostname_and_action_are_not_authentication_factors(): void
    {
        $this->http->fake(['*' => Factory::response($this->valid(['hostname' => 'not-provided', 'action' => null]), 200)]);
        self::assertTrue($this->verifier->verify('hcaptcha', 'token', 'login')->isVerified());
        $this->http->assertSent(fn (Request $request): bool => $request['sitekey'] === 'test-only-site-key');
    }

    public static function ages(): array
    {
        return [['turnstile', 300, 'VERIFIED'], ['turnstile', 301, 'TOKEN_TOO_OLD'], ['hcaptcha', 120, 'VERIFIED'], ['hcaptcha', 121, 'TOKEN_TOO_OLD'], ['turnstile', -5, 'VERIFIED'], ['turnstile', -6, 'TOKEN_FROM_FUTURE']];
    }

    #[DataProvider('ages')]
    public function test_time_boundaries(string $provider, int $age, string $code): void
    {
        $this->http->fake(['*' => Factory::response($this->valid(['challenge_ts' => Carbon::now()->subSeconds($age)->toISOString()]), 200)]);
        self::assertSame($code, $this->verifier->verify($provider, 'token', 'login')->code);
    }

    public static function badTimes(): array
    {
        return [[null], ['tomorrow'], ['2026-10-10'], ['2026-02-30T12:00:00Z'], ['2026-10-10T99:00:00Z']];
    }

    #[DataProvider('badTimes')]
    public function test_invalid_timestamps_are_rejected(mixed $timestamp): void
    {
        $this->http->fake(['*' => Factory::response($this->valid(['challenge_ts' => $timestamp]), 200)]);
        self::assertSame('TIMESTAMP_INVALID', $this->verifier->verify('turnstile', 'token', 'login')->code);
    }

    public function test_timezone_offset_represents_the_same_instant(): void
    {
        $this->http->fake(['*' => Factory::response($this->valid(['challenge_ts' => '2026-10-10T18:00:00+0600']), 200)]);
        self::assertTrue($this->verifier->verify('turnstile', 'token', 'login')->isVerified());
    }

    public static function unavailableResponses(): array
    {
        return [[302, '{"success":true}', 'PROVIDER_HTTP_ERROR'], [500, 'secret-provider-body', 'PROVIDER_HTTP_ERROR'], [200, 'not-json', 'PROVIDER_RESPONSE_INVALID'], [200, str_repeat('x', 65537), 'PROVIDER_RESPONSE_INVALID']];
    }

    #[DataProvider('unavailableResponses')]
    public function test_no_redirect_retry_or_error_body_leak(int $status, string $body, string $code): void
    {
        $this->http->fake(['*' => Factory::response($body, $status, ['Location' => 'https://other.test/collect'])]);
        $result = $this->verifier->verify('turnstile', 'private-token', 'login');
        self::assertSame($code, $result->code);
        self::assertFalse($result->isVerified());
        self::assertStringNotContainsString('private-token', json_encode($result->toArray()));
        self::assertStringNotContainsString('secret-provider-body', json_encode($result->toArray()));
        $this->http->assertSentCount(1);
    }

    public function test_connection_failure_returns_unavailable_not_false_success(): void
    {
        $this->http->fake(fn () => throw new ConnectionException('secret-transport-details'));
        $result = $this->verifier->verify('turnstile', 'token', 'login');
        self::assertSame('unavailable', $result->status);
        self::assertSame('PROVIDER_UNAVAILABLE', $result->code);
        self::assertStringNotContainsString('secret', json_encode($result->toArray()));
    }

    public function test_provider_reported_replay_is_rejected_without_local_success_cache(): void
    {
        $this->http->fake(['*' => $this->http->sequence()->push($this->valid())->push(['success' => false, 'error-codes' => ['timeout-or-duplicate']])]);
        self::assertTrue($this->verifier->verify('turnstile', 'same-token', 'login')->isVerified());
        self::assertFalse($this->verifier->verify('turnstile', 'same-token', 'login')->isVerified());
        $this->http->assertSentCount(2);
    }
}
