<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Tests\TestCase;

/**
 * What the scheduler is — and is not — told to run for the Thai rails.
 *
 * WHY THIS SUITE EXISTS
 * ThaiRailsServiceProvider::bootSchedule() is the only producer for the two
 * recurring rail tasks: the payout status poller and the TrueMoney
 * reconciler. Both commands and the job itself are fully implemented and
 * covered elsewhere; a suite that only invokes them by hand cannot see that
 * nothing ever calls them, which is exactly how the lanes stayed inert
 * before this batch.
 *
 * The second half is the safety half: the guard is on the LANE FLAG. A
 * deployment that has not turned a rail on must not schedule a task whose
 * only effect is to write to a log or hit a disabled provider. The guard
 * removes the timer, never the tool — both remain runnable by hand.
 */
final class ThaiRailsSchedulerTest extends TestCase
{
    /** @var array<string, bool>|null */
    private ?array $laneFlags = null;

    public function createApplication(): Application
    {
        if ($this->laneFlags === null) {
            return parent::createApplication();
        }

        // Console bootstrap resolves Schedule before a test body runs. Apply
        // the scenario after config loads, but BEFORE providers register timers.
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app->make('config')->set($this->laneFlags);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * @return list<Event>
     */
    private function events(): array
    {
        if ($this->laneFlags === null) {
            $this->laneFlags = [
                'thai_payout.enabled' => (bool) config('thai_payout.enabled'),
                'truemoney.enabled' => (bool) config('truemoney.enabled'),
            ];
            $this->refreshApplication();
        }
        app(Kernel::class)->all();

        return app(Schedule::class)->events();
    }

    private function payoutPollEvent(): Event
    {
        foreach ($this->events() as $event) {
            if (str_contains((string) $event->description, 'Thai bank payout status polls')) {
                return $event;
            }
        }

        $this->fail('The Thai bank payout status poll is not scheduled.');
    }

    private function truemoneyReconcileEvent(): Event
    {
        foreach ($this->events() as $event) {
            if (isset($event->command) && str_contains((string) $event->command, 'truemoney:reconcile')) {
                return $event;
            }
        }

        $this->fail('The TrueMoney reconciler is not scheduled.');
    }

    public function test_the_payout_poll_is_scheduled_when_the_lane_is_enabled(): void
    {
        config(['thai_payout.enabled' => true]);

        $event = $this->payoutPollEvent();

        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping, 'A slow poll must never be re-entered.');
        $this->assertSame(600, $event->expiresAt, 'The overlap window matches the cadence, not a guess.');
        $this->assertTrue($event->onOneServer, 'On a horizontally scaled deployment only one host may poll.');
    }

    public function test_the_payout_poll_is_not_scheduled_when_the_lane_is_disabled(): void
    {
        config(['thai_payout.enabled' => false]);

        foreach ($this->events() as $event) {
            $this->assertStringNotContainsString(
                'Thai bank payout status polls',
                (string) $event->description,
                'A deployment that has not enabled THAI_PAYOUT_ENABLED must schedule no poller.',
            );
        }
    }

    public function test_the_truemoney_reconciler_is_scheduled_when_the_lane_is_enabled(): void
    {
        config(['truemoney.enabled' => true]);

        $event = $this->truemoneyReconcileEvent();

        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping, 'A slow reconciliation must never be re-entered.');
        $this->assertTrue($event->onOneServer, 'Only one host may reconcile the redemptions.');
        $this->assertStringContainsString(
            'Reconcile TrueMoney voucher redemptions',
            (string) $event->description,
        );
    }

    public function test_the_truemoney_reconciler_is_not_scheduled_when_the_lane_is_disabled(): void
    {
        config(['truemoney.enabled' => false]);

        foreach ($this->events() as $event) {
            $command = isset($event->command) ? (string) $event->command : '';

            $this->assertStringNotContainsString(
                'truemoney:reconcile',
                $command,
                'A deployment that has not enabled TRUEMONEY_ENABLED must schedule no reconciler.',
            );
        }
    }

    public function test_disabling_both_rails_registers_nothing_from_this_provider(): void
    {
        config(['thai_payout.enabled' => false, 'truemoney.enabled' => false]);

        $descriptions = [];
        $commands = [];

        foreach ($this->events() as $event) {
            $descriptions[] = (string) $event->description;
            // A callback task carries no command string at all.
            $commands[] = isset($event->command) ? (string) $event->command : '';
        }

        $this->assertNotSame([], $descriptions, 'The application must still declare its other schedule.');

        foreach ($descriptions as $description) {
            $this->assertStringNotContainsString('Thai bank payout status polls', $description);
            $this->assertStringNotContainsString('TrueMoney voucher redemptions', $description);
        }

        foreach ($commands as $command) {
            $this->assertStringNotContainsString('truemoney:reconcile', $command);
        }
    }

    public function test_enabling_the_payout_rail_does_not_schedule_the_voucher_reconciler(): void
    {
        config(['thai_payout.enabled' => true, 'truemoney.enabled' => false]);

        $this->payoutPollEvent();

        foreach ($this->events() as $event) {
            $command = isset($event->command) ? (string) $event->command : '';

            $this->assertStringNotContainsString('truemoney:reconcile', $command);
        }
    }

    public function test_enabling_the_voucher_reconciler_does_not_schedule_the_payout_poll(): void
    {
        config(['thai_payout.enabled' => false, 'truemoney.enabled' => true]);

        $this->truemoneyReconcileEvent();

        foreach ($this->events() as $event) {
            $this->assertStringNotContainsString(
                'Thai bank payout status polls',
                (string) $event->description,
            );
        }
    }
}
