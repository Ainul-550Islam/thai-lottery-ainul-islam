<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Support\Ops\ProductionPreflight;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * The preflight is the gate a release passes through, so what it *reports* is a
 * safety property of the deployment process.
 *
 * Two classes of bug are pinned here:
 *   1. the checks do not fire when they should (a broken release is allowed);
 *   2. the report leaks the values it is inspecting (a preflight transcript in a
 *      ticket becomes a credential disclosure).
 *
 * This suite deliberately extends the framework's TestCase instead of
 * Tests\TestCase: the shared base class migrates the database on every boot,
 * and the preflight is a pure configuration gate — every case here passes
 * `withConnectivity: false`, so no schema is involved. Booting without the
 * schema keeps this gate testable while the database/migrations lane is
 * repaired elsewhere (see HANDOFF-C.md), instead of hiding a working safety
 * check behind an unrelated fixture failure.
 */
final class ProductionPreflightTest extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The preflight inspects compiled assets; nothing here renders a view.
        $this->withoutVite();
    }

    private const SECRET_KEY = 'a-production-backup-key-of-sufficient-length';

    public function test_a_production_release_with_debug_enabled_is_blocked(): void
    {
        $this->app['config']->set('app.env', 'production');
        $this->app['config']->set('app.debug', true);

        $report = (new ProductionPreflight(production: true, withConnectivity: false))->run();

        $this->assertSame('FAIL', $report['status']);

        $failing = array_column(
            array_filter($report['checks'], static fn (array $check): bool => $check['status'] === 'FAIL'),
            'id'
        );

        $this->assertContains('app.debug', $failing);
    }

    public function test_a_production_release_without_a_backup_key_is_blocked(): void
    {
        $this->app['config']->set('app.env', 'production');
        $this->app['config']->set('backup.key', null);
        $this->app['config']->set('backup.key_file', null);

        $report = (new ProductionPreflight(production: true, withConnectivity: false))->run();

        $backup = collect($report['checks'])->firstWhere('id', 'backup.key');

        $this->assertNotNull($backup);
        $this->assertSame('FAIL', $backup['status'], 'a production release must not proceed without a backup key');
    }

    public function test_a_production_release_with_a_short_backup_key_is_blocked(): void
    {
        $this->app['config']->set('app.env', 'production');
        $this->app['config']->set('backup.key', 'only-16-chars-ok');
        $this->app['config']->set('backup.key_file', null);

        $report = (new ProductionPreflight(production: true, withConnectivity: false))->run();

        $backup = collect($report['checks'])->firstWhere('id', 'backup.key');

        $this->assertSame('FAIL', $backup['status']);
        $this->assertStringContainsString('at least 32', (string) $backup['detail']);
    }

    public function test_the_report_never_contains_a_secret_value(): void
    {
        $this->app['config']->set('app.env', 'production');
        $this->app['config']->set('app.debug', false);
        $this->app['config']->set('backup.key', self::SECRET_KEY);
        $this->app['config']->set('backup.key_file', null);
        $this->app['config']->set('app.health_detail_token', str_repeat('h', 40));
        $this->app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $this->app['config']->set('database.redis.default.password', 'redis-super-secret');

        $report = (new ProductionPreflight(production: true, withConnectivity: false))->run();
        $serialised = (string) json_encode($report);

        foreach ([self::SECRET_KEY, 'redis-super-secret', str_repeat('h', 40), str_repeat('k', 32), (string) config('app.key')] as $secret) {
            $this->assertStringNotContainsString($secret, $serialised, 'the preflight report leaked a secret value');
        }

        // It must still be useful: the shape (length) is reported.
        $backup = collect($report['checks'])->firstWhere('id', 'backup.key');
        $this->assertStringContainsString((string) strlen(self::SECRET_KEY), (string) $backup['detail']);
    }

    public function test_missing_build_artifacts_fail_a_production_release(): void
    {
        $this->app['config']->set('app.env', 'production');

        $manifest = public_path('build/manifest.json');
        $existed = is_file($manifest);
        $contents = $existed ? (string) file_get_contents($manifest) : null;

        if ($existed) {
            @unlink($manifest);
        }

        try {
            $report = (new ProductionPreflight(production: true, withConnectivity: false))->run();
            $build = collect($report['checks'])->firstWhere('id', 'build.manifest');

            $this->assertNotNull($build);
            $this->assertSame('FAIL', $build['status']);
        } finally {
            if ($existed && $contents !== null) {
                @mkdir(dirname($manifest), 0775, true);
                file_put_contents($manifest, $contents);
            }
        }
    }

    public function test_queue_retry_after_must_exceed_the_longest_worker_timeout(): void
    {
        // The classic double-execution bug: retry_after below the worker timeout
        // means a slow-but-alive job is handed to a second worker.
        $this->app['config']->set('queue.default', 'redis');
        $this->app['config']->set('queue.connections.redis.retry_after', 60);
        $this->app['config']->set('horizon.environments.production.supervisor-1', [
            'connection' => 'redis',
            'queue' => ['default'],
            'timeout' => 300,
        ]);

        $report = (new ProductionPreflight(production: true, withConnectivity: false))->run();
        $timeouts = collect($report['checks'])->firstWhere('id', 'queue.timeouts');

        $this->assertSame('FAIL', $timeouts['status']);
        $this->assertStringContainsString('executed twice', (string) $timeouts['detail']);
    }

    public function test_a_sync_queue_blocks_production_but_only_warns_elsewhere(): void
    {
        // A production request must never run a payout inline. Outside
        // production the sync driver is the correct default, and failing the
        // gate there teaches operators to ignore it.
        $this->app['config']->set('queue.default', 'sync');

        $this->app['config']->set('app.env', 'production');
        $production = collect((new ProductionPreflight(production: true, withConnectivity: false))->run()['checks'])
            ->firstWhere('id', 'queue.timeouts');
        $this->assertSame('FAIL', $production['status']);

        $this->app['config']->set('app.env', 'local');
        $local = collect((new ProductionPreflight(production: false, withConnectivity: false))->run()['checks'])
            ->firstWhere('id', 'queue.timeouts');
        $this->assertSame('WARN', $local['status']);
    }

    public function test_a_non_production_environment_warns_instead_of_blocking(): void
    {
        $this->app['config']->set('app.env', 'local');
        $this->app['config']->set('backup.key', null);
        $this->app['config']->set('backup.key_file', null);

        $report = (new ProductionPreflight(production: false, withConnectivity: false))->run();
        $backup = collect($report['checks'])->firstWhere('id', 'backup.key');

        $this->assertSame('WARN', $backup['status']);
    }
}
