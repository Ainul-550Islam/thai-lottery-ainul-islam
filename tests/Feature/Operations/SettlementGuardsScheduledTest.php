<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Services\Operations\ScheduleCoverageService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Part 64 — the guards Part 62 and 63 built are now on the schedule.
 *
 * Part 63 measured that 38 application commands were registered and most were
 * never run, including `settlement:verify-guards`, which
 * config/operations-readiness.php lists as required. That measurement is what
 * this suite pins down: the same read that exposed the gap now shows it closed.
 *
 * WHAT IS NOT PROVEN
 * A schedule entry is not evidence that the deployed host runs `schedule:run`.
 * This suite reads the schedule this application builds; it cannot see cron, a
 * queue worker, or whether the entry ever fires in production.
 */
final class SettlementGuardsScheduledTest extends TestCase
{
    /**
     * The scheduled artisan command names, read through the product's own entry
     * point so the answer does not depend on what else ran in this process.
     *
     * @return list<string>
     */
    private function scheduledNames(): array
    {
        Artisan::call('schedule:list', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);
        $events = is_array($decoded) ? ($decoded['events'] ?? $decoded) : [];

        $names = [];

        foreach ((array) $events as $event) {
            $line = (string) (is_array($event) ? ($event['command'] ?? '') : $event);

            if (preg_match('/([a-z][a-z0-9]*(?::[a-z0-9\-_]+)+)/i', $line, $matches) === 1) {
                $names[] = $matches[1];

                continue;
            }

            $names[] = $line;
        }

        return $names;
    }

    #[Test]
    public function both_guard_commands_are_on_the_schedule(): void
    {
        $names = $this->scheduledNames();

        $this->assertContains('settlement:verify-guards', $names, 'the Part 62 guard is scheduled');
        $this->assertContains('operations:readiness-report', $names, 'the Part 63 readiness report is scheduled');

        // The three that were already scheduled must still be there: adding two
        // entries must not cost an existing control.
        $this->assertContains('lottery:tick', $names);
        $this->assertContains('risk:provision-number-limits', $names);
        $this->assertContains('webhooks:purge-replay-guards', $names);
    }

    #[Test]
    public function every_command_the_configuration_requires_is_now_scheduled(): void
    {
        $names = $this->scheduledNames();
        $required = (array) config('operations-readiness.required_scheduled_commands', []);

        $this->assertNotEmpty($required);

        foreach ($required as $command) {
            $this->assertContains(
                (string) $command,
                $names,
                'config/operations-readiness.php requires '.$command.' to be scheduled',
            );
        }

        // The service still inventories the application's own commands, which is
        // the half of the report that does not depend on schedule state.
        $this->assertContains(
            'settlement:verify-guards',
            app(ScheduleCoverageService::class)->report()['application_commands'],
        );
    }

    #[Test]
    public function the_readiness_command_still_exits_non_zero_for_the_lane_that_needs_action(): void
    {
        // Scheduling the guard does not make the report clean: the OTP lane still
        // has no configuration at all, and that must still fail the exit code.
        $this->artisan('operations:readiness-report')
            ->expectsOutputToContain('Provider lanes')
            ->expectsOutputToContain('otp.account')
            ->assertExitCode(1);
    }

    #[Test]
    public function the_settlement_guard_command_runs_and_reports_when_there_is_nothing_settled(): void
    {
        // The test database is persistent and not reset between runs, so a completed
        // draw committed by another suite would make this precondition false. The
        // test therefore establishes "nothing settled" itself, inside a transaction
        // that is always rolled back, so the shared database is left exactly as found.
        // Foreign keys must be off to clear draws that have children, and SQLite
        // ignores that pragma inside a transaction, so it is set before BEGIN.
        DB::statement('PRAGMA foreign_keys = OFF');

        try {
            DB::beginTransaction();

            try {
                DB::table('draw_results')->whereIn('draw_id', DB::table('draws')->where('status', 'completed')->select('id'))->delete();
                DB::table('draws')->where('status', 'completed')->delete();

                $this->artisan('settlement:verify-guards')
                    ->expectsOutputToContain('No settled draws to verify.')
                    ->assertExitCode(0);
            } finally {
                DB::rollBack();
            }
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
}
