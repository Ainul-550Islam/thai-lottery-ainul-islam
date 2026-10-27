<?php

declare(strict_types=1);

namespace Tests\Integration\Concurrency;

use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\LedgerAccountSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Part 56 — multi-process password-lifecycle harness against a real engine.
 *
 * WHAT IT DOES
 * Rebuilds the schema on the given MySQL/MariaDB or PostgreSQL database,
 * creates one account, then starts one changer process plus several login
 * processes AT THE SAME WALL-CLOCK INSTANT. The changer walks a known
 * password sequence through PasswordChangeService (which locks the row with
 * lockForUpdate()); the login workers hammer session logins with every
 * candidate password, which is what exercises the Part 51 guard's
 * revalidation and compare-and-swap rehash while a write is in flight.
 *
 * WHY PROCESS-LEVEL
 * Row locks, transaction isolation and deadlock detection happen between
 * independent server connections. A single PHP process cannot reproduce
 * them, which is why every attempt runs in its own process with its own
 * connection.
 *
 * WHAT IT REPORTS (assertions live in the test, not here)
 *  - which candidate the final stored hash validates
 *  - violations: a login that succeeded with a password already superseded
 *    before the login even started verifying
 *  - residual-window hits: a login that succeeded while the superseding
 *    change committed between its verification and its session issuance —
 *    the documented limit, counted rather than hidden
 *  - lock/deadlock errors surfaced by the engine
 *  - session rows left without a password binding
 */
final class RealEngineConcurrencyHarness
{
    /**
     * @param  array{connection: string, host: string, port: int, database: string, username: string, password: string}  $engine
     * @return array<string, mixed>
     */
    public static function run(array $engine, int $loginWorkers = 4, int $changerRounds = 12, int $loginRounds = 40): array
    {
        $passwords = [
            'Concurrency-Alpha-1!',
            'Concurrency-Bravo-2!',
            'Concurrency-Charlie-3!',
            'Concurrency-Delta-4!',
            'Concurrency-Echo-5!',
        ];

        config()->set('database.default', $engine['connection']);
        config()->set('database.connections.'.$engine['connection'].'.host', $engine['host']);
        config()->set('database.connections.'.$engine['connection'].'.port', $engine['port']);
        config()->set('database.connections.'.$engine['connection'].'.database', $engine['database']);
        config()->set('database.connections.'.$engine['connection'].'.username', $engine['username']);
        config()->set('database.connections.'.$engine['connection'].'.password', $engine['password']);
        config()->set('session.driver', 'database');
        config()->set('session.connection', null);

        DB::purge($engine['connection']);
        app('session')->forgetDrivers();

        Artisan::call('migrate:fresh', ['--force' => true, '--no-interaction' => true]);
        (new LedgerAccountSeeder)->run();

        $email = 'concurrency-'.uniqid().'@example.test';

        $user = User::factory()->create([
            'email' => $email,
            'status' => UserStatus::Active,
            'password' => Hash::make($passwords[0]),
        ]);

        $workdir = sys_get_temp_dir().'/part56-'.$engine['connection'].'-'.getmypid();
        @mkdir($workdir, 0777, true);

        $workerScript = dirname(__DIR__, 2).'/Integration/Concurrency/PasswordLifecycleWorker.php';
        $startAt = microtime(true) + 3.0;

        $jobs = [['role' => 'changer', 'rounds' => $changerRounds]];

        for ($i = 0; $i < $loginWorkers; $i++) {
            $jobs[] = ['role' => 'login', 'rounds' => $loginRounds];
        }

        $processes = [];

        foreach ($jobs as $index => $job) {
            $workerId = $job['role'].'-'.$index;
            $configFile = $workdir.'/'.$workerId.'.json';
            $outFile = $workdir.'/'.$workerId.'.out.json';

            file_put_contents($configFile, json_encode([
                'appKey' => (string) config('app.key'),
                'connection' => $engine['connection'],
                'host' => $engine['host'],
                'port' => $engine['port'],
                'database' => $engine['database'],
                'username' => $engine['username'],
                'password' => $engine['password'],
                'role' => $job['role'],
                'workerId' => $workerId,
                'userEmail' => $email,
                'passwords' => $passwords,
                'rounds' => $job['rounds'],
                'startAt' => $startAt,
                'outFile' => $outFile,
            ]));

            $command = sprintf(
                '%s %s %s',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($workerScript),
                escapeshellarg($configFile),
            );

            $pipes = [];
            $handle = proc_open(
                $command,
                [1 => ['file', $workdir.'/'.$workerId.'.stdout', 'w'], 2 => ['file', $workdir.'/'.$workerId.'.stderr', 'w']],
                $pipes,
                dirname(__DIR__, 3),
            );

            if (! is_resource($handle)) {
                throw new \RuntimeException("Could not start worker {$workerId}");
            }

            $processes[$workerId] = ['handle' => $handle, 'out' => $outFile, 'stderr' => $workdir.'/'.$workerId.'.stderr'];
        }

        $deadline = microtime(true) + 300.0;
        $pending = $processes;

        while ($pending !== [] && microtime(true) < $deadline) {
            foreach ($pending as $workerId => $process) {
                $status = proc_get_status($process['handle']);

                if ($status['running']) {
                    continue;
                }

                proc_close($process['handle']);
                unset($pending[$workerId]);
            }

            if ($pending !== []) {
                usleep(20000);
            }
        }

        foreach ($pending as $workerId => $process) {
            proc_terminate($process['handle']);
            proc_close($process['handle']);
        }

        $results = [];

        foreach ($processes as $workerId => $process) {
            $results[$workerId] = is_file($process['out'])
                ? json_decode((string) file_get_contents($process['out']), true)
                : ['missing' => true, 'stderr' => is_file($process['stderr']) ? (string) file_get_contents($process['stderr']) : ''];
        }

        return self::analyse($engine, $passwords, $results, $pending !== [] ? array_keys($pending) : []);
    }

