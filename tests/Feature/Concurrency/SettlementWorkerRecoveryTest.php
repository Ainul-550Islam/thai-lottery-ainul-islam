<?php

declare(strict_types=1);

namespace Tests\Feature\Concurrency;

use App\Enums\DrawStatus;
use App\Enums\PayoutStatus;
use App\Enums\QueueName;
use App\Jobs\Draw\FinalizeDrawSettlementJob;
use App\Jobs\Notification\SendFinancialAlertJob;
use App\Jobs\Draw\ProcessPrizeSettlementJob;
use App\Jobs\Draw\SettleDrawChunkJob;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Payout;
use App\Models\SettlementRun;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\Feature\Draw\Concerns\BuildsConfirmedSettlementDraws;
use Tests\Feature\Payment\PaymentTestCase;
use Tests\Support\RunsServerFinancialRaces;

/** Real SIGKILL, database cache locks, serialized deliveries and fresh Artisan workers. */
final class SettlementWorkerRecoveryTest extends PaymentTestCase
{
    use BuildsConfirmedSettlementDraws;
    use RunsServerFinancialRaces;

    private int $epoch;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireServerRace();
        $this->assertTrue(function_exists('posix_kill') && function_exists('pcntl_signal'));
        $this->configureSafety();
        config(['queue.default' => 'database', 'cache.default' => 'database']);
        app()->forgetInstance('cache.store');
        Cache::purge('database');
        $this->assertInstanceOf(DatabaseStore::class, app(Repository::class)->getStore());
        $this->epoch = now()->timestamp;
        $this->at(0);
        $this->directory = storage_path('framework/testing/settlement-recovery/'.bin2hex(random_bytes(12)));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        $this->travelBack();
        parent::tearDown();
    }

    private function at(int $seconds): void
    {
        $this->travelTo(Carbon::createFromTimestamp($this->epoch + $seconds));
    }

    private function probe(int $seconds, string $phase = 'none', int $lag = 0): array
    {
        $file = $this->directory.'/'.bin2hex(random_bytes(8)).'.json';
        file_put_contents($file, json_encode(['time' => $this->epoch + $seconds, 'phase' => $phase,
            'reservation_lag' => $lag, 'threshold' => (string) config('finance.prize_payout.auto_approve_below')], JSON_THROW_ON_ERROR));
        chmod($file, 0600);
        $process = new Process([PHP_BINARY, base_path('tests/Support/SettlementRecoveryProbe.php'), $file], base_path(), [
            'SETTLEMENT_RECOVERY_PROBE' => '1', 'APP_ENV' => 'testing', 'DB_CONNECTION' => config('database.default'),
            'DB_DATABASE' => 'thai_lottery_test', 'DB_SOCKET' => DB::connection()->getConfig('unix_socket'),
            'DB_USERNAME' => DB::connection()->getConfig('username'), 'DB_PASSWORD' => DB::connection()->getConfig('password'),
            'DB_URL' => '', 'CACHE_STORE' => 'database', 'CACHE_PREFIX' => config('cache.prefix'),
            'QUEUE_CONNECTION' => 'database', 'APP_KEY' => config('app.key'), 'LOG_CHANNEL' => 'null',
        ]);
        $process->setTimeout(20);
        $process->start();

        return [$process, $file];
    }

    private function workerAt(int $seconds): array
    {
        $this->at($seconds);
        [$process] = $this->probe($seconds);
        try {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), 'Fresh Artisan worker refused its safe input: '.$process->getOutput().' '.$process->getErrorOutput());
            $this->assertSame('', $process->getErrorOutput());
            $out = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            $this->assertTrue($out['worked']);
            $this->assertSame(0, $out['worker_exit']);

            return $out;
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    private function killAt(string $phase, int $drawId, int $lag = 0): array
    {
        [$process, $file] = $this->probe(0, $phase, $lag);
        try {
            $deadline = microtime(true) + 10;
            while (! is_file($file.'.paused') && microtime(true) < $deadline && $process->isRunning()) {
                usleep(10000);
            }
            $this->assertFileExists($file.'.paused', 'Fault point must execute in the real worker: '.$process->getOutput().' '.$process->getErrorOutput());
            $marker = json_decode(file_get_contents($file.'.paused'), true, 16, JSON_THROW_ON_ERROR);
            $this->assertSame($phase, $marker['phase']);
            $this->assertSame('database', $marker['cache']);
            $this->assertSame($process->getPid(), $marker['pid']);
            $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $marker['connection']);
            $this->assertSame(1, DB::table('jobs')->whereNotNull('reserved_at')->count());
            $this->assertSame(1, DB::table('cache_locks')->where('key', 'like', '%settlement-draw:'.$drawId)->count());
            if ($phase === 'money') {
                $this->assertGreaterThan(0, $marker['transaction_level']);
                $trx = DB::selectOne('SELECT trx_rows_modified AS n FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = ?', [$marker['connection']]);
                $this->assertNotNull($trx);
                $this->assertGreaterThan(0, (int) $trx->n);
            } else {
                $this->assertSame(0, $marker['transaction_level']);
            }
            $process->signal(SIGKILL);
            $process->wait();
            $this->assertTrue($process->hasBeenSignaled());
            $this->assertSame(SIGKILL, $process->getTermSignal());
            $deadline = microtime(true) + 5;
            do {
                $transactions = (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = ?', [$marker['connection']])->n;
                if ($transactions > 0) {
                    usleep(200000);
                }
            } while ($transactions > 0 && microtime(true) < $deadline);
            $this->assertSame(0, $transactions, 'Killed connection must no longer own an InnoDB transaction.');
            $marker['signal'] = SIGKILL;

            return $marker;
        } finally {
            if ($process->isRunning()) {
                $process->signal(SIGKILL);
                $process->wait();
            }
        }
    }

    private function financialJobs(): \Illuminate\Database\Query\Builder
    {
        return DB::table('jobs')->where('queue', QueueName::FinancialCritical->value);
    }

    private function drain(int $start): void
    {
        // The fixture also schedules real notification jobs. They have a
        // different lane; this recovery probe drains and verifies the money
        // lane without deleting or treating those deliveries as settlement.
        for ($i = 0; $i < 20 && $this->financialJobs()->count() > 0; $i++) {
            $this->workerAt($start + $i * 10);
            $this->assertSame(0, DB::table('failed_jobs')->count(), 'Overlap releases must not consume the recovery budget before lock expiry.');
        }
        $this->assertSame(0, $this->financialJobs()->count());
    }

    private function finished(array $f, SettlementRun $run, int $finance, int $entries, bool $pending = false): void
    {
        $run = $run->fresh();
        $this->assertTrue($run->isComplete());
        $this->assertSame(3, $run->bets_settled);
        $this->assertSame(3, $run->chunks_processed);
        $this->assertSame('9000.00', $run->total_prize);
        $this->assertSame(DrawStatus::Completed, $f['draw']->fresh()->status);
        $this->assertSame(1, Payout::query()->count());
        $this->assertSame($pending ? PayoutStatus::Pending : PayoutStatus::Completed, Payout::query()->sole()->status);
        $this->assertSame($pending ? '470.00' : '9470.00', (string) $f['wallet']->fresh()->balance);
        $this->assertSame($finance + ($pending ? 0 : 1), FinancialTransaction::query()->count());
        $this->assertSame($entries + ($pending ? 0 : 2), LedgerEntry::query()->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    private function witness(array $marker): void
    {
        $path = getenv('SETTLEMENT_RECOVERY_WITNESS');
        if (is_string($path) && str_starts_with($path, sys_get_temp_dir().'/settlement-recovery-')) {
            file_put_contents($path, json_encode(['class' => self::class, 'name' => $this->name(),
                'phase' => $marker['phase'], 'signal' => $marker['signal'], 'pid' => $marker['pid'],
                'child_connection' => $marker['connection'], 'transaction_level' => $marker['transaction_level'],
                'cache' => 'database', 'recovered' => true], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function interrupted(string $phase, int $lag = 0, bool $pending = false): void
    {
        if ($pending) {
            $this->configureSafety('LIVE', '0.00');
        }
        $f = $this->publishedDrawWithBets(['111', '222'], 'recovery-'.$phase.'-'.$lag);
        $o = app(ChunkedSettlementOrchestrator::class);
        $run = $o->start((int) $f['draw']->id, 1);
        $finance = FinancialTransaction::query()->count();
        $entries = LedgerEntry::query()->count();
        if ($phase === 'finalized') {
            while ($o->hasMoreWork($run)) {
                $run = $o->runChunk($run);
            }
            Queue::connection('database')->push(new FinalizeDrawSettlementJob((int) $run->draw_id, (int) $run->id), '', QueueName::FinancialCritical->value);
        } else {
            Queue::connection('database')->push(new SettleDrawChunkJob((int) $run->draw_id, (int) $run->id, 0), '', QueueName::FinancialCritical->value);
        }
        $marker = $this->killAt($phase, (int) $f['draw']->id, $lag);
        $this->assertSame($phase === 'money' ? 0 : 1, Payout::query()->count());
        $this->assertSame($phase === 'money' ? 0 : ($phase === 'finalized' ? 3 : 1), $run->fresh()->bets_settled);
        if ($phase === 'money') {
            $this->assertSame($finance, FinancialTransaction::query()->count());
            $this->assertSame($entries, LedgerEntry::query()->count());
            $this->assertSame('470.00', (string) $f['wallet']->fresh()->balance);
        }
        if ($phase !== 'enqueued') {
            $this->workerAt(299); // Reservation is not visible early, even after worker death.
            $this->assertSame(1, $this->financialJobs()->count());
            $this->assertSame(1, $this->financialJobs()->whereNotNull('reserved_at')->count());
        } else {
            $this->assertSame(2, $this->financialJobs()->count(), 'Continuation and unacknowledged original both persist.');
        }
        $this->drain($lag === 0 ? 301 : 300);
        $this->finished($f, $run, $finance, $entries, $pending);
        $this->witness($marker);
    }

    public function test_recovery_kill_before_money_commit_rolls_back_and_retries(): void
    {
        $this->interrupted('money');
    }

    public function test_recovery_kill_after_checkpoint_commit_repairs_missing_handoff(): void
    {
        $this->interrupted('committed');
    }

    public function test_recovery_kill_after_enqueue_replays_without_duplicate_money(): void
    {
        $this->interrupted('enqueued');
    }

    public function test_recovery_kill_after_finalization_before_ack_is_idempotent(): void
    {
        $this->interrupted('finalized');
    }

    public function test_recovery_manual_approval_survives_commit_and_restart(): void
    {
        $this->interrupted('committed', pending: true);
    }

    public function test_recovery_stale_overlap_does_not_exhaust_delivery_attempts(): void
    {
        $this->interrupted('committed', 25);
    }

    public function test_recovery_entry_run_commit_repairs_handoff_despite_stale_lock(): void
    {
        $f = $this->publishedDrawWithBets(['111', '222'], 'recovery-entry');
        $finance = FinancialTransaction::query()->count();
        $entries = LedgerEntry::query()->count();
        Queue::connection('database')->push(new ProcessPrizeSettlementJob((int) $f['draw']->id), '', QueueName::FinancialCritical->value);
        $marker = $this->killAt('started', (int) $f['draw']->id, 25);
        $run = app(ChunkedSettlementOrchestrator::class)->runFor((int) $f['draw']->id);
        $this->assertNotNull($run);
        // Entry's default chunk is larger; retain the persisted chunk policy.
        $this->assertSame(0, $run->bets_settled);
        $this->assertSame(0, Payout::query()->count());
        $this->drain(300);
        $this->assertTrue($run->fresh()->isComplete());
        $this->assertSame(3, $run->fresh()->bets_settled);
        $this->assertSame(1, $run->fresh()->chunks_processed);
        $this->assertSame('9470.00', (string) $f['wallet']->fresh()->balance);
        $this->assertSame($finance + 1, FinancialTransaction::query()->count());
        $this->assertSame($entries + 2, LedgerEntry::query()->count());
        $this->assertSame(1, Payout::query()->count());
        $this->witness($marker);
    }

    public function test_recovery_deadline_is_persisted_and_finite(): void
    {
        $f = $this->publishedDrawWithBets(['111', '222'], 'recovery-deadline');
        $run = app(ChunkedSettlementOrchestrator::class)->start((int) $f['draw']->id, 1);
        Queue::connection('database')->push(new SettleDrawChunkJob((int) $run->draw_id, (int) $run->id, 0), '', QueueName::FinancialCritical->value);
        $payload = json_decode($this->financialJobs()->sole()->payload, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame($this->epoch + 1800, $payload['retryUntil']);
        $this->workerAt(1801);
        $this->assertSame(0, $this->financialJobs()->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame(0, $run->fresh()->bets_settled);
        $this->assertSame('470.00', (string) $f['wallet']->fresh()->balance);
    }

    public function test_recovery_real_exceptions_still_exhaust_a_finite_budget(): void
    {
        $f = $this->publishedDrawWithBets(['111', '222'], 'recovery-exceptions');
        $run = app(ChunkedSettlementOrchestrator::class)->start((int) $f['draw']->id, 1);
        Queue::connection('database')->push(new SettleDrawChunkJob((int) $run->draw_id, (int) $run->id + 99999, 0), '', QueueName::FinancialCritical->value);
        foreach ([0, 60, 120] as $index => $time) {
            $this->workerAt($time);
            $this->assertSame($index === 2 ? 1 : 0, DB::table('failed_jobs')->count());
        }
        $this->assertSame(0, $this->financialJobs()->count());
        $alertQueued = DB::table('jobs')->where('queue', QueueName::Notifications->value)->get(['payload'])
            ->contains(fn ($job) => data_get(json_decode($job->payload, true), 'displayName') === SendFinancialAlertJob::class
                && str_contains($job->payload, 'Queue job failed'));
        $this->assertTrue($alertQueued, 'A permanently failed money job must enqueue a real operational alert.');
        $this->assertSame(0, Payout::query()->count());
        $this->assertSame(0, $run->fresh()->bets_settled);
    }
}
