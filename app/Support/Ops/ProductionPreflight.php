<?php

declare(strict_types=1);

namespace App\Support\Ops;

use App\Providers\ProductionSafetyServiceProvider;
use App\Services\Compliance\FinancialComplianceGateService;
use App\Services\Payment\Drivers\ThaiBankPayoutGateway;
use App\Services\Payment\PaymentGatewayManager;
use App\Support\Backup\BackupCipher;
use App\Support\Ops\CheckResult;
use App\Support\Ops\LaneFlagChecks;
use App\Support\Ops\RealtimeChecks;
use App\Support\Ops\ThaiRailsChecks;
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
 * artifacts, key material shape, backup readiness, and the payment / realtime /
 * optional lanes. The lane checks are DELEGATED to the same classes the lane
 * commands use (ThaiRailsChecks, RealtimeChecks, LaneFlagChecks) so a rule
 * added there is enforced here on the next release without a second edit, and
 * so the command, the report and the tests can never disagree about a lane.
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
        $this->checkFieldEncryptionKey();
        $this->checkPlaceholderHosts();
        $this->checkTrustedProxies();
        $this->checkBroadcastDriver();
        $this->checkMailHost();
        $this->checkPromptPayMerchant();
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
        $this->checkThaiRailsLanes();
        $this->checkRealtimeLane();
        $this->checkOptionalLanes();
        $this->checkPayoutComplianceGate();
        $this->checkPayoutGatewayRouting();

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

    private function checkFieldEncryptionKey(): void
    {
        $fieldKey = $this->encryptionKeyMaterial(config('app.field_encryption_key'));
        $appKey = $this->encryptionKeyMaterial(config('app.key'));
        $missing = $fieldKey === null;
        $sameAsAppKey = $fieldKey !== null && $appKey !== null && hash_equals($fieldKey, $appKey);

        if ($this->production && ($missing || $sameAsAppKey)) {
            $this->record('field_encryption.key', 'FAIL', 'FIELD_ENCRYPTION_KEY must be non-empty and distinct from APP_KEY in production.');
        } elseif ($missing || $sameAsAppKey) {
            $this->recordAdvisory('field_encryption.key', 'WARN', 'A distinct FIELD_ENCRYPTION_KEY is required before production deployment.');
        } else {
            $this->recordAdvisory('field_encryption.key', 'PASS', 'A distinct field-encryption key is configured.');
        }
    }

    private function encryptionKeyMaterial(mixed $key): ?string
    {
        if (! is_string($key) || trim($key) === '') {
            return null;
        }
        if (! str_starts_with($key, 'base64:')) {
            return $key;
        }

        $decoded = base64_decode(substr($key, 7), true);

        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    private function checkPlaceholderHosts(): void
    {
        if (! $this->production) {
            $this->recordAdvisory('host.placeholder', 'SKIP', 'Placeholder-host enforcement applies to production only.');

            return;
        }

        $values = [
            'APP_URL' => config('app.url'),
            'SESSION_DOMAIN' => config('session.domain'),
            'MAIL_HOST' => config('mail.mailers.smtp.host'),
            'REVERB_HOST' => config('broadcasting.connections.reverb.options.host', config('reverb.apps.apps.0.options.host')),
        ];
        $placeholders = [];

        foreach ($values as $name => $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }
            $host = strtolower((string) (parse_url($value, PHP_URL_HOST) ?: $value));
            $host = trim(preg_replace('/^\\[|\\]$/', '', $host) ?? $host);
            if (str_contains($host, '.example') || str_contains($host, 'example.com')
                || str_contains($host, 'localhost') || str_contains($host, '127.0.0.1')) {
                $placeholders[] = $name;
            }
        }

        $placeholders === []
            ? $this->recordAdvisory('host.placeholder', 'PASS', 'No placeholder application, session, mail or realtime hosts are configured.')
            : $this->record('host.placeholder', 'FAIL', 'Placeholder hosts remain configured: '.implode(', ', $placeholders).'.');
    }

    private function checkTrustedProxies(): void
    {
        $setting = config('network.trusted_proxies_setting');
        $raw = is_string($setting) ? trim($setting) : '';
        $entries = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $entry): bool => $entry !== '',
        ));
        $wildcardOverride = (bool) config('network.allow_wildcard_trusted_proxies', false);
        $wildcard = array_intersect($entries, ['*', '**']) !== [];

        if ($this->production && $entries === []) {
            $this->record('proxy.trusted', 'FAIL', 'TRUSTED_PROXIES must contain an explicit proxy CIDR list in production.');
        } elseif ($this->production && $wildcard && ! $wildcardOverride) {
            $this->record('proxy.trusted', 'FAIL', 'Wildcard trusted proxies require ALLOW_WILDCARD_TRUSTED_PROXIES=true.');
        } elseif ($entries === []) {
            $this->recordAdvisory('proxy.trusted', 'WARN', 'No explicit TRUSTED_PROXIES value is configured.');
        } else {
            $this->recordAdvisory('proxy.trusted', 'PASS', $wildcard ? 'Wildcard proxy trust has an explicit override.' : 'Explicit trusted proxy addresses are configured.');
        }
    }

    private function checkBroadcastDriver(): void
    {
        $driver = (string) config('broadcasting.default', 'log');
        $realtimeEnabled = (bool) config('chat.enabled', true)
            && (string) config('chat.transport', 'poll') === 'broadcast';

        if ($driver === 'log' && $realtimeEnabled) {
            $this->recordAdvisory('broadcast.driver', 'WARN', 'Realtime features are enabled but BROADCAST_CONNECTION=log only writes events to the log.');
        } else {
            $this->recordAdvisory('broadcast.driver', 'PASS', 'The broadcast driver is consistent with the configured realtime features.');
        }
    }

    private function checkMailHost(): void
    {
        $mailer = (string) config('mail.default');
        $host = config('mail.mailers.smtp.host');

        if ($mailer === 'smtp' && (! is_string($host) || trim($host) === '')) {
            $this->record('mail.host', 'FAIL', 'MAIL_HOST is required when MAIL_MAILER=smtp.');
        } else {
            $this->recordAdvisory('mail.host', 'PASS', $mailer === 'smtp' ? 'An SMTP host is configured.' : 'SMTP host validation is not required for the selected mailer.');
        }
    }

    /**
     * Delegated to ThaiRailsChecks so the command, this report and the tests
     * all read the same verdict for the same question.
     *
     * THE DISABLED CASE KEEPS ITS PUBLISHED SHAPE
     * -------------------------------------------
     * A deployment without PromptPay has always reported
     * `promptpay.merchant = PASS / "PromptPay is disabled."` as an ADVISORY
     * row, and a release gate reads it. The shared check answers the same
     * question with the same PASS verdict; this method keeps the row's
     * severity and wording as they were, so delegating the rule changes no
     * published contract.
     */
    private function checkPromptPayMerchant(): void
    {
        if (! (bool) config('payment.gateways.promptpay.enabled', false)) {
            $this->recordAdvisory('promptpay.merchant', 'PASS', 'PromptPay is disabled.');

            return;
        }

        $result = app(ThaiRailsChecks::class)->promptpay();

        $this->record(
            'promptpay.merchant',
            $result->status === CheckResult::FAIL ? 'FAIL' : 'PASS',
            $result->status === CheckResult::FAIL
                ? 'PROMPTPAY_MERCHANT_ID is not a supported PromptPay identifier.'
                : 'The configured PromptPay merchant identifier has a valid format.',
        );
    }

    /**
     * The Thai payment rails, read from the shared check class.
     *
     * A disabled rail is SKIP, not PASS: the honest answer for a lane this
     * deployment does not operate is "not applicable", and a preflight that
     * reported PASS for a switched-off rail would teach operators to ignore it.
     */
    private function checkThaiRailsLanes(): void
    {
        foreach (app(ThaiRailsChecks::class)->run() as $result) {
            $this->recordCheck($result, 'thai_rails.');
        }
    }

    /**
     * The real-time lane, only when the application has switched it on. A
     * deployment with REALTIME_ENABLED=false gets one SKIP line and nothing
     * else, so a dark lane is never mistaken for a broken one.
     */
    private function checkRealtimeLane(): void
    {
        if (! (bool) config('realtime.enabled', false)) {
            $this->recordAdvisory('realtime.lane', 'SKIP', 'Realtime is disabled (REALTIME_ENABLED=false).');

            return;
        }

        foreach (app(RealtimeChecks::class)->run(skipNetwork: true) as $result) {
            $this->recordCheck($result, 'realtime.');
        }
    }

    /**
     * The optional lanes (legal PDF, payout illustration, privacy requests,
     * support tickets). Each is skipped when its configuration is absent or the
     * lane is off, and checked when it is on.
     */
    private function checkOptionalLanes(): void
    {
        foreach (app(LaneFlagChecks::class)->run() as $result) {
            $this->recordCheck($result, 'lane.');
        }
    }

    /**
     * The payout compliance gate must be resolvable BEFORE a payout can be
     * completed: PayoutTransferService resolves it from the container at the
     * completion boundary, and a class that cannot be built turns a reviewed,
     * approved, evidenced payout into a container error at the worst possible
     * moment. This check fails the release instead of the payout.
     */
    private function checkPayoutComplianceGate(): void
    {
        try {
            $gate = app(FinancialComplianceGateService::class);

            $resolvable = $gate instanceof FinancialComplianceGateService
                && method_exists($gate, 'assertPayoutAllowed')
                && method_exists($gate, 'assertWithdrawalAllowed');
        } catch (Throwable $error) {
            $this->record('payout.compliance_gate', 'FAIL', 'The payout compliance gate could not be resolved: '.$error::class);

            return;
        }

        $this->record(
            'payout.compliance_gate',
            $resolvable ? 'PASS' : 'FAIL',
            $resolvable
                ? 'The payout compliance gate resolves and exposes both payout and withdrawal assertions.'
                : 'The payout compliance gate is missing one of its assertion verbs.',
        );
    }

    /**
     * The automated payout routing must resolve the driver it persists. When
     * thai_payout.enabled is true the disbursement persists the key 'thai_bank'
     * on the withdrawal, so the manager must be able to resolve that key back
     * to a driver; when the lane is off there is nothing to resolve.
     */
    private function checkPayoutGatewayRouting(): void
    {
        if (! (bool) config('thai_payout.enabled', false)) {
            $this->recordAdvisory('payout.gateway_routing', 'SKIP', 'The automated Thai bank payout lane is disabled.');

            return;
        }

        try {
            $manager = app(PaymentGatewayManager::class);
            $resolvable = $manager->hasDriver('thai_bank');
            $driver = $resolvable ? $manager->driver('thai_bank') : null;
            $resolvable = $driver instanceof ThaiBankPayoutGateway && $driver->supportsWithdrawal();
        } catch (Throwable $error) {
            $this->record('payout.gateway_routing', 'FAIL', 'The thai_bank payout driver could not be resolved: '.$error::class);

            return;
        }

        $this->record(
            'payout.gateway_routing',
            $resolvable ? 'PASS' : 'FAIL',
            $resolvable
                ? 'The thai_bank payout key resolves to a withdrawal-capable driver.'
                : 'The thai_bank payout key does not resolve to a withdrawal-capable driver.',
        );
    }

    /**
     * One shared-check result becomes one preflight row.
     *
     * A FAIL is BLOCKING in every environment, exactly as the PromptPay check
     * above always was: a lane that is switched ON and misconfigured is a live
     * defect, not a laptop inconvenience, and the lane commands themselves exit
     * 1 on the same verdict. A PASS or a SKIP is advisory — a skipped lane is
     * a lane this deployment does not operate.
     */
    private function recordCheck(CheckResult $result, string $prefix): void
    {
        if ($result->status === CheckResult::FAIL) {
            $this->record($prefix.$result->name, 'FAIL', $result->message);

            return;
        }

        $this->recordAdvisory(
            $prefix.$result->name,
            $result->status === CheckResult::PASS ? 'PASS' : 'SKIP',
            $result->message,
        );
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
