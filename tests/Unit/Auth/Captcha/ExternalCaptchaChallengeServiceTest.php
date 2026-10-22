<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Captcha;

use App\Services\Auth\Captcha\ExternalCaptchaChallengeService;
use App\Services\Auth\Captcha\ExternalCaptchaVerifier;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExternalCaptchaChallengeServiceTest extends TestCase
{
    private Repository $config;

    private Factory $http;

    private CacheRepository $cache;

    private ExternalCaptchaChallengeService $service;

    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'UTC'));
        $this->config = new Repository(['services' => ['captcha' => [
            'turnstile' => ['site_key' => 'public-site-key', 'secret' => 'private-secret',
                'allowed_hostnames' => ['example.test'],
                'actions' => ['login' => 'login', 'register' => 'register', 'password_reset' => 'password_reset']],
            'hcaptcha' => ['site_key' => 'public-hcaptcha-key', 'secret' => 'private-hcaptcha-secret'],
        ]]]);
        $this->http = new Factory;
        $this->http->preventStrayRequests();
        $this->cache = new CacheRepository(new ArrayStore);
        $this->service = $this->adapter($this->cache);
        $this->request = $this->request();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function adapter(CacheContract $cache): ExternalCaptchaChallengeService
    {
        return new ExternalCaptchaChallengeService($this->config, $cache, new ExternalCaptchaVerifier($this->config, $this->http));
    }

    private function request(): Request
    {
        $request = Request::create('/login', 'POST');
        $session = new Store('external-captcha-test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);

        return $request;
    }

    private function fakeSuccess(string $purpose = 'login'): void
    {
        $this->http->fake(['*' => Factory::response([
            'success' => true, 'hostname' => 'example.test', 'action' => $purpose,
            'challenge_ts' => Carbon::now()->toISOString(),
        ])]);
    }

    public static function providersAndPurposes(): array
    {
        return [
            ['turnstile', 'login'], ['turnstile', 'register'], ['turnstile', 'password_reset'],
            ['hcaptcha', 'login'], ['hcaptcha', 'register'], ['hcaptcha', 'password_reset'],
        ];
    }

    #[DataProvider('providersAndPurposes')]
    public function test_round_trip_delegates_to_real_verifier(string $provider, string $purpose): void
    {
        $widget = $this->service->issue($this->request, $provider, $purpose);
        self::assertSame($provider, $widget['provider']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $widget['nonce']);
        self::assertSame($provider === 'turnstile' ? $purpose : null, $widget['action']);
        self::assertStringNotContainsString('private-', json_encode($widget));
        $this->fakeSuccess($purpose);
        self::assertTrue($this->service->verify($this->request, $provider, $purpose, $widget['nonce'], 'vendor-token')->isVerified());
        self::assertSame('CHALLENGE_INVALID', $this->service->verify($this->request, $provider, $purpose, $widget['nonce'], 'vendor-token')->code);
        $this->http->assertSentCount(1);
        self::assertStringNotContainsString('vendor-token', json_encode($this->request->session()->all()));
    }

    public function test_different_session_cannot_use_nonce(): void
    {
        $widget = $this->service->issue($this->request, 'turnstile', 'login');
        self::assertSame('CHALLENGE_INVALID', $this->service->verify($this->request(), 'turnstile', 'login', $widget['nonce'], 'token')->code);
        $this->http->assertNothingSent();
    }

    public static function wrongBindings(): array
    {
        return [['hcaptcha', 'login'], ['turnstile', 'register']];
    }

    #[DataProvider('wrongBindings')]
    public function test_wrong_binding_consumes_challenge_without_http(string $provider, string $purpose): void
    {
        $widget = $this->service->issue($this->request, 'turnstile', 'login');
        self::assertSame('CHALLENGE_BINDING_MISMATCH', $this->service->verify($this->request, $provider, $purpose, $widget['nonce'], 'token')->code);
        self::assertSame('CHALLENGE_INVALID', $this->service->verify($this->request, 'turnstile', 'login', $widget['nonce'], 'token')->code);
        $this->http->assertNothingSent();
    }

    public function test_rendered_site_key_cannot_drift_before_submission(): void
    {
        $widget = $this->service->issue($this->request, 'turnstile', 'login');
        $this->config->set('services.captcha.turnstile.site_key', 'changed-public-key');
        self::assertSame('CHALLENGE_BINDING_MISMATCH', $this->service->verify($this->request, 'turnstile', 'login', $widget['nonce'], 'token')->code);
        $this->http->assertNothingSent();
    }

    public function test_expiry_boundary_rejects_before_provider(): void
    {
        $widget = $this->service->issue($this->request, 'turnstile', 'login');
        Carbon::setTestNow(Carbon::now()->addSeconds(300));
        self::assertSame('CHALLENGE_EXPIRED', $this->service->verify($this->request, 'turnstile', 'login', $widget['nonce'], 'token')->code);
        $this->http->assertNothingSent();
    }

    public function test_unexpired_challenge_uses_fresh_vendor_timestamp(): void
    {
        $widget = $this->service->issue($this->request, 'turnstile', 'login');
        Carbon::setTestNow(Carbon::now()->addSeconds(299));
        $this->fakeSuccess();
        self::assertTrue($this->service->verify($this->request, 'turnstile', 'login', $widget['nonce'], 'token')->isVerified());
    }

    public function test_stale_parallel_session_copy_is_rejected_by_shared_claim(): void
    {
        $widget = $this->service->issue($this->request, 'turnstile', 'login');
        $stale = $this->request();
        $stale->session()->put('auth_member.external_captcha', $this->request->session()->get('auth_member.external_captcha'));
        $this->fakeSuccess();
        self::assertTrue($this->service->verify($this->request, 'turnstile', 'login', $widget['nonce'], 'token')->isVerified());
        $otherWorker = $this->adapter($this->cache);
        self::assertSame('CHALLENGE_ALREADY_USED', $otherWorker->verify($stale, 'turnstile', 'login', $widget['nonce'], 'token')->code);
        $this->http->assertSentCount(1);
    }

    public function test_cache_failure_never_calls_provider_or_grants_success(): void
    {
        $cache = $this->createMock(CacheContract::class);
        $cache->expects(self::once())->method('add')->willThrowException(new RuntimeException('private-cache-details'));
        $adapter = $this->adapter($cache);
        $widget = $adapter->issue($this->request, 'turnstile', 'login');
        $result = $adapter->verify($this->request, 'turnstile', 'login', $widget['nonce'], 'token');
        self::assertSame(['status' => 'unavailable', 'code' => 'CHALLENGE_STORE_UNAVAILABLE'], $result->toArray());
        self::assertSame('CHALLENGE_INVALID', $adapter->verify($this->request, 'turnstile', 'login', $widget['nonce'], 'token')->code);
        $this->http->assertNothingSent();
    }

    public function test_provider_outage_consumes_nonce_and_retains_claim(): void
    {
        $widget = $this->service->issue($this->request, 'turnstile', 'login');
        $this->http->fake(['*' => Factory::response('unavailable', 503)]);
        self::assertSame('unavailable', $this->service->verify($this->request, 'turnstile', 'login', $widget['nonce'], 'token')->status);
        self::assertTrue($this->cache->get('auth:captcha:claim:'.hash('sha256', $widget['nonce'])));
        self::assertSame('CHALLENGE_INVALID', $this->service->verify($this->request, 'turnstile', 'login', $widget['nonce'], 'token')->code);
        $this->http->assertSentCount(1);
    }

    public function test_invalid_vendor_response_still_consumes_nonce(): void
    {
        $widget = $this->service->issue($this->request, 'turnstile', 'login');
        self::assertSame('TOKEN_INVALID', $this->service->verify($this->request, 'turnstile', 'login', $widget['nonce'], true)->code);
        self::assertSame('CHALLENGE_INVALID', $this->service->verify($this->request, 'turnstile', 'login', $widget['nonce'], 'token')->code);
        $this->http->assertNothingSent();
    }

    public function test_storage_is_bounded_and_expired_entries_pruned(): void
    {
        $first = $this->service->issue($this->request, 'turnstile', 'login');
        for ($i = 0; $i < 10; $i++) {
            $this->service->issue($this->request, 'turnstile', 'login');
        }
        $entries = $this->request->session()->get('auth_member.external_captcha');
        self::assertCount(10, $entries);
        self::assertArrayNotHasKey($first['nonce'], $entries);
        self::assertStringNotContainsString('private-secret', json_encode($entries));
        Carbon::setTestNow(Carbon::now()->addSeconds(300));
        $this->service->issue($this->request, 'turnstile', 'login');
        self::assertCount(1, $this->request->session()->get('auth_member.external_captcha'));
    }

    public function test_missing_session_is_rejected(): void
    {
        self::assertSame('CHALLENGE_INVALID', $this->service->verify(Request::create('/'), 'turnstile', 'login', str_repeat('a', 64), 'token')->code);
        $this->expectException(LogicException::class);
        $this->service->issue(Request::create('/'), 'turnstile', 'login');
    }

    public static function invalidNonces(): array
    {
        return [[null], [true], [[]], [''], [str_repeat('A', 64)], [str_repeat('a', 65)]];
    }

    #[DataProvider('invalidNonces')]
    public function test_malformed_nonce_is_rejected_without_http(mixed $nonce): void
    {
        self::assertSame('CHALLENGE_INVALID', $this->service->verify($this->request, 'turnstile', 'login', $nonce, 'token')->code);
        $this->http->assertNothingSent();
    }

    public function test_client_driver_and_purpose_fields_do_not_control_adapter(): void
    {
        $this->request->merge(['provider' => 'hcaptcha', 'purpose' => 'register', 'captcha' => true]);
        $widget = $this->service->issue($this->request, 'turnstile', 'login');
        self::assertSame('turnstile', $widget['provider']);
        self::assertSame('login', $widget['action']);
        $this->fakeSuccess();
        self::assertTrue($this->service->verify($this->request, 'turnstile', 'login', $widget['nonce'], 'token')->isVerified());
    }

    public function test_unknown_provider_or_invalid_public_config_cannot_issue(): void
    {
        self::assertSame('CHALLENGE_CONFIGURATION_INVALID', $this->service->verify($this->request, 'unknown', 'login', 'nonce', 'token')->code);
        $this->config->set('services.captcha.turnstile.site_key', '');
        $this->expectException(LogicException::class);
        $this->service->issue($this->request, 'turnstile', 'login');
    }
}
