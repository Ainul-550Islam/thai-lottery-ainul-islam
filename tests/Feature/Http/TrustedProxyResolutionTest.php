<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\AuthenticationAttempt;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * The client-address contract.
 *
 * Illuminate\Http\Middleware\TrustProxies has been running on every request all along -
 * the framework puts it in the default global stack - but with no proxy list to believe,
 * so handle() opens by clearing trust and every $request->ip() resolves to the machine
 * that opened the TCP connection. The application's own App\Http\Middleware\TrustProxies
 * subclass promised to fix exactly that, was never registered anywhere, and is deleted by
 * this cluster; config/trustedproxy.php is now the single knob, which the framework
 * middleware reads by itself.
 *
 * The consequences were measured, not assumed: the login limiter's second budget is
 * Limit::perMinutes(...)->by('login:ip:'.$request->ip()) (AppServiceProvider), the audit
 * lane persists 'ip_address' into authentication_attempts (AuthenticationSecurityService),
 * and HomeController keys a cache entry with sha1($input.'|'.$request->ip()).
 *
 * So the tests below go through the real HTTP stack and read the state the middleware
 * actually installed on Symfony's Request, rather than re-checking my own arithmetic.
 */
final class TrustedProxyResolutionTest extends TestCase
{
    /**
     * The cache and the sqlite file both outlive a test run.
     *
     * Without this, a second run of the throttle test sees the bucket the first one emptied
     * and reports 429 for every request in milliseconds - a green test that asserted nothing,
     * which is exactly what happened while this file was being written. The authentication
     * gate keys on identifier + IP too, so a reused identifier can be refused before the
     * audit lane is reached and the row a test looks for never exists.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::clear();
    }

    private const LOOPBACK = '127.0.0.1';

    private const TRUSTED_INTERNAL_PEER = '10.0.0.5';

    private const UNTRUSTED_PUBLIC_PEER = '203.0.113.9';

    /**
     * The addresses believed when TRUSTED_PROXIES is unset, as documented in the config.
     *
     * @return list<string>
     */
    private static function internalRanges(): array
    {
        return [self::LOOPBACK, '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
    }

    #[Test]
    public function the_setting_resolves_to_the_shape_the_framework_actually_expects(): void
    {
        $this->assertSame(
            self::internalRanges(),
            $this->resolveWith(''),
            'an unset TRUSTED_PROXIES must believe only internal peers'
        );

        $this->assertSame(self::internalRanges(), $this->resolveWith('internal'));

        $this->assertSame([], $this->resolveWith('none'), 'none must mean trust nobody');

        // Load bearing, and the reason this test exists at all: the framework compares the
        // value against the bare strings '*' / '**' before it treats anything as a list, and
        // Symfony matches array entries as exact addresses or CIDR blocks. A one-element
        // array ['*'] therefore trusts no one while looking configured.
        $this->assertSame('*', $this->resolveWith('*'));
        $this->assertIsString($this->resolveWith('*'), 'the wildcard must stay a string, not become ["*"]');

        $this->assertSame(
            ['203.0.113.10', '198.51.100.0/24'],
            $this->resolveWith('203.0.113.10, 198.51.100.0/24'),
            'a list must be trimmed into entries'
        );

        $this->assertSame(
            ['203.0.113.10'],
            $this->resolveWith('203.0.113.10,,'),
            'empty entries must be dropped, never kept as an address'
        );
    }

    #[Test]
    public function the_proxy_list_applied_to_a_real_request_is_the_configured_one(): void
    {
        $this->postLogin(self::LOOPBACK, '203.0.113.77');

        $applied = Request::getTrustedProxies();

        $this->assertSame(
            self::internalRanges(),
            $applied,
            'the middleware installed a different proxy list than config/trustedproxy.php declares'
        );

        // Without the config file the framework clears trust, so this is the exact value
        // the cluster changed. Asserting it is non-empty is what makes the guard meaningful.
        $this->assertNotEmpty($applied, 'nothing is trusted, which is the state that was fixed');
    }

    #[Test]
    public function the_header_mask_believes_address_and_protocol_but_never_the_host(): void
    {
        $this->postLogin(self::LOOPBACK, '203.0.113.77');

        $installed = Request::getTrustedHeaderSet();

        $this->assertHasHeader($installed, Request::HEADER_X_FORWARDED_FOR, 'the limiter and the audit lanes need it');
        $this->assertHasHeader($installed, Request::HEADER_X_FORWARDED_PROTO, 'secure URL generation needs it');

        $this->assertSame(
            0,
            $installed & Request::HEADER_X_FORWARDED_HOST,
            'X-Forwarded-Host is not read anywhere in this application, and trusting it while '
            . 'an operator may set TRUSTED_PROXIES=* would let any client choose the Host the app echoes'
        );
    }

    #[Test]
    public function a_forwarded_client_ip_from_a_trusted_peer_is_the_address_that_gets_audited(): void
    {
        $row = $this->auditFor('trusted-peer', self::TRUSTED_INTERNAL_PEER, '203.0.113.77');

        $this->assertSame(
            '203.0.113.77',
            $row->ip_address,
            'a proxy on the internal network must be believed, or every player is logged as the load balancer'
        );
    }

    #[Test]
    public function a_forwarded_client_ip_from_an_untrusted_peer_is_refused(): void
    {
        $row = $this->auditFor('untrusted-peer', self::UNTRUSTED_PUBLIC_PEER, '198.51.100.1');

        $this->assertSame(
            self::UNTRUSTED_PUBLIC_PEER,
            $row->ip_address,
            'a client on a public address must not be able to invent its own X-Forwarded-For'
        );
    }

    #[Test]
    public function players_behind_one_proxy_do_not_share_the_login_ip_budget(): void
    {
        $ceiling = (int) config('security.rate_limits.login.max_attempts') * 5;

        $this->assertGreaterThan(0, $ceiling, 'the limiter ceiling resolved to nothing, so this test would prove nothing');

        // Exhaust the bucket for one forwarded client, using a distinct identifier every
        // time so the identifier-keyed half of the limiter can never be what trips.
        $nonce = bin2hex(random_bytes(6));

        for ($attempt = 0; $attempt < $ceiling; $attempt++) {
            $response = $this->postLogin(
                self::TRUSTED_INTERNAL_PEER,
                '203.0.113.11',
                'probe-'.$attempt.'-'.$nonce.'@example.test'
            );

            // Each of these must be a real credential rejection. If any of them were
            // already throttled, the 429 below would prove nothing about the ceiling.
            $this->assertSame(
                401,
                $response->getStatusCode(),
                'attempt '.($attempt + 1).' of the ceiling was refused before the credentials were tested'
            );
        }

        $this->assertSame(
            429,
            $this->postLogin(self::TRUSTED_INTERNAL_PEER, '203.0.113.11', 'probe-final-'.$nonce.'@example.test')->getStatusCode(),
            'the ip-keyed budget never filled up, so the limiter is not keyed the way this test assumes'
        );

        $this->assertNotSame(
            429,
            $this->postLogin(self::TRUSTED_INTERNAL_PEER, '203.0.113.12', 'neighbour-'.$nonce.'@example.test')->getStatusCode(),
            'a second player behind the same proxy inherited someone else\'s exhausted budget - '
            .'that is what a collapsed client IP does to every login in the lobby'
        );
    }

    #[Test]
    public function the_dead_subclass_stays_gone_and_leaves_exactly_one_knob(): void
    {
        $this->assertFalse(
            file_exists(base_path('app/Http/Middleware/TrustProxies.php')),
            'App\Http\Middleware\TrustProxies is back: it was never registered, it set $proxies to a '
            .'hardcoded wildcard while its docblock promised an environment variable it never read, and '
            .'its presence invites the same drift again'
        );

        $references = [];

        foreach (['app', 'config', 'routes', 'bootstrap', 'tests'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                // Only a real reference counts - an import, a ::class resolution or a
                // registration. This file itself names the class in prose to explain why it
                // must stay gone, and prose is not wiring.
                foreach (['use App\\Http\\Middleware\\TrustProxies;', 'App\\Http\\Middleware\\TrustProxies::class'] as $needle) {
                    if (str_contains($source, $needle)) {
                        $references[] = str_replace(base_path().'/', '', $file->getPathname()).' ('.$needle.')';
                    }
                }
            }
        }

        $this->assertSame([], $references, 'the removed middleware is still referenced');

        $config = require config_path('trustedproxy.php');

        // A second knob nobody reads is how this cluster started, so the config must expose
        // only what the framework middleware actually consumes.
        $this->assertSame(['proxies'], array_keys($config));
        $this->assertNull(
            (new ReflectionClass(TrustProxies::class))->getStaticPropertyValue('alwaysTrustProxies'),
            'bootstrap/app.php must not also pin the proxy list: two sources would drift again'
        );
    }

    #[Test]
    public function every_environment_template_documents_the_variable_and_the_cutover_stays_honest(): void
    {
        foreach (['.env.example', '.env.production.example', '.env.ci'] as $template) {
            $contents = (string) file_get_contents(base_path($template));

            $this->assertMatchesRegularExpression(
                '/TRUSTED_PROXIES/',
                $contents,
                "{$template} does not mention TRUSTED_PROXIES, so an operator copying it has no way to "
                .'discover that client IPs are being resolved to the proxy'
            );

            $this->assertStringContainsString(
                'config/trustedproxy.php',
                $contents,
                "{$template} must point at the file that reads the variable"
            );
        }

        $checklist = (string) file_get_contents(base_path('deployment/CUTOVER-CHECKLIST.md'));

        $proxyLines = array_values(array_filter(
            explode("\n", $checklist),
            static fn (string $line): bool => stripos($line, 'trusted proxies') !== false
        ));

        $this->assertNotEmpty($proxyLines, 'the cutover checklist no longer mentions trusted proxies at all');

        foreach ($proxyLines as $line) {
            if (str_starts_with(trim($line), '- [x]')) {
                $this->assertStringContainsString(
                    'code side',
                    $line,
                    'a checked box may only attest the code half; the operator half is the one that decides '
                    .'whether player IPs are real, and it must stay unchecked until TRUSTED_PROXIES is set'
                );
            } else {
                $this->assertStringContainsString('TRUSTED_PROXIES', $line);
            }
        }
    }

    /**
     * Re-evaluate config/trustedproxy.php under a given TRUSTED_PROXIES value.
     *
     * @return array{proxies: mixed}|mixed
     */
    private function resolveWith(string $value): mixed
    {
        $name = 'TRUSTED_PROXIES';
        $repository = Env::getRepository();

        $originalRepository = $repository->get($name);
        $originalPutenv = getenv($name);
        $hadEnv = array_key_exists($name, $_ENV);
        $hadServer = array_key_exists($name, $_SERVER);
        $originalEnv = $_ENV[$name] ?? null;
        $originalServer = $_SERVER[$name] ?? null;

        // The repository Laravel builds is immutable, so clear() is a no-op; the adapters
        // read $_SERVER, $_ENV and getenv() live, which is what actually takes effect.
        $repository->clear($name);
        putenv($value === '' ? $name : $name.'='.$value);
        unset($_ENV[$name], $_SERVER[$name]);

        if ($value !== '') {
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }

        try {
            $fresh = require config_path('trustedproxy.php');

            return $fresh['proxies'];
        } finally {
            if ($originalPutenv === false) {
                putenv($name);
            } else {
                putenv($name.'='.$originalPutenv);
            }

            if ($hadEnv) {
                $_ENV[$name] = $originalEnv;
            } else {
                unset($_ENV[$name]);
            }

            if ($hadServer) {
                $_SERVER[$name] = $originalServer;
            } else {
                unset($_SERVER[$name]);
            }
        }
    }

    private function assertHasHeader(int $installed, int $flag, string $why): void
    {
        $this->assertNotSame(
            0,
            $installed & $flag,
            "the framework's own header set was replaced by bootstrap/app.php and this flag was lost: {$why}"
        );
    }

    /**
     * Send one login and hand back the audit row that request wrote.
     *
     * authentication_attempts is not truncated between tests (the suite shares a sqlite
     * file across runs), so 'the newest row' is somebody else's row - a first draft of this
     * test read latest('id') and passed for the wrong reason twice in a row. Every probe
     * therefore carries a unique user agent, which the audit lane persists verbatim
     * (AuthenticationSecurityService::record writes 'user_agent'), and the row is found by
     * that. The count assertion is what stops a request that recorded nothing from reading
     * as a pass.
     */
    private function auditFor(string $tag, string $peerIp, ?string $forwardedFor): AuthenticationAttempt
    {
        $nonce = bin2hex(random_bytes(6));
        $marker = 'trusted-proxy-probe:'.$tag.':'.$nonce;

        $response = $this->postLogin($peerIp, $forwardedFor, 'probe-'.$tag.'-'.$nonce.'@example.test', $marker);

        $this->assertSame(401, $response->getStatusCode(), "the probe did not reach the audit lane: {$response->getContent()}");

        $query = AuthenticationAttempt::query()->where('user_agent', $marker);

        $this->assertSame(1, $query->count(), "expected exactly one audit row for {$marker}");

        return $query->firstOrFail();
    }

    private function postLogin(
        string $peerIp,
        ?string $forwardedFor,
        string $identifier = 'nobody@example.test',
        ?string $userAgent = null,
    ): \Illuminate\Testing\TestResponse {
        $server = ['REMOTE_ADDR' => $peerIp];

        if ($forwardedFor !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }

        if ($userAgent !== null) {
            $server['HTTP_USER_AGENT'] = $userAgent;
        }

        return $this->withServerVariables($server)->postJson('/api/v1/auth/login', [
            'login' => $identifier,
            'password' => 'not-the-password',
        ]);
    }
}
