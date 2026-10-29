<?php

declare(strict_types=1);

/*
 * Part 56 — one worker process of the real-engine concurrency harness.
 *
 * Usage: php tests/Integration/Concurrency/PasswordLifecycleWorker.php <config.json>
 *
 * This file is deliberately a script and not a class: it must never be
 * autoloaded, because including it would execute a workload. PHPUnit only
 * collects *Test.php files, and nothing in the application requires it.
 *
 * WHY A SEPARATE PROCESS
 * The point of the harness is process-level concurrency against a real
 * database engine. Two coroutines or two connections inside one PHP process
 * cannot reproduce InnoDB/Postgres row-lock waits, transaction isolation or
 * deadlock detection between independent server connections — which is
 * exactly what `PasswordChangeService`'s lockForUpdate() depends on.
 *
 * ROLES
 *  - changer: the single writer of the password. It walks a known password
 *    sequence, so the "last committed password" is knowable afterwards.
 *  - login:   repeatedly attempts session logins with candidate passwords,
 *    which exercises the Part 51 guard's revalidation and rehash path while
 *    the changer is writing.
 *
 * Every attempt is timestamped (microtime) so the harness can tell a genuine
 * violation from the documented residual window, where a password change
 * commits after a login has already verified the old password.
 */

use App\Models\User;
use App\Services\Auth\PasswordChangeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This worker must run from the command line.\n");
    exit(1);
}

if (($argv[1] ?? null) === null || ! is_file((string) $argv[1])) {
    fwrite(STDERR, "usage: php PasswordLifecycleWorker.php <config.json>\n");
    exit(1);
}

$config = json_decode((string) file_get_contents((string) $argv[1]), true);

if (! is_array($config)) {
    fwrite(STDERR, "config must be a JSON object\n");
    exit(1);
}

foreach ([
    'APP_ENV' => 'testing',
    'APP_DEBUG' => 'true',
    'APP_KEY' => (string) $config['appKey'],
    'DB_CONNECTION' => (string) $config['connection'],
    'DB_HOST' => (string) $config['host'],
    'DB_PORT' => (string) $config['port'],
    'DB_DATABASE' => (string) $config['database'],
    'DB_USERNAME' => (string) $config['username'],
    'DB_PASSWORD' => (string) $config['password'],
    'SESSION_DRIVER' => 'database',
    'CACHE_STORE' => 'array',
    'QUEUE_CONNECTION' => 'sync',
] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

chdir(dirname(__DIR__, 3));
require dirname(__DIR__, 3).'/vendor/autoload.php';

/** @var Application $app */
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config()->set('database.default', $config['connection']);
config()->set('session.driver', 'database');
config()->set('session.connection', null);
app('session')->forgetDrivers();
// The session.store singleton may already have been resolved during app
// bootstrap. The guard and this worker must share the same configured store.
$app->forgetInstance('session.store');
Auth::forgetGuards();
DB::purge($config['connection']);

$role = (string) $config['role'];
$workerId = (string) $config['workerId'];
$userEmail = (string) $config['userEmail'];
$passwords = array_values((array) $config['passwords']);
$rounds = max(1, (int) $config['rounds']);
$outFile = (string) $config['outFile'];

// Barrier: every worker waits for the same wall-clock start so the writers
// and readers actually overlap instead of running back to back.
$startAt = (float) $config['startAt'];
while (microtime(true) < $startAt) {
    usleep(2000);
}

$session = app('session.store');
$session->start();

$result = [
    'workerId' => $workerId,
    'role' => $role,
    'connection' => $config['connection'],
    'startedAt' => microtime(true),
    'changes' => [],
    'logins' => [],
    'errors' => [],
    'lockErrors' => [],
];

/**
 * @return array{code: string, message: string}
 */
$describeThrowable = static function (Throwable $exception): array {
    return [
        'code' => (string) ($exception->getCode() ?: $exception::class),
        'message' => $exception->getMessage(),
    ];
};

$isLockError = static function (Throwable $exception): bool {
    $message = $exception->getMessage();

    // 1213 deadlock / 1205 lock wait timeout (MySQL, MariaDB) and
    // 40001 serialization failure / 40P01 deadlock (PostgreSQL).
    return str_contains($message, '1213')
        || str_contains($message, '1205')
        || str_contains($message, 'Deadlock')
        || str_contains($message, 'deadlock')
        || str_contains($message, '40001')
        || str_contains($message, '40P01')
        || str_contains($message, 'Lock wait timeout');
};

try {
    if ($role === 'changer') {
        for ($i = 0; $i < $rounds; $i++) {
            $from = $passwords[$i % count($passwords)];
            $to = $passwords[($i + 1) % count($passwords)];

            if ($from === $to) {
                continue;
            }

            $user = User::query()->where('email', $userEmail)->first();

            if (! $user instanceof User) {
                $result['errors'][] = ['code' => 'NO_USER', 'message' => 'target user disappeared'];

                break;
            }

            $attempt = ['from' => $from, 'to' => $to, 'startedAt' => microtime(true)];

            try {
                $changed = app(PasswordChangeService::class)->change($user, $from, $to);
                $attempt['succeeded'] = $changed instanceof User;
            } catch (Throwable $exception) {
                $attempt['succeeded'] = false;
                $attempt['error'] = $describeThrowable($exception);

                if ($isLockError($exception)) {
                    $result['lockErrors'][] = $attempt['error'];
                }
            }

            $attempt['committedAt'] = microtime(true);
            $result['changes'][] = $attempt;
        }
    } else {
        for ($i = 0; $i < $rounds; $i++) {
            $candidate = $passwords[$i % count($passwords)];
            $attempt = ['password' => $candidate, 'startedAt' => microtime(true)];

            try {
                $attempt['success'] = Auth::guard('web')->attempt([
                    'email' => $userEmail,
                    'password' => $candidate,
                ]);

                $attempt['sessionBinding'] = $attempt['success']
                    ? $session->get('password_hash_web')
                    : null;
            } catch (Throwable $exception) {
                $attempt['success'] = false;
                $attempt['error'] = $describeThrowable($exception);

                if ($isLockError($exception)) {
                    $result['lockErrors'][] = $attempt['error'];
                }
            }

            $attempt['endedAt'] = microtime(true);
            $result['logins'][] = $attempt;

            /*
             * Rotate the session the way a new browser would, but KEEP the
             * finished row in the database: invalidate() would destroy it and
             * the harness's binding invariant would then have nothing to
             * inspect. migrate(false) moves to a fresh id and leaves the old
             * row behind; flush() gives the next attempt an empty session.
             */
            $session->save();
            $session->migrate(false);
            $session->flush();
            // Each iteration models a fresh browser; a cached guard user from
            // a previous successful attempt must not leak into the next one.
            Auth::forgetGuards();
        }
    }
} catch (Throwable $exception) {
    $result['errors'][] = $describeThrowable($exception);
}

$result['finishedAt'] = microtime(true);
$result['finalHash'] = (string) (User::query()->where('email', $userEmail)->value('password') ?? '');
$result['hashMatches'] = [];

foreach ($passwords as $candidate) {
    $result['hashMatches'][$candidate] = Hash::check($candidate, $result['finalHash']);
}

file_put_contents($outFile, json_encode($result, JSON_PRETTY_PRINT));

exit(0);
