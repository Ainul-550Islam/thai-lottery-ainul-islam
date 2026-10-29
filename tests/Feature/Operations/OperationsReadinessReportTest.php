<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Services\Operations\ProviderReadinessService;
use App\Services\Operations\ScheduleCoverageService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Part 63 — what is actually configured, and what actually runs.
 *
 * WHAT IS BEING PROVEN
 *  1. A lane with its switch off is reported NOT_CONFIGURED, and the report
 *     names the switch that decided it. Nothing is invented to fill the gap.
 *  2. A switch flipped on with no credentials behind it is reported
 *     ENABLED_WITHOUT_CREDENTIALS — the state that fails at the moment a player
 *     uses it, and the one a readiness report exists to catch.
 *  3. A lane whose config keys do not exist at all is reported
 *     LANE_NOT_DECLARED, which is a different answer from "empty".
 *  4. The schedule report measures what this application scheduled at boot, and
 *     shows that commands which exist are not all commands that run — including
 *     Part 62's own `settlement:verify-guards`.
 *  5. The command runs, and exits non-zero precisely because something needs
 *     action, not because it crashed.
 *
 * WHAT IS NOT CLAIMED
 * None of this proves a deployed host runs cron, that a queue worker is alive,
 * or that any external provider works. Those are deployment facts this suite
 * cannot see, and it does not pretend to.
 */
final class OperationsReadinessReportTest extends TestCase
{
    /*
     * No DatabaseTruncation here, deliberately. These tests read configuration
     * and the console schedule; they touch no table. The truncation trait also
     * leaves the harness's Schedule singleton without its events, which would
     * make the schedule measurement below report an empty schedule and prove
     * nothing about the application.
     */

    private function readiness(): ProviderReadinessService
    {
        return app(ProviderReadinessService::class);
    }

    #[Test]
    public function every_declared_lane_is_reported_with_the_switch_that_decided_it(): void
    {
        $lanes = $this->readiness()->lanes();

        $this->assertArrayHasKey('payment.bkash', $lanes);
        $this->assertArrayHasKey('captcha.turnstile', $lanes);
        $this->assertArrayHasKey('payment.promptpay', $lanes);

        $bkash = $lanes['payment.bkash'];

        $this->assertSame(ProviderReadinessService::NOT_CONFIGURED, $bkash['state']);
        $this->assertSame('payment.gateways.bkash.enabled', $bkash['switch']);
        $this->assertFalse($bkash['enabled']);
        $this->assertNotEmpty($bkash['missing'], 'the report names which credentials are absent');
        $this->assertNotSame('', $bkash['note']);
    }

    #[Test]
    public function a_switch_on_with_no_credentials_is_the_dangerous_state(): void
    {
        config(['payment.gateways.bkash.enabled' => true]);

        $lane = $this->readiness()->lane('payment.bkash');

        $this->assertSame(ProviderReadinessService::ENABLED_WITHOUT_CREDENTIALS, $lane['state']);
        $this->assertTrue($lane['enabled']);
        $this->assertContains('payment.gateways.bkash.app_key', $lane['missing']);

        $this->assertArrayHasKey('payment.bkash', $this->readiness()->findings());
    }

    #[Test]
    public function credentials_cannot_make_an_unverified_bkash_callback_implementation_ready(): void
    {
        config([
            'payment.gateways.bkash.enabled' => true,
            'payment.gateways.bkash.app_key' => 'test-app-key',
            'payment.gateways.bkash.app_secret' => 'test-app-secret',
            'payment.gateways.bkash.username' => 'test-user',
            'payment.gateways.bkash.password' => 'test-password',
            'payment.gateways.bkash.base_url' => 'https://tokenized.sandbox.bka.sh',
        ]);

        $lane = $this->readiness()->lane('payment.bkash');

        $this->assertSame(ProviderReadinessService::IMPLEMENTATION_INCOMPLETE, $lane['state']);
        $this->assertSame([], $lane['missing']);
        $this->assertArrayHasKey('payment.bkash', $this->readiness()->findings());
        $this->assertStringContainsString('callback authentication', $lane['note']);
    }

    #[Test]
    public function a_lane_whose_keys_do_not_exist_is_not_the_same_as_an_empty_one(): void
    {
        $lane = $this->readiness()->lane('otp.account');

        $this->assertSame(ProviderReadinessService::LANE_NOT_DECLARED, $lane['state']);
        $this->assertSame(['account.otp'], $lane['undeclared']);

        $emptyLane = $this->readiness()->lane('captcha.hcaptcha');
        $this->assertSame([], $emptyLane['undeclared'], 'these keys do exist; they are simply empty');
        $this->assertSame(ProviderReadinessService::NOT_CONFIGURED, $emptyLane['state']);
    }

