<?php

declare(strict_types=1);

namespace Tests\Support;


/** Test-only admission policy for a genuinely shared non-monetary race. */
final class SimulationConcurrencyEnvironment
{
    /** Public pricing/result rules only; no keys, payout mode or credentials. */
    public const RULE_CONFIG_KEYS = [
        'lottery.markets',
        'lottery.types',
        'lottery.betting',
        'lottery.payouts.multiplier_source',
        'lottery.results',
        'finance.currency',
        'finance.precision',
    ];

    public static function refusalReason(array $connection, string $project): ?string
    {
        $driver = $connection['driver'] ?? null;
        $database = $connection['database'] ?? null;

        if (! is_string($database) || $database === '' || basename($database) !== 'thai_lottery_test') {
            return 'SIMULATION_RACE_DISPOSABLE_DATABASE_REQUIRED';
        }

        if (in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            return null;
        }

        if ($driver !== 'sqlite') {
            return 'SIMULATION_RACE_UNSUPPORTED_DRIVER';
        }

        if (str_contains(strtolower($database), ':memory:') || str_starts_with(strtolower($database), 'file:')) {
            return 'SIMULATION_RACE_SHARED_FILE_REQUIRED';
        }

        $path = str_starts_with($database, '/') ? $database : $project.'/'.$database;
        $resolved = realpath($path);
        $directory = realpath($project.'/database');

        if ($resolved === false || $directory === false || ! is_file($resolved) || is_link($path)
            || dirname($resolved) !== $directory) {
            return 'SIMULATION_RACE_SHARED_TEST_FILE_REQUIRED';
        }

        if (strtoupper((string) ($connection['transaction_mode'] ?? '')) !== 'IMMEDIATE') {
            return 'SIMULATION_RACE_SQLITE_IMMEDIATE_REQUIRED';
        }

        if (! is_int($connection['busy_timeout'] ?? null) || $connection['busy_timeout'] < 1) {
            return 'SIMULATION_RACE_SQLITE_WAIT_REQUIRED';
        }

        return null;
    }

    /** Resolve exactly the admitted database, never an inherited URL. */
    public static function normalizeConnection(array $connection, string $project): array
    {
        $reason = self::refusalReason($connection, $project);

        if ($reason !== null) {
            throw new \RuntimeException($reason);
        }

        $connection['url'] = null;
        // Connection::getConfig() includes its previous runtime name. Carrying
        // that into a new worker alias makes hydrated models bind a second PDO
        // and self-deadlock against the transaction that owns the first one.
        unset($connection['name']);

        if ($connection['driver'] === 'sqlite') {
            $path = $connection['database'];
            $connection['database'] = realpath(str_starts_with($path, '/') ? $path : $project.'/'.$path);
        }

        return $connection;
    }

    /** Barrier files must be siblings in the actual temporary directory. */
    public static function barrierIsAllowed(string $ready, string $release): bool
    {
        $temporary = realpath(sys_get_temp_dir());

        return $temporary !== false
            && dirname($release) === $temporary
            && dirname($ready) === $temporary
            && preg_match('/^simulation-race-[a-zA-Z0-9]+$/D', basename($release)) === 1
            && preg_match('/^'.preg_quote(basename($release), '/').'-ready-[01]$/D', basename($ready)) === 1
            && ! is_link($ready) && ! is_link($release);
    }
}
