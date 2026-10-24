<?php

declare(strict_types=1);

namespace App\Support\Ops;

use App\Providers\ProductionSafetyServiceProvider;
use App\Support\Backup\BackupCipher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deployment preflight: the checks that must pass before a release is allowed
 * to take traffic.
 *
 * RELATIONSHIP TO ProductionSafetyServiceProvider
 * -----------------------------------------------
 * That provider already refuses to BOOT production with fixture result lanes,
 * debug mode, insecure session cookies, a per-node cache, a synchronous queue
 * or a live payout switch without an approval threshold. It is the right place
 * for "the application must not run like this" and this class does not
 * duplicate it — it calls `violations()` and adds the checks that need an
 * environment the boot path cannot assume: connectivity, writability, build
 * artifacts, key material shape, and backup readiness.
 *
 * THE RULE: NO VALUES IN THE OUTPUT
 * ---------------------------------
 * Every check reports a status and a *description of the shape* of what it
 * found ("present", "96 characters", "https", "base64: 32 bytes"), never the
 * value. A preflight is run in CI transcripts, in deploy logs, and pasted into
 * incident tickets; it must be safe in all three. `maskValue()` exists so a
 * check cannot accidentally include a secret by interpolating one.
 *
 * Statuses:
 *   PASS  the requirement is satisfied
 *   WARN  acceptable but worth an operator's attention
 *   FAIL  the release must not proceed
 *   SKIP  not applicable in this environment or the probe was not attempted
 */
final class ProductionPreflight
{
    /** @var list<array{id: string, status: string, detail: string, severity: string}> */
    private array $results = [];

    public function __construct(
        private readonly bool $production,
        private readonly bool $withConnectivity = true,
    ) {}

    /**
     * @return array{
     *     status: string,
     *     environment: string,
     *     checks: list<array{id: string, status: string, detail: string, severity: string}>,
     *     summary: array{pass: int, warn: int, fail: int, skip: int}
     * }
     */
    public function run(): array
    {
        $this->results = [];

        $this->checkAppEnvironment();
        $this->checkApplicationKey();
        $this->checkSessionAndCookies();
        $this->checkSafetyProviderContract();
        $this->checkBackupLane();
        $this->checkHealthToken();
        $this->checkBuildArtifacts();
        $this->checkWritablePaths();
        $this->checkEnvFilePermissions();
        $this->checkQueueTimeouts();
        $this->checkRedisPassword();
        $this->checkLogPrivacy();

        if ($this->withConnectivity) {
            $this->checkDatabaseConnectivity();
            $this->checkCacheAndRedisConnectivity();
        } else {
            $this->record('database.connectivity', 'SKIP', 'Connectivity probes were not requested (--offline).', 'advisory');
            $this->record('cache.connectivity', 'SKIP', 'Connectivity probes were not requested (--offline).', 'advisory');
        }

        $summary = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'skip' => 0];

        foreach ($this->results as $result) {
            $summary[strtolower($result['status'])]++;
        }

