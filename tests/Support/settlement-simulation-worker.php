<?php

declare(strict_types=1);

use App\Services\Draw\DrawSettlementSimulationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\SimulationConcurrencyEnvironment;

/** Test-only fixed simulation probe. Never a registered application command. */
ini_set('display_errors', '0');
$stage = 'admission';

try {
    $project = dirname(__DIR__, 2);
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);

    if (getenv('APP_ENV') !== 'testing' || ! is_array($input)
        || ! is_int($input['draw_id'] ?? null) || $input['draw_id'] < 1
        || ! is_array($input['connection'] ?? null)
        || ! is_string($input['ready'] ?? null) || ! is_string($input['release'] ?? null)
        || ! is_string($input['token'] ?? null)
        || preg_match('/^[a-f0-9]{32}$/D', $input['token']) !== 1
        || is_file($project.'/bootstrap/cache/config.php')) {
        throw new RuntimeException('SIMULATION_PROBE_INPUT_REFUSED');
    }

    require $project.'/vendor/autoload.php';

    if (! SimulationConcurrencyEnvironment::barrierIsAllowed($input['ready'], $input['release'])) {
        throw new RuntimeException('SIMULATION_PROBE_BARRIER_REFUSED');
    }

    $connection = SimulationConcurrencyEnvironment::normalizeConnection($input['connection'], $project);
    $stage = 'bootstrap';
    $app = require $project.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    DB::purge();
    config(['database.connections.simulation_race' => $connection, 'database.default' => 'simulation_race']);

    foreach (SimulationConcurrencyEnvironment::RULE_CONFIG_KEYS as $key) {
        if (array_key_exists($key, $input['configuration'] ?? [])) {
            config([$key => $input['configuration'][$key]]);
        }
    }

    $stage = 'connection';
    $actual = DB::connection();
    $actualConfiguration = SimulationConcurrencyEnvironment::normalizeConnection($actual->getConfig(), $project);

    if ($actualConfiguration['database'] !== $connection['database'] || DB::transactionLevel() !== 0) {
        throw new RuntimeException('SIMULATION_PROBE_CONNECTION_REFUSED');
    }

    $busyTimeout = null;

    if ($actual->getDriverName() === 'sqlite') {
        $busyTimeout = (int) $actual->selectOne('PRAGMA busy_timeout')->timeout;

        if ($busyTimeout < 1) {
            throw new RuntimeException('SIMULATION_PROBE_WAIT_REFUSED');
        }
    }

    if (file_put_contents($input['ready'], $input['token'], LOCK_EX) === false) {
        throw new RuntimeException('SIMULATION_PROBE_READY_FAILED');
    }
    chmod($input['ready'], 0600);
    $deadline = microtime(true) + 15;

    while (! is_file($input['release']) || file_get_contents($input['release']) !== $input['token']) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('SIMULATION_PROBE_BARRIER_EXPIRED');
        }
        usleep(1000);
    }

    $stage = 'simulation';
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/(?:insert(?:\s+or\s+ignore)?\s+into|update|delete\s+from)\s+["`]?([a-z_]+)/i', $query->sql, $match) === 1) {
            $table = strtolower($match[1]);
            $writes[$table] = ($writes[$table] ?? 0) + 1;
        }
    });
    $result = $app->make(DrawSettlementSimulationService::class)->settle($input['draw_id']);

    if (! $result->isSimulation()) {
        throw new RuntimeException('SIMULATION_PROBE_MODE_REFUSED');
    }

    ksort($writes);
    echo json_encode([
        'outcome' => $result->alreadySettled ? 'already_settled' : 'settled',
        'pid' => getmypid(),
        'driver' => $actual->getDriverName(),
        'transaction_mode' => $actual->getConfig('transaction_mode'),
        'busy_timeout' => $busyTimeout,
        'database_identity' => hash('sha256', $actual->getDriverName().'|'.$actualConfiguration['database']),
        'mode' => $result->mode,
        'selections_written' => $result->selectionsWritten,
        'bets_updated' => $result->betsUpdated,
        'winning_selections' => $result->winningSelections,
        'total_simulated_prize' => $result->totalSimulatedPrize,
        'fingerprint' => $result->idempotencyFingerprint(),
        'writes_by_table' => $writes,
    ], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $failure) {
    echo json_encode([
        'outcome' => 'refused',
        'code' => 'SIMULATION_PROBE_FAILED',
        'stage' => $stage,
        'pid' => getmypid(),
        'exception_type' => $failure::class,
        'sql_state' => $failure instanceof QueryException ? ($failure->errorInfo[0] ?? null) : null,
        'driver_error' => $failure instanceof PDOException ? ($failure->errorInfo[1] ?? null) : null,
        'sql_operation' => $failure instanceof QueryException ? strtolower((string) strtok($failure->getSql(), " \n")) : null,
        'connection_name' => $failure instanceof QueryException ? $failure->getConnectionName() : null,
    ], JSON_THROW_ON_ERROR);
    exit(1);
}
