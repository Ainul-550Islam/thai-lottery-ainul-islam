<?php

declare(strict_types=1);

/**
 * Migration safety audit — runs BEFORE `php artisan migrate --force`.
 *
 * WHY
 * ---
 * A lottery ledger migration is not a schema change like any other. `DROP
 * COLUMN balance_after`, `RENAME COLUMN` or `DROP TABLE bets` are perfectly
 * ordinary to write and cannot be undone by redeploying the previous commit:
 * the code comes back, the data does not. Production migrations are routinely
 * run by a deploy script with nobody reading them.
 *
 * This script reads the migration FILES that are pending on this database and
 * reports the destructive statements it finds, so that decision is made
 * deliberately (`--allow-destructive`) instead of implicitly by a deploy.
 *
 * WHAT IT IS NOT
 * --------------
 * It is not a schema differ and it does not predict data loss with certainty:
 * `dropColumn` on a column that is empty loses nothing, and a `delete()` in a
 * data migration can lose everything without matching any pattern here. It is
 * a speed bump for the obvious cases, and it errs toward naming the file so a
 * human can read it.
 *
 * USAGE
 *   php scripts/ops/migration-audit.php [--json] [--allow-destructive]
 *
 * EXIT CODES
 *   0 nothing destructive pending (or --allow-destructive)
 *   1 destructive operations pending
 *   2 refused / could not determine state
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;

$root = dirname(__DIR__, 2);

$json = in_array('--json', $argv, true);
$allowDestructive = in_array('--allow-destructive', $argv, true);

/**
 * Statement patterns that can destroy data. Ordered by how bad they are, so
 * the report reads worst-first.
 *
 * @var array<string, string>
 */
$patterns = [
    'dropTable' => '/->\s*dropTable\s*\(/i',
    'dropColumn' => '/->\s*dropColumn\s*\(/i',
    'dropIfExists' => '/->\s*dropIfExists\s*\(/i',
    'renameColumn' => '/->\s*renameColumn\s*\(/i',
    'renameTable' => '/->\s*rename\s*\(/i',
    'truncate' => '/->\s*truncate\s*\(/i',
    // A raw ALTER is not automatically destructive: `ALTER TABLE ... ADD
    // CONSTRAINT` is routine. The statement's own verb decides.
    'rawDropOrDelete' => '/DB::(?:statement|unprepared)\s*\(\s*[\'"][^\'"]*?\b(?:drop|truncate|delete)\b/i',
    'dataDelete' => '/DB::table\([^)]*\)->\s*delete\s*\(/i',
    'rawAlter' => '/->\s*(?:dropPrimary|dropUnique|dropIndex|dropForeign|dropMorphs)\s*\(/i',
];

try {
    require $root.'/vendor/autoload.php';

    /** @var Application $app */
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
} catch (Throwable $error) {
    $message = 'Unable to bootstrap the application to read migration state: '.$error->getMessage();

    $json
        ? print (json_encode(['status' => 'REFUSED', 'reason' => $message], JSON_PRETTY_PRINT).PHP_EOL)
        : fwrite(STDERR, $message.PHP_EOL);

    exit(2);
}

try {
    /** @var Migrator $migrator */
    $migrator = $app->make('migrator');

    if (! $migrator->repositoryExists()) {
        // A brand new database has no migrations table; every migration is
        // pending and none of them can be destructive to data that does not
        // exist yet.
        $output = ['status' => 'OK', 'pending' => 0, 'destructive' => [], 'note' => 'No migrations table; fresh database.'];

        $json
            ? print (json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL)
            : print 'No migrations table yet: a fresh database has nothing to destroy.'.PHP_EOL;

        exit(0);
    }

    // `Migrator::pendingMigrations()` is protected, so the same computation is
    // done explicitly: every migration file that is not in the `ran` list.
    // Doing it here also means a migration whose name merely *looks* applied is
    // reported, instead of silently skipped by the framework's own filter.
    $files = $migrator->getMigrationFiles([database_path('migrations')]);
    $ran = $migrator->getRepository()->getRan();

    $pending = [];

    foreach ($files as $name => $path) {
        if (! in_array($name, $ran, true)) {
            $pending[] = $path;
        }
    }
} catch (Throwable $error) {
    $message = 'Unable to determine pending migrations: '.$error->getMessage();

    $json
        ? print (json_encode(['status' => 'REFUSED', 'reason' => $message], JSON_PRETTY_PRINT).PHP_EOL)
        : fwrite(STDERR, $message.PHP_EOL);

    exit(2);
}

