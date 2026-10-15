<?php

declare(strict_types=1);

use App\Exceptions\SettlementPreviewException;
use App\Services\Draw\ChunkedSettlementPreviewService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/** Standalone process probe. Test database/config travel on stdin, never argv/logs. */
ini_set('display_errors', '0');

try {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $connection = $input['connection'] ?? [];
    $driver = $connection['driver'] ?? '';
    $database = $connection['database'] ?? '';
    $operation = $input['operation'] ?? '';
    $ready = $input['ready'] ?? '';
    $release = $input['release'] ?? '';
    $prefix = sys_get_temp_dir().'/settlement-preview-';

    if (getenv('APP_ENV') !== 'testing' || ! is_array($connection)
        || ! in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)
        || ! is_string($database) || basename($database) !== 'thai_lottery_test'
        || ! in_array($operation, ['start', 'chunk'], true)
        || ! is_string($ready) || ! is_string($release)
        || ! str_starts_with($ready, $prefix) || ! str_starts_with($release, $prefix)) {
        throw new RuntimeException('Invalid disposable preview probe.');
    }

    $project = dirname(__DIR__, 2);

    if (is_file($project.'/bootstrap/cache/config.php')) {
        throw new RuntimeException('The probe must not bootstrap a cached deployment configuration.');
    }

    require $project.'/vendor/autoload.php';
    $app = require $project.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $connection['url'] = null;
    DB::purge();
    config(['database.connections.preview_probe' => $connection, 'database.default' => 'preview_probe']);
    $configuration = $input['configuration'] ?? [];

    foreach (ChunkedSettlementPreviewService::RULE_CONFIG_KEYS as $key) {
        if (array_key_exists($key, $configuration)) {
            config([$key => $configuration[$key]]);
        }
    }

    if (file_put_contents($ready, 'ready') === false) {
        throw new RuntimeException('Cannot signal worker readiness.');
    }

    $deadline = microtime(true) + 15;

    while (! is_file($release)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Preview probe barrier expired.');
        }
        usleep(1000);
    }

    $service = app(ChunkedSettlementPreviewService::class);
    $preview = $operation === 'start'
        ? $service->start((int) $input['draw_id'], (int) $input['chunk_size'])
        : $service->processChunk((int) $input['preview_id'], (int) $input['expected_cursor']);
    echo json_encode([
        'ok' => true,
        'pid' => getmypid(),
        'preview_id' => $preview->id,
        'cursor' => $preview->cursor_bet_id,
        'bets_processed' => $preview->bets_processed,
        'chunks_processed' => $preview->chunks_processed,
        'mode' => $preview->mode,
    ], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $failure) {
    echo json_encode([
        'ok' => false,
        'code' => $failure instanceof SettlementPreviewException ? $failure->errorCode() : 'PREVIEW_WORKER_FAILED',
        'exception_type' => $failure::class,
    ], JSON_THROW_ON_ERROR);
    exit(1);
}
