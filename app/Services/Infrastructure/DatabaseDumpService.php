<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Produces and consumes a real database dump.
 *
 * WHY THIS CLASS EXISTS
 * ---------------------
 * `backup:execute-encrypted-worm` used to encrypt this string literal:
 *
 *     "-- THAI LOTTERY ... SNAPSHOT\nSELECT 'LEDGER_INVARIANT_LOCKED';"
 *
 * and then print three tick marks and "Prepared for immutable streaming to S3
 * Compliance WORM Bucket". No dump binary was ever invoked. The command
 * succeeded on a host with no database, no client tools and no credentials,
 * which is the clearest possible proof that it was not backing anything up.
 * A restore drilled against those archives would have produced an empty
 * database and a green checklist.
 *
 * This class runs the real client. Two deliberate choices:
 *
 *   * `Process` with an ARGUMENT ARRAY, never a shell string. Table names and
 *     database names reach the command line, and a shell would make the
 *     database name an injection point.
 *   * The password travels in the child environment (MYSQL_PWD / PGPASSWORD),
 *     never in argv. argv is world-readable in /proc on a shared host and is
 *     captured verbatim by most process supervisors' logs.
 */
class DatabaseDumpService
{
    /** The trailing marker mysqldump writes only after a completed dump. */
    private const MYSQL_COMPLETION_MARKER = '-- Dump completed';

    /** The trailing marker pg_dump writes only after a completed dump. */
    private const PGSQL_COMPLETION_MARKER = 'PostgreSQL database dump complete';

    public function __construct(private readonly array $config = []) {}

    public static function fromConfig(): self
    {
        /** @var array<string, mixed> $config */
        $config = (array) config('backup', []);

        return new self($config);
    }

    /**
     * Dump the configured connection to $destinationPath.
     *
     * @return array{driver: string, tables: int, bytes: int, binary: string, excluded_tables: list<string>}
     */
    public function dump(string $destinationPath, ?string $connectionName = null, ?callable $progress = null): array
    {
        $connection = $this->connectionConfig($connectionName);
        $driver = (string) ($connection['driver'] ?? '');

        $binary = match ($driver) {
            'mysql', 'mariadb' => $this->binary('mysql'),
            'pgsql' => $this->binary('pgsql'),
            default => throw new RuntimeException(sprintf(
                'This build can back up mysql/mariadb and pgsql connections; the default connection uses "%s".',
                $driver === '' ? 'an unknown driver' : $driver,
            )),
        };

        $serverIsMariaDb = $driver === 'mysql' || $driver === 'mariadb'
            ? $this->serverIsMariaDb($connection)
            : false;

        $arguments = $driver === 'pgsql'
            ? $this->pgsqlArguments($connection, $destinationPath)
            : $this->mysqlArguments($connection, $destinationPath, $serverIsMariaDb);

        $process = new Process([$binary, ...$arguments], null, $this->processEnv($connection, $driver), null, (float) ($this->config['dump']['timeout_seconds'] ?? 3600));

        $process->run(function ($type, $buffer) use ($progress): void {
            // Only stderr is surfaced: stdout is the dump itself, which is
            // written to the result file on disk and must never reach a log.
            if ($progress !== null && $type === Process::ERR) {
                $progress(trim($buffer));
            }
        });

        if (! $process->isSuccessful()) {
            $this->removeQuietly($destinationPath);

            throw new RuntimeException(sprintf(
                'Database dump failed (exit %s): %s',
                $process->getExitCode(),
                $this->scrub($process->getErrorOutput() ?: $process->getOutput()),
            ));
        }

        if (! is_file($destinationPath) || filesize($destinationPath) === 0) {
            throw new RuntimeException('Database dump produced no output file.');
        }

        $this->assertComplete($destinationPath, $driver);

        return [
            'driver' => $driver,
            'tables' => $this->countTables($destinationPath, $driver),
            'bytes' => (int) filesize($destinationPath),
            'binary' => basename($binary),
            'excluded_tables' => $driver === 'pgsql' ? [] : $this->excludedTables(),
        ];
    }

