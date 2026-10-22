<?php

declare(strict_types=1);

use App\Exceptions\ChunkedSettlementException;
use App\Models\SettlementRun;
use App\Services\Draw\ChunkedSettlementOrchestrator;
use App\Services\Draw\ManualSettlementSnapshot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\SimulationConcurrencyEnvironment;

/** Disposable-test worker only. No public command, provider or deployment hook. */
ini_set('display_errors', '0');

try {
    $root = dirname(__DIR__, 2);
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);

    if (getenv('APP_ENV') !== 'testing' || ! is_array($input)
        || ! is_array($input['connection'] ?? null)
        || ! in_array($input['operation'] ?? null, ['start', 'chunk'], true)
        || ! is_string($input['ready'] ?? null) || ! is_string($input['release'] ?? null)
        || ! is_string($input['token'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $input['token']) !== 1
        || is_file($root.'/bootstrap/cache/config.php')) {
        throw new RuntimeException('MANUAL_CHECKPOINT_PROBE_REFUSED');
    }

    $temporary = realpath(sys_get_temp_dir());
    $release = $input['release'];
    $ready = $input['ready'];

    if ($temporary === false || dirname($release) !== $temporary || dirname($ready) !== $temporary
        || preg_match('/^manual-checkpoint-[a-zA-Z0-9]+$/D', basename($release)) !== 1
        || preg_match('/^'.preg_quote(basename($release), '/').'-ready-[01]$/D', basename($ready)) !== 1
        || is_link($ready) || is_link($release)) {
        throw new RuntimeException('MANUAL_CHECKPOINT_BARRIER_REFUSED');
    }

    require $root.'/vendor/autoload.php';
    // This policy only admits/normalizes a disposable connection. It grants
    // no application authority and strips URL/inherited runtime name.
    $connection = SimulationConcurrencyEnvironment::normalizeConnection($input['connection'], $root);
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    DB::purge();
    config(['database.connections.manual_checkpoint_probe' => $connection, 'database.default' => 'manual_checkpoint_probe']);

    foreach (array_merge(ManualSettlementSnapshot::RULE_KEYS, ['finance.prize_payout.safety_mode']) as $key) {
        if (array_key_exists($key, $input['configuration'] ?? [])) {
            config([$key => $input['configuration'][$key]]);
        }
    }

    if (file_put_contents($ready, $input['token'], LOCK_EX) === false) {
        throw new RuntimeException('MANUAL_CHECKPOINT_READY_FAILED');
    }
    chmod($ready, 0600);
    $deadline = microtime(true) + 15;

    while (! is_file($release) || file_get_contents($release) !== $input['token']) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('MANUAL_CHECKPOINT_BARRIER_EXPIRED');
        }
        usleep(1000);
    }

    $service = app(ChunkedSettlementOrchestrator::class);

    if ($input['operation'] === 'start') {
        $run = $service->start((int) $input['draw_id'], (int) $input['chunk_size']);
    } else {
        $run = SettlementRun::query()->findOrFail((int) $input['run_id']);
        $run->cursor_bet_id = (int) $input['expected_cursor'];
        $run = $service->runChunk($run);
    }

    echo json_encode([
        'ok' => true, 'pid' => getmypid(), 'run_id' => $run->id,
        'cursor' => $run->cursor_bet_id, 'bets_settled' => $run->bets_settled,
        'chunks_processed' => $run->chunks_processed, 'payouts_created' => $run->payouts_created,
        'total_prize' => (string) $run->total_prize, 'recorded_mode' => $run->safety_mode,
    ], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $failure) {
    echo json_encode([
        'ok' => false, 'pid' => getmypid(),
        'code' => $failure instanceof ChunkedSettlementException ? $failure->errorCode() : 'MANUAL_CHECKPOINT_PROBE_FAILED',
        'exception_type' => $failure::class,
    ], JSON_THROW_ON_ERROR);
    exit(1);
}