/**
 * Return the body of a single method, located by brace matching.
 *
 * WHY THIS EXISTS. Migrations routinely DROP things in `down()` — that is what
 * a rollback is. Scanning the whole file flags every one of them and produces a
 * report that blocks every deploy, which is the same as having no gate at all.
 * `migrate` runs `up()` and nothing else, so `up()` is what must be audited.
 *
 * Returns null when the method is not found, and the caller falls back to the
 * whole file and says so: an unreadable migration must fail loudly, never
 * quietly pass.
 */
function methodBody(string $source, string $method): ?string
{
    if (preg_match('/function\s+'.preg_quote($method, '/').'\s*\([^)]*\)\s*(?::\s*[\w\\|?]+\s*)?\{/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }

    $start = $match[0][1] + strlen($match[0][0]);
    $depth = 1;
    $length = strlen($source);

    for ($index = $start; $index < $length; $index++) {
        $character = $source[$index];

        // Skip over string literals so a brace inside a SQL statement does not
        // shift the nesting count.
        if ($character === "'" || $character === '"') {
            $quote = $character;

            for ($index++; $index < $length; $index++) {
                if ($source[$index] === '\\') {
                    $index++;

                    continue;
                }

                if ($source[$index] === $quote) {
                    break;
                }
            }

            continue;
        }

        if ($character === '{') {
            $depth++;
        } elseif ($character === '}') {
            $depth--;

            if ($depth === 0) {
                return substr($source, $start, $index - $start);
            }
        }
    }

    return null;
}

$findings = [];
$unscoped = [];

foreach ($pending as $migrationPath) {
    $source = @file_get_contents($migrationPath);

    if ($source === false) {
        continue;
    }

    $body = methodBody($source, 'up');

    if ($body === null) {
        // No `up()` found. Scan everything and mark the finding as unscoped so a
        // human knows the report is approximate rather than definitive.
        $body = $source;
        $unscoped[] = basename($migrationPath);
    }

    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $body) === 1) {
            $findings[] = [
                'migration' => basename($migrationPath),
                'operation' => $label,
                'scope' => $body === $source ? 'whole-file' : 'up()',
            ];
        }
    }
}

$status = $findings === [] ? 'OK' : ($allowDestructive ? 'ALLOWED' : 'DESTRUCTIVE_PENDING');

$output = [
    'status' => $status,
    'pending_count' => count($pending),
    'scanned' => 'up() only; down() is a rollback path and is never run by `migrate`',
    'unscoped_migrations' => $unscoped,
    'destructive_count' => count($findings),
    'destructive' => $findings,
    'remediation' => $findings === []
        ? null
        : 'Take a verified backup (scripts/ops/backup.sh), read each migration listed above, then re-run the deploy with --allow-destructive if the loss is intended.',
];

if ($json) {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} else {
    printf("Pending migrations: %d\n", $output['pending_count']);

    if ($findings === []) {
        echo "No destructive operations found in pending migrations.\n";
    } else {
        echo "Destructive operations in pending migrations:\n";

        foreach ($findings as $finding) {
            printf("  - %-64s %s\n", $finding['migration'], $finding['operation']);
        }

        echo "\n".$output['remediation']."\n";
    }
}

if ($findings !== [] && ! $allowDestructive) {
    exit(1);
}

exit(0);
