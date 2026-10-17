<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

/**
 * Which of this application's own commands are actually on the schedule?
 *
 * THE GAP THIS NAMES
 * A command that exists and a command that runs are different things. The
 * register has said "a scheduled command's presence is not evidence of
 * production execution" for several parts, and until now that was an assertion
 * rather than a measurement. This service measures it: it reads the schedule the
 * application actually built at boot, compares it with the commands the
 * application registers, and names both lists.
 *
 * WHAT IT CANNOT SEE
 * It reports the schedule as this process built it. It cannot tell you whether
 * the deployed host runs `schedule:run` every minute, whether cron is installed,
 * or whether a queue worker is alive. Those are deployment facts, and this
 * service does not pretend to know them.
 */
final class ScheduleCoverageService
{
    /**
     * The schedule is resolved lazily rather than injected and held. Holding an
     * instance is a trap here: the console kernel (re)binds the Schedule
     * singleton when it boots, which happens the first time anything asks for
     * the command list — so an instance captured before that moment can be a
     * different, empty one. Asking the container at read time always returns
     * the schedule this application actually built.
     */
    /**
     * The schedule is resolved at read time by default. Injecting and holding it
     * is a trap, and it was measured rather than guessed: an instance captured
     * through the constructor had 0 events while `app(Schedule::class)` in the
     * same request had 31, because the console kernel rebinds the Schedule
     * singleton when it boots. A coverage report built on the wrong instance
     * reports an empty schedule and proves nothing.
     *
     * An explicit instance is still accepted, so the parsing can be tested
     * against a schedule built in the test rather than against whatever state
     * the process happens to be in.
     */
    public function __construct(
        private readonly ?Schedule $schedule = null,
    ) {}

    private function schedule(): Schedule
    {
        return $this->schedule ?? app(Schedule::class);
    }

    /**
     * Command names appearing on the schedule, sorted and deduplicated.
     *
     * @return list<string>
     */
    public function scheduledCommands(): array
    {
        $names = [];

        foreach ($this->schedule()->events() as $event) {
            if (! $event instanceof Event) {
                continue;
            }

            // Null-coalesced on purpose: a CallbackEvent (a scheduled closure)
            // declares no command, and touching the property directly turns a
            // deprecation into an exception under the test error handler.
            $name = $this->commandName((string) ($event->command ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * Commands owned by this application (not framework commands), by name.
     *
     * @return list<string>
     */
    public function applicationCommands(): array
    {
        $names = [];

        foreach (Artisan::all() as $name => $command) {
            if (! $command instanceof Command) {
                continue;
            }

            if (! str_starts_with($command::class, 'App\\Console\\Commands')) {
                continue;
            }

            $names[] = (string) $name;
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * @return array{scheduled: list<string>, application_commands: list<string>, unscheduled: list<string>, required_but_missing: list<string>, coverage: string}
     */
    public function report(): array
    {
        $scheduled = $this->scheduledCommands();
        $application = $this->applicationCommands();

        $unscheduled = array_values(array_diff($application, $scheduled));

        $required = array_values(array_map(
            'strval',
            (array) config('operations-readiness.required_scheduled_commands', []),
        ));

        $requiredButMissing = array_values(array_diff($required, $scheduled));

        $coverage = $application === []
            ? 'no application commands registered'
            : count(array_intersect($application, $scheduled)).' of '.count($application).' application commands are scheduled';

        return [
            'scheduled' => $scheduled,
            'application_commands' => $application,
            'unscheduled' => $unscheduled,
            'required_but_missing' => $requiredButMissing,
            'coverage' => $coverage,
        ];
    }

    /**
     * Pull the command name out of a schedule event's command string, which
     * arrives shaped like `'/usr/bin/php' 'artisan' lottery:tick --open`.
     */
    private function commandName(string $command): string
    {
        if (preg_match('/(?:^|\s)([a-z][a-z0-9]*(?::[a-z0-9\-_]+)+)(?:\s|$)/i', $command, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }
}