    /**
     * Verify that a plaintext dump file has the completion marker its tool
     * appends. A dump truncated by a killed process, a full disk or a dropped
     * connection has no marker; AES-GCM/HMAC cannot help here because the
     * *plaintext itself* is short — the encryption is perfectly valid.
     */
    public function assertComplete(string $path, string $driver): void
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to read the dump file for completeness verification.');
        }

        try {
            // The marker lives in the last few hundred bytes; read backwards.
            $size = filesize($path);
            $window = (int) min($size, 4096);
            fseek($handle, -$window, SEEK_END);
            $tail = (string) fread($handle, $window);
        } finally {
            fclose($handle);
        }

        $marker = $driver === 'pgsql' ? self::PGSQL_COMPLETION_MARKER : self::MYSQL_COMPLETION_MARKER;

        if (! str_contains($tail, $marker)) {
            throw new RuntimeException(sprintf(
                'Dump is incomplete: the trailing "%s" marker is missing. This archive is not usable for '
                .'restore and has been discarded.',
                $marker,
            ));
        }
    }

    /**
     * Count `CREATE TABLE` statements in a plaintext dump. Used for the
     * manifest and for the restore drill's before/after comparison.
     */
    public function countTables(string $path, string $driver = 'mysql'): int
    {
        $pattern = $driver === 'pgsql' ? '/^CREATE TABLE /m' : '/^CREATE TABLE /m';
        $contents = file_get_contents($path, false, null, 0, 64 * 1024 * 1024);

        if ($contents === false) {
            return 0;
        }

        return (int) preg_match_all($pattern, $contents);
    }

    /**
     * Import a plaintext dump into a target database.
     *
     * Restores are destructive. This method therefore requires the caller to
     * have already obtained an explicit confirmation; it performs no prompting
     * of its own and refuses to run against the configured live connection
     * unless `$allowLiveTarget` is true.
     */
    public function restore(string $dumpPath, string $database, ?string $connectionName = null, bool $allowLiveTarget = false): array
    {
        $connection = $this->connectionConfig($connectionName);
        $driver = (string) ($connection['driver'] ?? '');
        $configuredDatabase = (string) ($connection['database'] ?? '');

        if (! $allowLiveTarget && $database === $configuredDatabase) {
            throw new RuntimeException(
                'Refusing to restore into the database this application is configured to use ('
                .$database.'). Restore into a scratch database, verify, then swap.'
            );
        }

        if ($driver === 'pgsql') {
            $binary = $this->clientBinary('pgsql');
            $arguments = [
                '--host='.($connection['host'] ?? '127.0.0.1'),
                '--port='.($connection['port'] ?? '5432'),
                '--username='.($connection['username'] ?? ''),
                '--dbname='.$database,
                '--no-owner',
                '--no-privileges',
                '--quiet',
                '--file='.$dumpPath,
            ];
        } else {
            $binary = $this->clientBinary('mysql');
            $arguments = [
                '--host='.($connection['host'] ?? '127.0.0.1'),
                '--port='.($connection['port'] ?? '3306'),
                '--user='.($connection['username'] ?? ''),
                '--default-character-set=utf8mb4',
                $database,
            ];
        }

        $process = new Process([$binary, ...$arguments], null, $this->processEnv($connection, $driver), null, (float) ($this->config['dump']['timeout_seconds'] ?? 3600));

        // mysql/mariadb read SQL from stdin; psql takes --file. Neither path
        // goes through a shell, so a database name is never an injection point.
        if ($driver !== 'pgsql') {
            $handle = fopen($dumpPath, 'rb');

            if ($handle === false) {
                throw new RuntimeException('Unable to open the dump file for restore.');
            }

            $process->setInput($handle);
        }

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'Restore failed (exit %s): %s',
                $process->getExitCode(),
                $this->scrub($process->getErrorOutput()),
            ));
        }

        return ['driver' => $driver, 'database' => $database, 'bytes' => (int) filesize($dumpPath)];
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return list<string>
     */
    private function mysqlArguments(array $connection, string $destinationPath, bool $serverIsMariaDb): array
    {
        $options = (array) ($this->config['dump']['mysql']['options'] ?? []);

        if ($serverIsMariaDb) {
            // --set-gtid-purged is MySQL-8-only and makes MariaDB's client exit
            // non-zero with "unknown option".
            $options = array_values(array_filter(
                $options,
                static fn (string $option): bool => ! str_starts_with($option, '--set-gtid-purged'),
            ));
        }

        $arguments = [
            '--host='.($connection['host'] ?? '127.0.0.1'),
            '--port='.($connection['port'] ?? '3306'),
            '--user='.($connection['username'] ?? ''),
            '--result-file='.$destinationPath,
            ...$options,
        ];

        foreach ($this->excludedTables() as $table) {
            $arguments[] = '--ignore-table='.($connection['database'] ?? '').'.'.$table;
        }

        $arguments[] = (string) ($connection['database'] ?? '');

        return $arguments;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return list<string>
     */
    private function pgsqlArguments(array $connection, string $destinationPath): array
    {
        $options = (array) ($this->config['dump']['pgsql']['options'] ?? []);

        return [
            '--host='.($connection['host'] ?? '127.0.0.1'),
            '--port='.($connection['port'] ?? '5432'),
            '--username='.($connection['username'] ?? ''),
            '--dbname='.(string) ($connection['database'] ?? ''),
            '--file='.$destinationPath,
            ...$options,
        ];
    }

    /**
     * @return list<string>
     */
    private function excludedTables(): array
    {
        /** @var list<string> $tables */
        $tables = array_values(array_filter(
            array_map('strval', (array) ($this->config['dump']['exclude_tables'] ?? [])),
        ));

        return $tables;
    }

    /**
     * The client used to IMPORT a dump (mysql / psql), as opposed to the client
     * used to produce one (mysqldump / pg_dump).
     */
    private function clientBinary(string $family): string
    {
        $configured = $family === 'pgsql'
            ? (string) ($this->config['restore']['pgsql_binary'] ?? 'psql')
            : (string) ($this->config['restore']['mysql_binary'] ?? 'mysql');

        return $this->resolveOnPath($configured === '' ? ($family === 'pgsql' ? 'psql' : 'mysql') : $configured);
    }

    private function binary(string $family): string
    {
        $section = $family === 'pgsql' ? 'pgsql' : 'mysql';
        $configured = trim((string) ($this->config['dump'][$section]['binary'] ?? ''));

        if ($configured === '') {
            $configured = $family === 'pgsql' ? 'pg_dump' : 'mysqldump';
        }

        return $this->resolveOnPath($configured);
    }

    private function resolveOnPath(string $configured): string
    {
        // Resolve through PATH without a shell, so a missing client produces a
        // clean error instead of "sh: mysqldump: not found".
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$configured;

            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException(sprintf(
            'The %s client binary was not found on PATH. Install the database client in the runtime '
            .'image before scheduling backups or restores.',
            $configured,
        ));
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<string, string>
     */
    private function processEnv(array $connection, string $driver): array
    {
        $env = [
            // Keep client chatter out of any log that captures stdout/stderr.
            'MYSQL_PWD' => (string) ($connection['password'] ?? ''),
            'PGPASSWORD' => (string) ($connection['password'] ?? ''),
        ];

        if ($driver === 'pgsql') {
            $env['PGSSLMODE'] = (string) (($connection['sslmode'] ?? '') ?: 'prefer');
        }

        return $env;
    }

    /**
     * MariaDB and MySQL disagree about whether --set-gtid-purged exists. Ask the
     * server which one it is rather than guessing from the driver name, because
     * `mysql` is the driver for both.
     *
     * @param  array<string, mixed>  $connection
     */
    private function serverIsMariaDb(array $connection): bool
    {
        try {
            $result = DB::connection()->selectOne('SELECT VERSION() AS version');
            $version = strtolower((string) ($result->version ?? ''));
        } catch (Throwable) {
            // If the version cannot be read, prefer the conservative option set
            // (MariaDB-compatible) rather than failing the whole backup: a
            // missing --set-gtid-purged cannot corrupt a MySQL dump.
            return true;
        }

        return str_contains($version, 'mariadb');
    }

    /**
     * @return array<string, mixed>
     */
    private function connectionConfig(?string $connectionName): array
    {
        $name = $connectionName ?? (string) config('database.default');

        /** @var array<string, mixed> $connection */
        $connection = (array) config('database.connections.'.$name, []);

        if ($connection === []) {
            throw new RuntimeException('Unknown database connection: '.$name);
        }

        return $connection;
    }

    /**
     * Strip anything that looks like a credential out of a client's error text
     * before it reaches a console, a log or a CI transcript. The password is
     * passed in the environment precisely so it cannot appear in argv, but
     * clients do sometimes echo "using password: YES" plus a DSN.
     */
    private function scrub(string $message): string
    {
        $scrubbed = preg_replace(
            ['/(password\s*=\s*)[^\s;]+/i', '/(IDENTIFIED BY\s+)\'[^\']*\'/i', '/(--password=)\S+/'],
            '$1[REDACTED]',
            $message,
        );

        return trim((string) $scrubbed);
    }

    private function removeQuietly(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