    #[Test]
    public function an_unknown_lane_is_reported_rather_than_throwing(): void
    {
        $lane = $this->readiness()->lane('payment.does_not_exist');

        $this->assertSame(ProviderReadinessService::LANE_NOT_DECLARED, $lane['state']);
        $this->assertSame(['payment.does_not_exist'], $lane['undeclared']);
    }

    #[Test]
    public function the_summary_counts_add_up_to_the_number_of_lanes(): void
    {
        $summary = $this->readiness()->summary();
        $total = array_sum($summary);

        $this->assertSame(count($this->readiness()->lanes()), $total);
        $this->assertGreaterThanOrEqual(1, $summary['lane_not_declared'], 'the OTP lane has no configuration at all');
    }

    #[Test]
    public function scheduled_command_names_are_parsed_and_scheduled_closures_are_skipped(): void
    {
        $schedule = new Schedule;
        $schedule->command('lottery:tick')->daily();
        $schedule->command('risk:provision-number-limits --open --check')->hourly();
        $schedule->call(static fn (): bool => true)->daily()->name('a-scheduled-closure');

        $service = new ScheduleCoverageService($schedule);

        $this->assertSame(
            ['lottery:tick', 'risk:provision-number-limits'],
            $service->scheduledCommands(),
            'a scheduled closure has no command name and must be skipped, not fatal',
        );
    }

    #[Test]
    public function the_real_schedule_runs_lottery_tick(): void
    {
        // Read through the product's own entry point, so the answer does not
        // depend on what else ran earlier in this PHPUnit process.
        Artisan::call('schedule:list', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);
        $events = is_array($decoded) && isset($decoded['events']) && is_array($decoded['events'])
            ? $decoded['events']
            : (is_array($decoded) ? $decoded : []);

        // `schedule:list --json` reports a command event as the whole command
        // line ("php artisan lottery:tick") and a scheduled closure by its name
        // ("player-protection-self-exclusion-expiry"), so the assertion is made
        // on the extracted artisan name rather than on the raw string.
        $names = [];

        foreach ($events as $event) {
            $line = (string) ($event['command'] ?? '');

            if (preg_match('/([a-z][a-z0-9]*(?::[a-z0-9\-_]+)+)/i', $line, $matches) === 1) {
                $names[] = $matches[1];

                continue;
            }

            $names[] = $line;
        }

        $this->assertNotEmpty($names, 'the schedule reported nothing at all');
        $this->assertContains('lottery:tick', $names, 'draw automation really is scheduled');
        $this->assertContains(
            'player-protection-self-exclusion-expiry',
            $names,
            'the self-exclusion expiry sweep is scheduled as a closure',
        );
        // Part 64 scheduled the guard, so the Part 63 finding is now closed and
        // this assertion records the new state rather than the old one.
        $this->assertContains('settlement:verify-guards', $names);
    }

    #[Test]
    public function the_coverage_report_inventories_the_applications_own_commands(): void
    {
        $report = app(ScheduleCoverageService::class)->report();

        $this->assertNotEmpty($report['application_commands']);
        $this->assertContains('settlement:verify-guards', $report['application_commands']);
        $this->assertNotEmpty($report['unscheduled'], 'commands that exist but nobody runs');
        /*
         * No assertion on required_but_missing here. That list is computed from
         * the Schedule singleton, which is empty in a test process unless the
         * console kernel has booted in it, so it would report every required
         * command as missing no matter what bootstrap/app.php says. The
         * authoritative read is `schedule:list`, and
         * SettlementGuardsScheduledTest makes that assertion instead.
         */
        $this->assertIsArray($report['required_but_missing']);
        $this->assertStringContainsString('application commands are scheduled', $report['coverage']);
    }

    #[Test]
    public function the_command_runs_and_exits_non_zero_because_something_needs_action(): void
    {
        $this->artisan('operations:readiness-report')
            ->expectsOutputToContain('Provider lanes')
            ->expectsOutputToContain('Schedule coverage')
            ->expectsOutputToContain('otp.account')
            ->assertExitCode(1);
    }

    #[Test]
    public function the_command_can_report_only_the_lanes(): void
    {
        $this->artisan('operations:readiness-report', ['--lanes' => true])
            ->expectsOutputToContain('Provider lanes')
            ->assertExitCode(1);
    }
}
