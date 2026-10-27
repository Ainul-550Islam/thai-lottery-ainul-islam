<?php

declare(strict_types=1);

namespace Tests\Unit\Bot\Signals;

use App\Services\Bot\BotDetectionService;
use App\Services\Bot\BotScoreCalculator;
use App\Services\Bot\DeviceFingerprintService;
use App\Services\Bot\Enums\BotDecision;
use App\Services\Bot\Signals\DeviceReuseSignal;
use App\Services\Bot\Signals\FormTimingSignal;
use App\Services\Bot\Signals\HeaderConsistencySignal;
use App\Services\Bot\Signals\RequestRateSignal;
use App\Services\Bot\Signals\UserAgentSignal;
use App\Services\Velocity\VelocityWindow;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/** Real SQLite query tests on a minimal projection schema; no project DB touched. */
final class DeviceReuseSignalTest extends TestCase
{
    private Repository $config;

    private Connection $database;

    private DeviceReuseSignal $signal;

    private Request $request;

    private string $fingerprint;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', 'UTC'));
        $capsule = new Manager;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $this->database = $capsule->getConnection();
        $schema = $this->database->getSchemaBuilder();
        $schema->create('authentication_attempts', function (Blueprint $table): void {
            $table->integer('user_id')->nullable();
            $table->string('device_fingerprint');
            $table->string('outcome');
            $table->timestamp('attempted_at');
        });
        $schema->create('trusted_devices', function (Blueprint $table): void {
            $table->integer('user_id');
            $table->string('device_fingerprint');
            $table->string('trust_status');
            $table->timestamp('registered_at');
            $table->timestamp('revoked_at')->nullable();
        });
        $this->config = new Repository(['bot' => ['fingerprint' => ['key' => str_repeat('k', 32)], 'device_reuse' => ['max_accounts' => 2, 'lookback_days' => 30]]]);
        $fingerprints = new DeviceFingerprintService($this->config);
        $this->request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '192.0.2.1', 'HTTP_USER_AGENT' => 'Browser/1']);
        $this->fingerprint = $fingerprints->fingerprint($this->request);
        $this->signal = new DeviceReuseSignal($this->config, $this->database, $fingerprints);
    }

    protected function tearDown(): void
    {
        $this->database->disconnect();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function attempt(?int $user, array $overrides = []): void
    {
        $this->database->table('authentication_attempts')->insert(array_replace([
            'user_id' => $user, 'device_fingerprint' => $this->fingerprint,
            'outcome' => 'succeeded', 'attempted_at' => Carbon::now()->toDateTimeString(),
        ], $overrides));
    }

    private function trusted(int $user, array $overrides = []): void
    {
        $this->database->table('trusted_devices')->insert(array_replace([
            'user_id' => $user, 'device_fingerprint' => $this->fingerprint,
            'trust_status' => 'trusted', 'registered_at' => Carbon::now()->toDateTimeString(), 'revoked_at' => null,
        ], $overrides));
    }

    public function test_empty_boundary_union_deduplication_and_breach(): void
    {
        self::assertSame(0, $this->signal->score($this->request)->strength);
        $this->attempt(1);
        $this->attempt(1);
        $this->trusted(1);
        $this->trusted(2);
        self::assertSame(0, $this->signal->score($this->request)->strength);
        $this->attempt(3);
        self::assertSame(['DEVICE_SHARED_ACCOUNTS'], $this->signal->score($this->request)->reasons);
    }

    public function test_untrusted_failed_stale_future_and_null_evidence_excluded(): void
    {
        $this->attempt(1, ['outcome' => 'failed']);
        $this->attempt(2, ['attempted_at' => Carbon::now()->subDays(31)->toDateTimeString()]);
        $this->attempt(3, ['attempted_at' => Carbon::now()->addDay()->toDateTimeString()]);
        $this->attempt(null);
        $this->trusted(4, ['trust_status' => 'pending']);
        $this->trusted(5, ['revoked_at' => Carbon::now()->toDateTimeString()]);
        $this->trusted(6, ['registered_at' => Carbon::now()->addDay()->toDateTimeString()]);
        self::assertSame(0, $this->signal->score($this->request)->strength);
    }

    public function test_fingerprint_scope_and_client_header_cannot_override(): void
    {
        foreach ([1, 2, 3] as $id) {
            $this->attempt($id, ['device_fingerprint' => str_repeat('f', 64)]);
        }
        $this->request->headers->set('X-Device-Fingerprint', str_repeat('f', 64));
        self::assertSame(0, $this->signal->score($this->request)->strength);
    }

    public function test_lower_time_boundary_is_inclusive_and_read_only(): void
    {
        foreach ([1, 2, 3] as $id) {
            $this->attempt($id, ['attempted_at' => Carbon::now()->subDays(30)->toDateTimeString()]);
        }
        self::assertSame(100, $this->signal->score($this->request)->strength);
        self::assertSame(3, $this->database->table('authentication_attempts')->count());
        self::assertSame(0, $this->database->table('trusted_devices')->count());
    }

    public function test_five_real_signals_compose_once_without_enforcement(): void
    {
        $settings = require dirname(__DIR__, 4).'/config/bot.php';
        $settings['enabled'] = true;
        $settings['mode'] = 'log';
        $settings['thresholds'] = ['challenge' => 40, 'block' => 80];
        $settings['fingerprint']['key'] = str_repeat('k', 32);
        $settings['request_rate'] = ['window_seconds' => 60, 'max_requests' => 1];
        $settings['device_reuse'] = ['max_accounts' => 2, 'lookback_days' => 30];
        $settings['form_timing'] = ['field' => '_bot_timing', 'min_submit_seconds' => 2, 'ttl_seconds' => 600, 'max_tokens_per_session' => 10];
        $config = new Repository(['bot' => $settings]);
        $request = Request::create('/register', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.1']);
        $request->headers->replace(['User-Agent' => 'curl/8']);
        $session = new Store('composition', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);
        $request->attributes->set('bot.form_timing_required', true);
        $request->attributes->set('bot.form_purpose', 'register');
        $fingerprints = new DeviceFingerprintService($config);
        $fingerprint = $fingerprints->fingerprint($request);
        foreach ([1, 2, 3] as $id) {
            $this->attempt($id, ['device_fingerprint' => $fingerprint]);
        }
        $cache = new CacheRepository(new ArrayStore);
        $rate = new RequestRateSignal($config, $cache, $fingerprints, new VelocityWindow);
        $timing = new FormTimingSignal($config);
        $request->request->set('_bot_timing', $timing->issue($request, 'register'));
        $rate->score($request);
        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $engine = new BotDetectionService($config, new BotScoreCalculator($config), new NullLogger, [
            'user_agent' => new UserAgentSignal,
            'header_consistency' => new HeaderConsistencySignal,
            'form_timing' => $timing,
            'request_rate' => $rate,
            'device_reuse' => new DeviceReuseSignal($config, $this->database, $fingerprints),
        ]);
        $result = $engine->detect($request);
        self::assertSame(90, $result->score);
        self::assertSame(BotDecision::Block, $result->assessedDecision);
        self::assertSame(BotDecision::Allow, $result->decision);
        self::assertSame($result, $engine->detect($request));
        self::assertCount(2, $cache->get('bot:rate:v1:'.$fingerprint));
        self::assertSame([], $session->get('bot.form_timing.tokens'));
        self::assertSame(3, $this->database->table('authentication_attempts')->count());
    }

    public static function invalidSettings(): array
    {
        return [['max_accounts', 0], ['max_accounts', 101], ['max_accounts', '2'], ['lookback_days', 0], ['lookback_days', 366]];
    }

    #[DataProvider('invalidSettings')]
    public function test_bad_settings_rejected(string $key, mixed $value): void
    {
        $this->config->set('bot.device_reuse.'.$key, $value);
        $this->expectException(InvalidArgumentException::class);
        $this->signal->score($this->request);
    }
}
