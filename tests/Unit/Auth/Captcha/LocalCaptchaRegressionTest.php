<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Captcha;

use App\Services\Auth\CaptchaService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/** DB-free regression for the unchanged local service, not a browser journey. */
final class LocalCaptchaRegressionTest extends TestCase
{
    private Container $previousContainer;

    private Repository $config;

    private CaptchaService $service;

    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $this->config = new Repository(['auth_security' => ['captcha' => [
            'enabled' => true, 'driver' => 'fixed', 'ttl_seconds' => 60,
            'max_failures' => 2, 'cooldown_seconds' => 30,
        ]]]);
        $container->instance('config', $this->config);
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'UTC'));
        $this->service = new CaptchaService;
        $this->request = Request::create('/login', 'POST');
        $session = new Store('captcha-regression', new ArraySessionHandler(120));
        $session->start();
        $this->request->setLaravelSession($session);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_fixed_challenge_hash_and_one_time_consumption(): void
    {
        $challenge = $this->service->issue($this->request);
        self::assertSame('2 + 3', $challenge['question']);
        self::assertSame(48, strlen($challenge['token']));
        $entry = $this->request->session()->get('auth_member.captcha.challenges')[$challenge['token']];
        self::assertSame(hash('sha256', '5'), $entry['answer_hash']);
        self::assertArrayNotHasKey('answer', $entry);
        self::assertTrue($this->service->verify($this->request, $challenge['token'], ' 5 '));
        self::assertFalse($this->service->verify($this->request, $challenge['token'], '5'));
    }

    public function test_wrong_answer_also_consumes_token(): void
    {
        $challenge = $this->service->issue($this->request);
        self::assertFalse($this->service->verify($this->request, $challenge['token'], 'wrong'));
        self::assertFalse($this->service->verify($this->request, $challenge['token'], '5'));
    }

    public function test_expiry_including_minimum_thirty_second_ttl(): void
    {
        $this->config->set('auth_security.captcha.ttl_seconds', 1);
        $challenge = $this->service->issue($this->request);
        Carbon::setTestNow(Carbon::now()->addSeconds(29));
        self::assertTrue($this->service->verify($this->request, $challenge['token'], '5'));
        $challenge = $this->service->issue($this->request);
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        self::assertFalse($this->service->verify($this->request, $challenge['token'], '5'));
    }

    public function test_failure_budget_cooldown_and_reset(): void
    {
        self::assertFalse($this->service->verify($this->request, null, null));
        self::assertFalse($this->service->verify($this->request, 'unknown', 'true'));
        self::assertSame(30, $this->service->cooldownRemaining($this->request));
        $challenge = $this->service->issue($this->request);
        self::assertFalse($this->service->verify($this->request, $challenge['token'], '5'));
        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        self::assertSame(0, $this->service->cooldownRemaining($this->request));
        self::assertTrue($this->service->verify($this->request, $challenge['token'], '5'));
        self::assertSame(0, $this->request->session()->get('auth_member.captcha.failures'));
    }

    public function test_session_challenge_store_is_capped_and_oldest_removed(): void
    {
        $first = $this->service->issue($this->request);
        for ($i = 0; $i < 10; $i++) {
            $this->service->issue($this->request);
        }
        $entries = $this->request->session()->get('auth_member.captcha.challenges');
        self::assertCount(10, $entries);
        self::assertArrayNotHasKey($first['token'], $entries);
    }

    public function test_only_server_configuration_disables_gate(): void
    {
        $this->request->merge(['captcha' => true, 'captcha_enabled' => false]);
        self::assertTrue($this->service->isEnabled());
        self::assertFalse($this->service->verify($this->request, null, 'true'));
        $this->config->set('auth_security.captcha.enabled', false);
        self::assertFalse($this->service->isEnabled());
        self::assertTrue($this->service->verify($this->request, null, null));
    }

    public function test_local_random_arithmetic_still_verifies(): void
    {
        $this->config->set('auth_security.captcha.driver', 'local');
        $challenge = $this->service->issue($this->request);
        self::assertSame(1, preg_match('/^(\d+) ([+−×]) (\d+)$/u', $challenge['question'], $matches));
        $a = (int) $matches[1];
        $b = (int) $matches[3];
        $answer = match ($matches[2]) {
            '+' => $a + $b,
            '−' => $a - $b,
            '×' => $a * $b,
        };
        self::assertTrue($this->service->verify($this->request, $challenge['token'], (string) $answer));
    }
}
