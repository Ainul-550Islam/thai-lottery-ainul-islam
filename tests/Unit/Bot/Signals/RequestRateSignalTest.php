<?php

declare(strict_types=1);

namespace Tests\Unit\Bot\Signals;

use App\Services\Bot\DeviceFingerprintService;
use App\Services\Bot\Signals\RequestRateSignal;
use App\Services\Velocity\VelocityWindow;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RequestRateSignalTest extends TestCase
{
    private Repository $config;

    private CacheRepository $cache;

    private DeviceFingerprintService $fingerprints;

    private RequestRateSignal $signal;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'UTC'));
        $this->config = new Repository(['bot' => ['fingerprint' => ['key' => str_repeat('k', 32)], 'request_rate' => ['window_seconds' => 60, 'max_requests' => 2]]]);
        $this->cache = new CacheRepository(new ArrayStore);
        $this->fingerprints = new DeviceFingerprintService($this->config);
        $this->signal = new RequestRateSignal($this->config, $this->cache, $this->fingerprints, new VelocityWindow);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function request(string $path = '/login', string $ip = '192.0.2.1'): Request
    {
        return Request::create($path, 'POST', [], [], [], ['REMOTE_ADDR' => $ip, 'HTTP_USER_AGENT' => 'Browser/1', 'HTTP_ACCEPT_LANGUAGE' => 'en']);
    }

    private function key(Request $request): string
    {
        return 'bot:rate:v1:'.$this->fingerprints->fingerprint($request);
    }

    public function test_counts_across_paths_and_threshold_boundary(): void
    {
        self::assertSame(0, $this->signal->score($this->request())->strength);
        self::assertSame(0, $this->signal->score($this->request('/register'))->strength);
        self::assertSame(['REQUEST_RATE_EXCEEDED'], $this->signal->score($this->request('/forgot-password'))->reasons);
        self::assertCount(3, $this->cache->get($this->key($this->request())));
        for ($i = 0; $i < 10; $i++) {
            $this->signal->score($this->request());
        }
        self::assertCount(3, $this->cache->get($this->key($this->request())));
    }

    public function test_window_expiration_and_independent_network(): void
    {
        $this->signal->score($this->request());
        $this->signal->score($this->request());
        self::assertSame(0, $this->signal->score($this->request(ip: '203.0.113.1'))->strength);
        Carbon::setTestNow(Carbon::now()->addSeconds(60));
        self::assertSame(0, $this->signal->score($this->request())->strength);
        self::assertCount(1, $this->cache->get($this->key($this->request())));
    }

    public function test_busy_lock_does_not_mutate_state(): void
    {
        $key = $this->key($this->request());
        $lock = $this->cache->getStore()->lock($key.':lock', 10);
        self::assertTrue($lock->get());
        try {
            $this->signal->score($this->request());
            self::fail('Expected busy lock failure.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('lock is busy', $e->getMessage());
            self::assertNull($this->cache->get($key));
        } finally {
            $lock->release();
        }
    }

    public function test_corrupt_cache_is_not_treated_as_zero(): void
    {
        $key = $this->key($this->request());
        $this->cache->put($key, 'bad-state', 60);
        try {
            $this->signal->score($this->request());
            self::fail('Expected corrupt state failure.');
        } catch (RuntimeException) {
            self::assertSame('bad-state', $this->cache->get($key));
        }
        $lock = $this->cache->getStore()->lock($key.':lock', 10);
        self::assertTrue($lock->get());
        $lock->release();
    }

    public function test_cache_without_locks_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new RequestRateSignal($this->config, new CacheRepository($this->createMock(Store::class)), $this->fingerprints, new VelocityWindow))->score($this->request());
    }

    public static function invalidSettings(): array
    {
        return [['window_seconds', 0], ['window_seconds', 86401], ['max_requests', 0], ['max_requests', 10001], ['max_requests', '2']];
    }

    #[DataProvider('invalidSettings')]
    public function test_bad_settings_rejected(string $key, mixed $value): void
    {
        $this->config->set('bot.request_rate.'.$key, $value);
        $this->expectException(InvalidArgumentException::class);
        $this->signal->score($this->request());
    }
}
