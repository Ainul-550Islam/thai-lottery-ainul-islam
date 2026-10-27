<?php

declare(strict_types=1);

namespace Tests\Feature\Realtime;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `realtime:check` - PASS/FAIL per check, exit 1 on any FAIL, no secret in the output.
 * Network checks are skipped here (--skip-network); the static checks are the ones a deploy
 * gate relies on to catch a log driver, a wildcard origin or a missing channel callback.
 */
final class RealtimeCheckCommandTest extends TestCase
{
    #[Test]
    public function with_realtime_disabled_the_static_checks_pass_and_the_socket_checks_are_skipped(): void
    {
        Config::set('realtime.enabled', false);
        Config::set('broadcasting.default', 'log');

        $this->artisan('realtime:check', ['--skip-network' => true])
            ->expectsOutputToContain('PASS  broadcast.connection')
            ->expectsOutputToContain('PASS  channels.authorized')
            ->expectsOutputToContain('SKIP  reverb.reachable')
            ->expectsOutputToContain('0 failed')
            ->assertExitCode(0);
    }

    #[Test]
    public function a_log_driver_with_realtime_enabled_is_a_failure(): void
    {
        Config::set('realtime.enabled', true);
        Config::set('broadcasting.default', 'log');

        $this->artisan('realtime:check', ['--skip-network' => true])
            ->expectsOutputToContain('FAIL  broadcast.connection')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_wildcard_origin_or_multi_node_without_scaling_fails_in_production(): void
    {
        Config::set('app.env', 'production');
        Config::set('realtime.enabled', false);
        Config::set('reverb.apps.apps.0.allowed_origins', ['*']);
        Config::set('realtime.app_nodes', 3);
        Config::set('reverb.servers.reverb.scaling.enabled', false);
        Config::set('queue.default', 'sync');

        $this->artisan('realtime:check', ['--skip-network' => true])
            ->expectsOutputToContain('FAIL  reverb.allowed_origins')
            ->expectsOutputToContain('FAIL  reverb.scaling')
            ->expectsOutputToContain('FAIL  queue.connection')
            ->assertExitCode(1);

        Config::set('reverb.apps.apps.0.allowed_origins', ['https://example.com']);
        Config::set('reverb.servers.reverb.scaling.enabled', true);
        Config::set('queue.default', 'redis');

        $this->artisan('realtime:check', ['--skip-network' => true])
            ->expectsOutputToContain('PASS  reverb.allowed_origins')
            ->expectsOutputToContain('PASS  reverb.scaling')
            ->expectsOutputToContain('PASS  queue.connection')
            ->assertExitCode(0);
    }

    #[Test]
    public function missing_credentials_fail_and_present_ones_are_never_printed(): void
    {
        Config::set('realtime.enabled', true);
        Config::set('broadcasting.default', 'reverb');
        Config::set('broadcasting.connections.reverb.key', '');
        Config::set('broadcasting.connections.reverb.secret', '');
        Config::set('broadcasting.connections.reverb.app_id', '');

        $this->artisan('realtime:check', ['--skip-network' => true])
            ->expectsOutputToContain('FAIL  broadcast.credentials')
            ->assertExitCode(1);

        Config::set('broadcasting.connections.reverb.key', 'pk-visible-is-fine');
        Config::set('broadcasting.connections.reverb.secret', 'sk-must-never-print');
        Config::set('broadcasting.connections.reverb.app_id', '42');

        $this->artisan('realtime:check', ['--skip-network' => true, '--json' => true])
            ->doesntExpectOutputToContain('sk-must-never-print')
            ->expectsOutputToContain('"broadcast.credentials"')
            ->expectsOutputToContain('"status": "PASS"');
    }

    #[Test]
    public function json_output_lists_every_check_with_a_status(): void
    {
        Config::set('realtime.enabled', false);

        $this->artisan('realtime:check', ['--skip-network' => true, '--json' => true])
            ->expectsOutputToContain('"checks"')
            ->expectsOutputToContain('"id": "channels.authorized"')
            ->expectsOutputToContain('"id": "client.block"')
            ->assertExitCode(0);
    }
}
