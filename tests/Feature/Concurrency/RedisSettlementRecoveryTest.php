<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Enums\DrawStatus;
use App\Enums\PayoutStatus;
use App\Enums\QueueName;
use App\Jobs\Draw\SettleDrawChunkJob;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Feature\Draw\Concerns\BuildsConfirmedSettlementDraws;
use Tests\Feature\Payment\PaymentTestCase;
use Tests\Support\OwnsPrivateRedis;
use Tests\Support\RunsServerFinancialRaces;

final class RedisSettlementRecoveryTest extends PaymentTestCase
{
    use BuildsConfirmedSettlementDraws;
    use OwnsPrivateRedis;
    use RunsServerFinancialRaces;

    private string $directory;

    private int $epoch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireServerRace();
        $this->createPrivateRedis();
        config(['queue.default' => 'redis', 'cache.default' => 'redis', 'database.redis.client' => 'phpredis',
            'database.redis.options.prefix' => 'part25_',
            'database.redis.default.url' => null, 'database.redis.cache.url' => null,
            'database.redis.default.host' => $this->redisSocket, 'database.redis.cache.host' => $this->redisSocket,
            'database.redis.default.port' => 0, 'database.redis.cache.port' => 0,
            'database.redis.default.password' => null, 'database.redis.cache.password' => null,
            'database.redis.default.database' => 0, 'database.redis.cache.database' => 1]);
        app('redis')->purge('default');
        app('redis')->purge('cache');
        app()->forgetInstance('cache.store');
        Cache::purge('redis');
        $this->configureSafety();
        $this->epoch = now()->timestamp;
        $this->directory = storage_path('framework/testing/redis-settlement/'.bin2hex(random_bytes(10)));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->destroyPrivateRedis();
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        $this->travelBack();
        parent::tearDown();
    }

    private function fixture(): array
    {
        $f = $this->publishedDrawWithBets(['111', '222'], 'redis-recovery');
        $f['run'] = app(ChunkedSettlementOrchestrator::class)->start((int) $f['draw']->id, 1);
        $f['finance'] = FinancialTransaction::query()->count();
        $f['entries'] = LedgerEntry::query()->count();
        Queue::connection('redis')->push(new SettleDrawChunkJob((int) $f['draw']->id, (int) $f['run']->id, 0), '', QueueName::FinancialCritical->value);

        return $f;
    }

    private function probe(string $phase = 'none', int $offset = 0): array
    {
        $file = $this->directory.'/'.bin2hex(random_bytes(6)).'.json';
        file_put_contents($file, json_encode(['phase' => $phase, 'time' => $this->epoch + $offset,
            'threshold' => (string) config('finance.prize_payout.auto_approve_below')], JSON_THROW_ON_ERROR));
        chmod($file, 0600);
        $process = new Process([PHP_BINARY, base_path('tests/Support/RedisSettlementProbe.php'), $file], base_path(), [
            'REDIS_SETTLEMENT_PROBE' => '1', 'APP_ENV' => 'testing', 'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => 'thai_lottery_test', 'DB_SOCKET' => DB::connection()->getConfig('unix_socket'),
            'DB_USERNAME' => DB::connection()->getConfig('username'), 'DB_PASSWORD' => DB::connection()->getConfig('password'),
            'DB_URL' => '', 'APP_KEY' => config('app.key'), 'LOG_CHANNEL' => 'null',
            'REDIS_HOST' => $this->redisSocket, 'REDIS_PORT' => '0', 'REDIS_URL' => '', 'REDIS_PASSWORD' => '',
            'REDIS_CLIENT' => 'phpredis', 'REDIS_DB' => '0', 'REDIS_CACHE_DB' => '1', 'REDIS_PREFIX' => 'part25_',
            'QUEUE_CONNECTION' => 'redis', 'CACHE_STORE' => 'redis', 'CACHE_PREFIX' => config('cache.prefix'),
        ]);
        $process->setTimeout(20);
        $process->start();

        return [$process, $file];
    }

    private function paused(string $phase): array
    {
        [$process, $file] = $this->probe($phase);
        $deadline = microtime(true) + 8;
        while (! is_file($file.'.paused') && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file($file.'.paused')) {
            if ($process->isRunning()) {
                $process->signal(SIGKILL);
                $process->wait();
            }
            $this->fail('Redis worker did not reach the exact real fault boundary: '.$process->getOutput().' '.$process->getErrorOutput());
        }
        $marker = json_decode(file_get_contents($file.'.paused'), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame($phase, $marker['phase']);
        $this->assertSame($process->getPid(), $marker['pid']);
        $this->assertSame(0, $marker['transaction_level']);
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $marker['connection']);

        return [$process, $marker];
    }

    private function finishWorker(Process $process): void
    {
        $process->wait();
        $this->assertSame(0, $process->getExitCode(), 'Actual Redis worker must exit cleanly: '.$process->getOutput().' '.$process->getErrorOutput());
        $this->assertSame('', $process->getErrorOutput());
        $out = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertTrue($out['worked']);
        $this->assertSame(0, $out['worker_exit']);
    }

    private function drain(int $offset = 10): void
    {
        for ($i = 0; $i < 20 && Queue::connection('redis')->size(QueueName::FinancialCritical->value) > 0; $i++) {
            [$worker] = $this->probe(offset: $offset + $i * 10);
            $this->finishWorker($worker);
            $this->assertSame(0, DB::table('failed_jobs')->count());
        }
        $this->assertSame(0, Queue::connection('redis')->size(QueueName::FinancialCritical->value));
    }

    private function assertMoney(array $f, bool $pending = false): void
    {
        $run = $f['run']->fresh();
        $this->assertTrue($run->isComplete());
        $this->assertSame(3, $run->bets_settled);
        $this->assertSame(3, $run->chunks_processed);
        $this->assertSame(DrawStatus::Completed, $f['draw']->fresh()->status);
        $this->assertSame(1, Payout::query()->count());
        $this->assertSame($pending ? PayoutStatus::Pending : PayoutStatus::Completed, Payout::query()->sole()->status);
        $this->assertSame($pending ? '470.00' : '9470.00', (string) $f['wallet']->fresh()->balance);
        $this->assertSame($f['finance'] + ($pending ? 0 : 1), FinancialTransaction::query()->count());
        $this->assertSame($f['entries'] + ($pending ? 0 : 2), LedgerEntry::query()->count());
    }

    private function witness(array $restart, string $phase): void
    {
        $path = getenv('REDIS_RECOVERY_WITNESS');
        if (is_string($path) && str_starts_with($path, sys_get_temp_dir().'/redis-recovery-')) {
            file_put_contents($path, json_encode($restart + ['class' => self::class, 'name' => $this->name(),
                'phase' => $phase, 'recovered' => true, 'aof_fsync' => 'always', 'cache_ttl' => 'real wall time'], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        }
    }

    public function test_redis_restart_retains_queued_work(): void
    {
        $f = $this->fixture();
        $restart = $this->restartPrivateRedis();
        $this->assertSame(1, Queue::connection('redis')->size(QueueName::FinancialCritical->value));
        $this->drain();
        $this->assertMoney($f);
        $this->witness($restart, 'queued');
    }

    private function liveWorkerRestart(string $phase, bool $pending = false): void
    {
        if ($pending) {
            $this->configureSafety('LIVE', '0.00');
        }
        $f = $this->fixture();
        [$worker] = $this->paused($phase);
        try {
            $this->assertSame(1, $f['run']->fresh()->bets_settled);
            $restart = $this->restartPrivateRedis();
            $worker->signal(SIGCONT);
            $this->finishWorker($worker);
            // A stale client's release must not orphan its 300-second physical Redis lock.
            $client = $this->privateRedis(1);
            $this->assertSame([], $client->keys('*settlement-draw:*'));
            $client->close();
            $this->drain();
            $this->assertMoney($f, $pending);
            $this->witness($restart, $phase);
        } finally {
            if ($worker->isRunning()) {
                $worker->signal(SIGKILL);
                $worker->wait();
            }
        }
    }

    public function test_redis_restart_after_commit_releases_stale_client_lock(): void
    {
        $this->liveWorkerRestart('committed');
    }

    public function test_redis_restart_before_ack_reconnects_live_worker(): void
    {
        $this->liveWorkerRestart('released');
    }

    public function test_redis_restart_manual_approval_remains_uncredited(): void
    {
        $this->liveWorkerRestart('committed', true);
    }

    public function test_redis_restart_and_worker_death_replays_reserved_job(): void
    {
        $f = $this->fixture();
        [$worker] = $this->paused('released');
        $worker->signal(SIGKILL);
        $worker->wait();
        $this->assertSame(SIGKILL, $worker->getTermSignal());
        $restart = $this->restartPrivateRedis();
        $this->drain(301); // Only queue visibility is clock-accelerated, never Redis lock TTL.
        $this->assertMoney($f);
        $this->witness($restart, 'worker-death');
    }

    public function test_redis_queue_loss_requires_operator_handoff_without_duplicate_credit(): void
    {
        $f = $this->fixture();
        [$worker] = $this->paused('committed');
        $worker->signal(SIGKILL);
        $worker->wait();
        $restart = $this->restartPrivateRedis();
        $client = $this->privateRedis();
        $client->flushAll(); // This instance belongs exclusively to this fixture.
        $client->close();
        $this->assertSame(0, Queue::connection('redis')->size(QueueName::FinancialCritical->value));
        $this->assertSame(1, $f['run']->fresh()->bets_settled);
        $this->assertSame('9470.00', (string) $f['wallet']->fresh()->balance);
        $exit = Artisan::call('lottery:settle-draw-chunked', ['--draw' => (string) $f['draw']->id, '--queue' => true]);
        $this->assertSame(0, $exit);
        $this->drain();
        $this->assertMoney($f);
        $this->witness($restart, 'explicit-data-loss');
    }

    public function test_redis_unavailable_handoff_is_a_controlled_failure(): void
    {
        $f = $this->fixture();
        $thrown = false;
        $exit = null;
        $restart = $this->restartPrivateRedis(function () use ($f, &$thrown, &$exit): void {
            try {
                $exit = Artisan::call('lottery:settle-draw-chunked', ['--draw' => (string) $f['draw']->id, '--queue' => true]);
            } catch (\Throwable) {
                $thrown = true;
            }
        });
        $this->assertFalse($thrown, 'Broker failure must return a controlled CLI refusal, not an uncaught socket error.');
        $this->assertSame(1, $exit);
        $this->assertSame(0, $f['run']->fresh()->bets_settled);
        $this->assertSame(0, Payout::query()->count());
        $this->drain();
        $this->assertMoney($f);
        $this->witness($restart, 'broker-unavailable');
    }
}