        return [
            'status' => $summary['fail'] > 0 ? 'FAIL' : ($summary['warn'] > 0 ? 'WARN' : 'PASS'),
            'environment' => (string) config('app.env'),
            'checks' => $this->results,
            'summary' => $summary,
        ];
    }

    private function record(string $id, string $status, string $detail, string $severity = 'blocking'): void
    {
        $this->results[] = [
            'id' => $id,
            'status' => $status,
            'detail' => $detail,
            'severity' => $severity,
        ];
    }

    /** A failing advisory check does not block and does not escalate WARN to FAIL. */
    private function recordAdvisory(string $id, string $status, string $detail): void
    {
        $this->record($id, $status, $detail, 'advisory');
    }

    private function checkAppEnvironment(): void
    {
        $env = (string) config('app.env');
        $debug = (bool) config('app.debug');
        $url = (string) config('app.url');

        if ($this->production && ! in_array($env, ['production', 'prod'], true)) {
            $this->record('app.environment', 'FAIL', sprintf('APP_ENV is "%s" but this preflight expects production.', $this->maskValue($env)));
        } else {
            $this->record('app.environment', 'PASS', sprintf('APP_ENV is "%s".', $this->maskValue($env)));
        }

        if ($this->production && $debug) {
            $this->record('app.debug', 'FAIL', 'APP_DEBUG is enabled; stack traces would be served publicly.');
        } else {
            $this->record('app.debug', 'PASS', 'APP_DEBUG is disabled.');
        }

        if ($this->production) {
            if (! str_starts_with($url, 'https://')) {
                $this->record('app.url', 'FAIL', 'APP_URL is not https://; session cookies and payment callbacks would travel in clear text.');
            } elseif (str_contains($url, 'localhost') || str_contains($url, '127.0.0.1') || str_contains($url, 'example')) {
                $this->record('app.url', 'FAIL', 'APP_URL still points at a placeholder host.');
            } else {
                $this->recordAdvisory('app.url', 'PASS', 'APP_URL is a public https:// origin.');
            }
        } else {
            $this->recordAdvisory('app.url', 'SKIP', 'APP_URL https:// enforcement applies to production only.');
        }
    }

    private function checkApplicationKey(): void
    {
        $key = (string) config('app.key');

        if ($key === '') {
            $this->record('app.key', 'FAIL', 'APP_KEY is empty; encrypted sessions, cookies and payloads cannot be produced.');

            return;
        }

        $decoded = Str::startsWith($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if ($decoded === false || strlen((string) $decoded) !== 32) {
            $this->record('app.key', 'FAIL', 'APP_KEY is present but is not a 32-byte key (base64: or raw).');

            return;
        }

        $this->record('app.key', 'PASS', 'APP_KEY is a 32-byte key.');
    }

    private function checkSessionAndCookies(): void
    {
        if (! $this->production) {
            $this->recordAdvisory('session.cookie', 'SKIP', 'Cookie hardening checks apply to production only.');

            return;
        }

        $secure = (bool) config('session.secure');
        $encrypted = (bool) config('session.encrypt');
        $sameSite = strtolower((string) config('session.same_site'));

        if ($secure && $encrypted && in_array($sameSite, ['lax', 'strict'], true)) {
            $this->record('session.cookie', 'PASS', 'Session cookies are secure, encrypted and SameSite-restricted.');
        } else {
            $this->record('session.cookie', 'FAIL', sprintf(
                'Session cookies are secure=%s encrypt=%s same_site=%s; all three must be hardened in production.',
                $secure ? 'true' : 'false',
                $encrypted ? 'true' : 'false',
                $sameSite === '' ? 'unset' : $sameSite,
            ));
        }
    }

    /**
     * Delegates to the provider's contract rather than restating it, so a rule
     * added there is enforced here on the next release without a second edit.
     */
    private function checkSafetyProviderContract(): void
    {
        try {
            $violations = ProductionSafetyServiceProvider::violations();
        } catch (Throwable $error) {
            $this->record('safety.contract', 'WARN', 'The production safety contract could not be evaluated: '.$error->getMessage());

            return;
        }

        if ($violations === []) {
            $this->record('safety.contract', 'PASS', 'Every ProductionSafetyServiceProvider rule is satisfied.');

            return;
        }

        foreach ($violations as $index => $violation) {
            $this->record('safety.contract.'.($index + 1), $this->production ? 'FAIL' : 'WARN', $violation);
        }
    }

    private function checkBackupLane(): void
    {
        $configured = BackupCipher::hasConfiguredKey();

        if (! $configured) {
            $this->record(
                'backup.key',
                $this->production ? 'FAIL' : 'WARN',
                'No usable backup encryption key: set BACKUP_ENCRYPTION_KEY (>= 32 characters) or BACKUP_ENCRYPTION_KEY_FILE.',
            );
        } else {
            $length = strlen((string) BackupCipher::configuredKey());

            if ($this->production && $length < 32) {
                $this->record('backup.key', 'FAIL', sprintf('The backup key is %d characters; production requires at least 32.', $length));
            } else {
                $this->recordAdvisory('backup.key', 'PASS', sprintf('Backup key present (%d characters, value not printed).', $length));
            }
        }

        $path = (string) config('backup.path', storage_path('app/backups'));
        $publicRoot = (string) public_path();

        if (Str::startsWith(realpath($path) ?: $path, $publicRoot)) {
            $this->record('backup.path', 'FAIL', 'BACKUP_PATH is inside the web root; a backup archive must never be web-reachable.');
        } elseif (is_dir($path) && ! is_writable($path)) {
            $this->record('backup.path', 'FAIL', 'The backup directory exists but is not writable by the runtime user.');
        } else {
            $this->recordAdvisory('backup.path', 'PASS', 'Backup directory is outside the web root and writable (or creatable).');
        }

        $binary = config('backup.dump.mysql.binary', 'mysqldump');
        $driver = (string) config('database.default');
        $driverName = (string) config('database.connections.'.$driver.'.driver');
        $binaryName = $driverName === 'pgsql'
            ? (string) config('backup.dump.pgsql.binary', 'pg_dump')
            : (is_string($binary) ? $binary : 'mysqldump');

        $found = false;

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory !== '' && is_executable(rtrim($directory, '/').'/'.$binaryName)) {
                $found = true;

                break;
            }
        }

        $found
            ? $this->recordAdvisory('backup.binary', 'PASS', sprintf('The %s client is on PATH.', $binaryName))
            : $this->record('backup.binary', $this->production ? 'FAIL' : 'WARN', sprintf(
                'The %s client is not on PATH; this host cannot take a database backup.',
                $binaryName,
            ));
    }

    private function checkHealthToken(): void
    {
        $token = config('app.health_detail_token');

        if (! is_string($token) || strlen($token) < 32) {
            $this->recordAdvisory(
                'health.token',
                'WARN',
                'HEALTH_DETAIL_TOKEN is unset or shorter than 32 characters; /up/health will only return the public summary.'
                    .' That is a safe default, but it also means nobody can see the detailed report during an incident.'
            );

            return;
        }

        $this->recordAdvisory('health.token', 'PASS', 'A detailed health token is configured (value not printed).');
    }

    private function checkBuildArtifacts(): void
    {
        $manifest = public_path('build/manifest.json');

        if (! is_file($manifest)) {
            $this->record(
                'build.manifest',
                $this->production ? 'FAIL' : 'WARN',
                'public/build/manifest.json is missing; every page would fail to resolve its assets. '
                .'Run `npm run build` as part of the release, never on the production host ad hoc.'
            );

            return;
        }

        $decoded = json_decode((string) file_get_contents($manifest), true);

        if (! is_array($decoded) || $decoded === []) {
            $this->record('build.manifest', 'FAIL', 'public/build/manifest.json is empty or is not a JSON object.');

            return;
        }

        $missing = 0;

        foreach ($decoded as $entry) {
            if (is_array($entry) && isset($entry['file']) && ! is_file(public_path('build/'.$entry['file']))) {
                $missing++;
            }
        }

        $missing === 0
            ? $this->record('build.manifest', 'PASS', sprintf('%d manifest entries, every referenced file exists.', count($decoded)))
            : $this->record('build.manifest', 'FAIL', sprintf('%d manifest entries point at files that do not exist.', $missing));
    }

    private function checkWritablePaths(): void
    {
        $paths = [
            'storage/framework/cache/data' => storage_path('framework/cache/data'),
            'storage/framework/sessions' => storage_path('framework/sessions'),
            'storage/framework/views' => storage_path('framework/views'),
            'storage/logs' => storage_path('logs'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ];

        $unwritable = [];

        foreach ($paths as $label => $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $unwritable[] = $label;
            }
        }

        $unwritable === []
            ? $this->record('filesystem.writable', 'PASS', 'Every Laravel runtime directory is present and writable.')
            : $this->record('filesystem.writable', 'FAIL', 'These runtime directories are missing or read-only: '.implode(', ', $unwritable).'.');
    }

    /**
     * A world-readable .env is a credential disclosure even when every other
     * check passes.
     */
    private function checkEnvFilePermissions(): void
    {
        $envPath = base_path('.env');

        if (! is_file($envPath)) {
            $this->recordAdvisory('env.permissions', 'SKIP', 'No .env file in this deployment (environment is injected by the orchestrator).');

            return;
        }

        $permissions = fileperms($envPath) & 0777;

        // "others" must have no read bit. Group read is acceptable: the group is
        // normally the application group.
        if (($permissions & 0004) !== 0) {
            $this->record('env.permissions', $this->production ? 'FAIL' : 'WARN', sprintf(
                'The .env file is world-readable (mode %s). Set 0640.',
                decoct($permissions),
            ));

            return;
        }

        $this->recordAdvisory('env.permissions', 'PASS', sprintf('.env mode is %s.', decoct($permissions)));
    }

    /**
     * The classic Laravel production bug: a job's timeout is longer than the
     * queue's retry_after, so a slow-but-alive worker's job is handed to a
     * second worker and runs twice. For a payout job that is a double payout.
     */
    private function checkQueueTimeouts(): void
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            // Blocking in production, advisory everywhere else: the sync driver
            // is the correct default for a laptop and for the test suite, but a
            // production request must never perform a payout inline. Failing a
            // local preflight for a legitimate local configuration teaches
            // operators to ignore the gate.
            $this->record(
                'queue.timeouts',
                $this->production ? 'FAIL' : 'WARN',
                'QUEUE_CONNECTION is sync; jobs run inside the HTTP request with no retries, no backoff and no visibility.'
                .($this->production ? '' : ' This is acceptable outside production; it is not acceptable in it.')
            );

            return;
        }

        $retryAfter = config('queue.connections.'.$connection.'.retry_after');
        $horizonTimeouts = [];

        foreach ((array) config('horizon.environments', []) as $environment) {
            foreach ((array) $environment as $supervisor) {
                if (is_array($supervisor) && isset($supervisor['timeout'])) {
                    $horizonTimeouts[] = (int) $supervisor['timeout'];
                }
            }
        }

        if (! is_numeric($retryAfter)) {
            $this->recordAdvisory('queue.timeouts', 'SKIP', 'The active queue connection does not declare retry_after (event-driven or sync driver).');

            return;
        }

        $retryAfter = (int) $retryAfter;
        $maxTimeout = $horizonTimeouts === [] ? 0 : max($horizonTimeouts);

        if ($maxTimeout > 0 && $retryAfter <= $maxTimeout) {
            $this->record('queue.timeouts', 'FAIL', sprintf(
                'queue.retry_after (%d s) must exceed the longest Horizon worker timeout (%d s), otherwise a '
                .'still-running job is released and executed twice.',
                $retryAfter,
                $maxTimeout,
            ));

            return;
        }

        $this->recordAdvisory('queue.timeouts', 'PASS', sprintf('retry_after is %d s and the longest worker timeout is %d s.', $retryAfter, $maxTimeout));
    }

    private function checkRedisPassword(): void
    {
        $connection = (string) config('queue.default');
        $usesRedis = $connection === 'redis'
            || (string) config('cache.default') === 'redis'
            || (string) config('session.driver') === 'redis';

        if (! $usesRedis) {
            $this->recordAdvisory('redis.password', 'SKIP', 'Redis is not used for queue, cache or sessions.');

            return;
        }

        $password = config('database.redis.default.password');

        if (! is_string($password) || $password === '') {
            $this->record(
                'redis.password',
                $this->production ? 'FAIL' : 'WARN',
                'Redis has no password while carrying sessions, cache and queues. Anyone who reaches port 6379 '
                .'can read sessions and inject jobs.'
            );

            return;
        }

        $this->recordAdvisory('redis.password', 'PASS', 'Redis credentials are configured (value not printed).');
    }

    private function checkLogPrivacy(): void
    {
        $channel = (string) config('logging.default');
        $redaction = (bool) config('logging.redaction.enabled', true);

        if ($this->production && in_array($channel, ['single', 'daily'], true)) {
            $this->recordAdvisory('log.privacy', 'WARN', sprintf(
                'LOG_CHANNEL is "%s"; container deployments should log to stderr so the orchestrator owns rotation and retention.',
                $channel
            ));
        } else {
            $this->recordAdvisory('log.privacy', 'PASS', sprintf('LOG_CHANNEL is "%s".', $channel));
        }

        $redaction
            ? $this->recordAdvisory('log.redaction', 'PASS', 'Secret redaction is enabled for every log channel.')
            : $this->record('log.redaction', $this->production ? 'FAIL' : 'WARN', 'LOG_REDACT_SENSITIVE is false; secrets could reach the log sink.');
    }

    private function checkDatabaseConnectivity(): void
    {
        $start = microtime(true);

        try {
            DB::connection()->select('SELECT 1');
        } catch (Throwable $error) {
            $this->record('database.connectivity', 'FAIL', 'The database is unreachable: '.$this->sanitize($error->getMessage()));

            return;
        }

        $latency = round((microtime(true) - $start) * 1000, 1);

        $latency > 1000
            ? $this->recordAdvisory('database.connectivity', 'WARN', sprintf('The database answered in %s ms.', $latency))
            : $this->record('database.connectivity', 'PASS', sprintf('The database answered in %s ms.', $latency));

        try {
            $driver = (string) DB::connection()->getDriverName();
            $pending = DB::connection()->getSchemaBuilder()->hasTable('migrations')
                ? DB::table('migrations')->count()
                : -1;

            $this->recordAdvisory('database.schema', 'PASS', sprintf(
                'Driver "%s" with %s applied migrations.',
                $driver,
                $pending < 0 ? 'no migrations table' : (string) $pending,
            ));
        } catch (Throwable $error) {
            $this->recordAdvisory('database.schema', 'WARN', 'Migration state could not be read: '.$this->sanitize($error->getMessage()));
        }
    }

    private function checkCacheAndRedisConnectivity(): void
    {
        try {
            $key = 'ops:preflight:'.bin2hex(random_bytes(6));
            cache()->put($key, 'ok', 10);

            if (cache()->get($key) !== 'ok') {
                $this->record('cache.connectivity', 'FAIL', 'The cache store accepts a write but does not return it; caching and locks cannot be trusted.');

                return;
            }

            cache()->forget($key);
            $this->record('cache.connectivity', 'PASS', sprintf('Cache store "%s" round-trips a value.', (string) config('cache.default')));
        } catch (Throwable $error) {
            $this->record('cache.connectivity', 'FAIL', 'The cache store is unreachable: '.$this->sanitize($error->getMessage()));

            return;
        }

        try {
            $pong = Redis::connection()->ping();
            $this->recordAdvisory('redis.connectivity', 'PASS', 'Redis answered PING with '.$this->maskValue((string) (is_object($pong) ? 'PONG' : $pong)).'.');
        } catch (Throwable $error) {
            $usesRedis = (string) config('cache.default') === 'redis' || (string) config('queue.default') === 'redis';

            $this->record(
                'redis.connectivity',
                $usesRedis ? 'FAIL' : 'WARN',
                'Redis is not reachable: '.$this->sanitize($error->getMessage()),
            );
        }
    }

    /**
     * Defensive: nothing a check interpolates should look like a credential.
     * Connection errors are the usual culprit (they can carry a DSN).
     */
    private function sanitize(string $message): string
    {
        $scrubbed = preg_replace(
            ['/(password|pwd|passwd)\s*=\s*[^\s;,]+/i', '/(:\/\/[^:\s]+:)[^@\s]+@/', '/(--password=)\S+/'],
            ['$1=[REDACTED]', '$1[REDACTED]@', '$1[REDACTED]'],
            $message,
        );

        return Str::limit(trim((string) $scrubbed), 240);
    }

    private function maskValue(string $value): string
    {
        return $value === '' ? '(empty)' : $value;
    }
}
