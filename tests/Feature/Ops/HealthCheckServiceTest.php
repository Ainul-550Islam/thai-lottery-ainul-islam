<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Services\Ops\HealthCheckService;
use App\Services\Ops\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * /health/live and /health/ready.
 *
 * LIVENESS MUST NOT TOUCH A DEPENDENCY
 * A liveness probe that queries the database reports the DATABASE's health as the
 * process's, and a load balancer then removes healthy containers whenever the
 * database blips. The second test breaks the default connection outright and still
 * expects live() to answer, which is the whole point of separating the two probes.
 *
 * READINESS MUST NOT LEAK
 * The reason codes come from a closed snake_case vocabulary, and the serialised
 * payload must not contain a DSN, a host, a password or a driver name.
 */
final class HealthCheckServiceTest extends TestCase
{
    use DatabaseTruncation;

    public function test_liveness_answers_up_with_a_timestamp(): void
    {
        $result = app(HealthCheckService::class)->live();

        $this->assertSame('UP', $result['status']);
        $this->assertArrayHasKey('timestamp', $result);
    }

    public function test_liveness_does_not_touch_the_database(): void
    {
        // Point the default connection at a closed port. If live() touches it, this
        // call throws and the test fails - which is exactly the regression guard.
        config(['database.connections.broken_probe' => [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 1,
            'database' => 'nope',
            'username' => 'nope',
            'password' => 'nope',
        ]]);
        config(['database.default' => 'broken_probe']);

        try {
            $result = app(HealthCheckService::class)->live();

            $this->assertSame('UP', $result['status']);
        } finally {
            // Restore the connection before PHPUnit inspects the handler stack: a
            // deliberately broken DSN is a fixture, not a leftover.
            config(['database.default' => 'sqlite']);
            \Illuminate\Support\Facades\DB::purge('broken_probe');
            restore_error_handler();
            restore_exception_handler();
        }
    }

    public function test_readiness_reports_a_named_check_per_dependency(): void
    {
        $result = app(HealthCheckService::class)->ready();

        $this->assertArrayHasKey('ready', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertContains($result['status'], ['READY', 'NOT_READY']);

        foreach (['database', 'cache', 'queue', 'scheduler'] as $key) {
            $this->assertArrayHasKey($key, $result['checks'], 'Readiness must report the '.$key.' check.');
        }

        foreach ($result['checks'] as $name => $check) {
            $this->assertIsBool($check['ok'], $name.' check must carry a boolean verdict.');

            if ($check['reason'] !== null) {
                $this->assertMatchesRegularExpression(
                    '/^[a-z0-9_]+$/',
                    (string) $check['reason'],
                    'A readiness reason must be a closed snake_case token, never free prose.',
                );
            }
        }
    }

    public function test_readiness_is_not_ready_when_no_heartbeat_has_ever_been_written(): void
    {
        // A missing heartbeat is not evidence that the scheduler is running.
        $this->assertNull(app(SchedulerHeartbeat::class)->read());

        $result = app(HealthCheckService::class)->ready();

        $this->assertFalse($result['checks']['scheduler']['ok']);
        $this->assertSame('scheduler_heartbeat_missing', $result['checks']['scheduler']['reason']);
        $this->assertSame('NOT_READY', $result['status']);
    }

    public function test_readiness_is_ready_on_the_scheduler_check_after_a_beat(): void
    {
        app(SchedulerHeartbeat::class)->beat();

        $result = app(HealthCheckService::class)->ready();

        $this->assertTrue(
            $result['checks']['scheduler']['ok'],
            'A heartbeat written a moment ago must satisfy the scheduler check.',
        );
        $this->assertNull($result['checks']['scheduler']['reason']);
    }

    public function test_the_heartbeat_command_writes_the_stamp_the_probe_reads(): void
    {
        $this->assertNull(app(SchedulerHeartbeat::class)->read());

        $this->artisan('ops:heartbeat')->assertSuccessful();

        $stamp = app(SchedulerHeartbeat::class)->read();

        $this->assertNotNull($stamp);
        $this->assertArrayHasKey('at', $stamp);
    }

    public function test_a_stale_heartbeat_is_reported_stale(): void
    {
        app(SchedulerHeartbeat::class)->beat(now()->subHours(4));

        $result = app(HealthCheckService::class)->ready();

        $this->assertFalse($result['checks']['scheduler']['ok']);
        $this->assertSame('scheduler_heartbeat_stale', $result['checks']['scheduler']['reason']);
    }

    public function test_the_payload_never_echoes_a_secret_or_a_host(): void
    {
        $json = json_encode(app(HealthCheckService::class)->ready(), JSON_THROW_ON_ERROR);

        foreach (['password', 'secret', 'APP_KEY', 'mysql://', 'redis://', '127.0.0.1'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $json);
        }
    }

    public function test_readiness_does_not_write_business_rows(): void
    {
        $before = \App\Models\FinancialTransaction::query()->count();

        app(HealthCheckService::class)->ready();
        app(HealthCheckService::class)->ready();

        $this->assertSame(
            $before,
            \App\Models\FinancialTransaction::query()->count(),
            'The probe runs on every load-balancer tick; it must not write business rows.',
        );
    }
}