    /**
     * @param  array<string, string>  $passwords
     * @param  array<string, mixed>  $results
     * @param  list<string>  $timedOut
     * @return array<string, mixed>
     */
    private static function analyse(array $engine, array $passwords, array $results, array $timedOut): array
    {
        $changes = [];
        $logins = [];
        $lockErrors = [];
        $errors = [];
        $missing = [];
        $finalHash = '';

        foreach ($results as $workerId => $result) {
            if (! is_array($result) || ($result['missing'] ?? false)) {
                $missing[] = ['worker' => $workerId, 'stderr' => $result['stderr'] ?? ''];

                continue;
            }

            foreach ((array) ($result['changes'] ?? []) as $change) {
                $changes[] = $change;
            }

            foreach ((array) ($result['logins'] ?? []) as $login) {
                $logins[] = $login;
            }

            foreach ((array) ($result['lockErrors'] ?? []) as $lockError) {
                $lockErrors[] = ['worker' => $workerId] + $lockError;
            }

            foreach ((array) ($result['errors'] ?? []) as $error) {
                $errors[] = ['worker' => $workerId] + $error;
            }

            if (($result['finalHash'] ?? '') !== '') {
                $finalHash = (string) $result['finalHash'];
            }
        }

        usort($changes, static fn (array $a, array $b): int => ($a['committedAt'] ?? 0) <=> ($b['committedAt'] ?? 0));

        $committedChanges = array_values(array_filter($changes, static fn (array $c): bool => (bool) ($c['succeeded'] ?? false)));
        $lastCommitted = $committedChanges === [] ? null : $committedChanges[count($committedChanges) - 1];

        /*
         * The password sequence is cyclic, so "superseded at T" is not a
         * one-way fact: a candidate can become current again later. The
         * honest model is therefore a timeline of segments — which password
         * was current from when until when — built from the committed
         * changes, and a login is judged against the overlap of its own
         * verification window with those segments.
         */
        $segments = [];
        $current = $passwords[0];
        $from = 0.0;

        foreach ($logins as $login) {
            $from = $from === 0.0 ? (float) $login['startedAt'] : min($from, (float) $login['startedAt']);
        }

        foreach ($committedChanges as $change) {
            $segments[] = ['password' => $current, 'from' => $from, 'to' => (float) $change['committedAt']];
            $current = (string) $change['to'];
            $from = (float) $change['committedAt'];
        }

        $segments[] = ['password' => $current, 'from' => $from, 'to' => PHP_FLOAT_MAX];

        /** Which password was current at an instant. */
        $currentAt = static function (float $at) use ($segments): ?string {
            foreach ($segments as $segment) {
                if ($at >= $segment['from'] && $at < $segment['to']) {
                    return $segment['password'];
                }
            }

            return null;
        };

        /** Was this candidate current at any point during the window? */
        $currentDuring = static function (string $candidate, float $start, float $end) use ($segments): bool {
            foreach ($segments as $segment) {
                if ($segment['password'] !== $candidate) {
                    continue;
                }

                if ($start < $segment['to'] && $end >= $segment['from']) {
                    return true;
                }
            }

            return false;
        };

        $violations = [];
        $residualWindow = [];
        $successes = 0;

        foreach ($logins as $login) {
            if (! ($login['success'] ?? false)) {
                continue;
            }

            $successes++;
            $candidate = (string) $login['password'];
            $start = (float) $login['startedAt'];
            $end = (float) ($login['endedAt'] ?? $start);

            $detail = $login + [
                'currentAtStart' => $currentAt($start),
                'currentAtEnd' => $currentAt($end),
            ];

            if (! $currentDuring($candidate, $start, $end)) {
                // Succeeded with a password that was never current while this
                // very login was verifying it: a real violation.
                $violations[] = $detail;

                continue;
            }

            if ($currentAt($end) !== $candidate) {
                // Current when verification began, superseded before the
                // session was issued: the documented residual window.
                $residualWindow[] = $detail;
            }
        }

        $hashMatches = [];

        foreach ($passwords as $candidate) {
            $hashMatches[$candidate] = $finalHash !== '' && Hash::check($candidate, $finalHash);
        }

        $expectedFinal = $lastCommitted === null ? $passwords[0] : (string) $lastCommitted['to'];

        /*
         * Session rows are stored base64-encoded by DatabaseSessionHandler.
         * The invariant that matters is Part 52's: a row that carries a
         * logged-in user must also carry the password binding. Anonymous rows
         * (failed logins) legitimately have neither.
         */
        $sessions = DB::connection($engine['connection'])->table('sessions')->get(['payload']);
        $sessionsTotal = count($sessions);
        $sessionsWithUser = 0;
        $userSessionsWithoutBinding = 0;

        foreach ($sessions as $session) {
            $payload = base64_decode((string) $session->payload, true);
            $payload = is_string($payload) ? $payload : (string) $session->payload;

            if (! str_contains($payload, 'login_web_')) {
                continue;
            }

            $sessionsWithUser++;

            if (! str_contains($payload, 'password_hash_web')) {
                $userSessionsWithoutBinding++;
            }
        }

        return [
            'engine' => $engine['connection'].' '.$engine['host'].':'.$engine['port'].'/'.$engine['database'],
            'workers' => count($results),
            'timedOut' => $timedOut,
            'missingResults' => $missing,
            'segments' => $segments,
            'loginAttempts' => count($logins),
            'loginSuccesses' => $successes,
            'changeAttempts' => count($changes),
            'changesCommitted' => count($committedChanges),
            'expectedFinalPassword' => $expectedFinal,
            'hashMatches' => $hashMatches,
            'violations' => $violations,
            'residualWindowHits' => $residualWindow,
            'lockErrors' => $lockErrors,
            'workerErrors' => $errors,
            'sessionsTotal' => $sessionsTotal,
            'sessionsWithUser' => $sessionsWithUser,
            'userSessionsWithoutBinding' => $userSessionsWithoutBinding,
        ];
    }
}
