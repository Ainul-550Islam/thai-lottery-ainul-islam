<?php

declare(strict_types=1);

namespace App\Console\Commands\Infrastructure;

use App\Services\Infrastructure\DatabaseDumpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Imports a plaintext SQL dump into a named database.
 *
 * This command is the mechanism; `scripts/ops/restore.sh` is the policy. The
 * split is deliberate: the shell script owns the confirmations an operator has
 * to type, and this command owns the parts that must not be expressible in
 * shell (identifier validation, DROP/CREATE sequencing, refusing the live
 * connection).
 *
 * The `--recreate` path is the one to be careful with: it DROPs the named
 * database before creating it. It therefore refuses outright when the name
 * matches the connection the application is configured to use, regardless of
 * any other flag — dropping the database you are currently connected to in
 * order to restore into it is never the right move, even during an incident.
 */
class RestoreDatabaseCommand extends Command
{
    protected $signature = 'db:restore-file
        {file : Plaintext SQL dump}
        {--database= : Target database (required)}
        {--connection= : Laravel connection supplying host, port and credentials}
        {--recreate : DROP and CREATE the target before importing}
        {--allow-live-target : Permit the application\'s own database as the target}
        {--json : Machine-readable result}';

    protected $description = 'Import a plaintext SQL dump into an explicit, validated target database.';

    public function handle(): int
    {
        $json = (bool) $this->option('json');
        $file = (string) $this->argument('file');
        $database = (string) ($this->option('database') ?: '');
        $connectionName = $this->option('connection');
        $connectionName = is_string($connectionName) && $connectionName !== '' ? $connectionName : null;

        $fail = function (string $message) use ($json): int {
            $json
                ? $this->line((string) json_encode(['status' => 'FAILED', 'reason' => $message]))
                : $this->components->error($message);

            return self::FAILURE;
        };

        if ($database === '') {
            return $fail('--database is required; this command never guesses a restore target.');
        }

        // A database name reaches a DROP/CREATE statement; it is validated
        // before it is interpolated anywhere.
        if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
            return $fail('The database name must match [A-Za-z0-9_]+.');
        }

        if (! is_file($file) || filesize($file) === 0) {
            return $fail('The dump file is missing or empty: '.basename($file));
        }

        $resolvedConnection = $connectionName ?? (string) config('database.default');
        $configuredDatabase = (string) config('database.connections.'.$resolvedConnection.'.database');

        if ($database === $configuredDatabase && ! $this->option('allow-live-target')) {
            return $fail(sprintf(
                'Refusing to restore into "%s": that is the database this application is configured to use.',
                $database,
            ));
        }

        if ($this->option('recreate') && $database === $configuredDatabase) {
            return $fail('Refusing to DROP the application\'s own database. Restore into a scratch database and swap.');
        }

        try {
            if ($this->option('recreate')) {
                $this->components->task('Dropping and creating '.$database, function () use ($database): void {
                    // Statement text cannot be parameterised for DDL; the name
                    // has already been validated as [A-Za-z0-9_]+ and is quoted.
                    $driver = (string) DB::connection()->getDriverName();

                    if ($driver === 'pgsql') {
                        DB::statement(sprintf('DROP DATABASE IF EXISTS "%s" WITH (FORCE)', $database));
                        DB::statement(sprintf('CREATE DATABASE "%s"', $database));
                    } else {
                        DB::statement(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
                        DB::statement(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $database));
                    }
                });
            }

            $result = $this->components->task(
                'Importing '.basename($file).' into '.$database,
                fn (): array => DatabaseDumpService::fromConfig()->restore($file, $database, $resolvedConnection, true)
            );

            $json
                ? $this->line((string) json_encode([
                    'status' => 'RESTORED',
                    'database' => $database,
                    'driver' => $result['driver'] ?? 'unknown',
                    'bytes' => $result['bytes'] ?? 0,
                ]))
                : $this->components->info('Restored into '.$database.'.');
        } catch (RuntimeException $error) {
            return $fail($error->getMessage());
        } catch (Throwable $error) {
            return $fail('Unexpected restore failure: '.$error->getMessage());
        }

        return self::SUCCESS;
    }
}
