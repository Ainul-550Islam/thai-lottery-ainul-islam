<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\FinancialException;
use App\Services\Compliance\MoneyOperationControlService;
use App\Services\Compliance\RealMoneyTechnicalReadinessService;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan money:technical-readiness` — a READ-ONLY audit of the money path.
 *
 * WHAT IT IS
 * ----------
 * One console entry point over `RealMoneyTechnicalReadinessService`, printed in
 * the same shape and with the same exit-code contract as `ops:preflight`, so an
 * operator or a deploy script that already reads one can read the other. It owns
 * no rule of its own: every verdict is the service's, exactly as
 * `PaymentsReadinessCommand` owns no rule and delegates to
 * `ProviderReadinessService` and `ThaiRailsChecks`. Two commands that could
 * disagree about the same deployment would be a second opinion nobody asked for.
 *
 * READ-ONLY, AND WHY THAT IS THE POINT
 * ------------------------------------
 * This command writes nothing: no row, no cache entry, no file, no log line, no
 * audit entry. It contacts no provider and performs no network I/O. It may
 * therefore be run against a production database at any moment — before a
 * release, during an incident, on a schedule, twice in a row — and the only thing
 * it can change is what the operator knows. A readiness audit that writes cannot
 * be run twice without changing what it measures, and cannot be run against
 * production at all without somebody having to reason about its side effects
 * first.
 *
 * THE EXIT CODES ARE THE CONTRACT
 * -------------------------------
 *   0  no blocking failure
 *   1  at least one blocking failure
 *   2  warnings only, and `--strict` was passed
 *
 * WHAT A CHECK MAY PRINT
 * ----------------------
 * Every line describes the SHAPE of what was found ("a usable 32-byte key",
 * "27 chart-of-accounts codes seeded", "no payment gateway is enabled"). No
 * credential, no key material, no account number and no raw configuration value
 * is ever interpolated: these lines end up in CI transcripts, deploy logs and
 * incident tickets.
 *
 * A CLOSED GATE IS NOT A FAILING GATE
 * -----------------------------------
 * While `RealMoneyGate::isOpen()` is false, the checks that only matter once
 * money can move are reported SKIP. A deployment that has not launched gets an
 * honest "nothing here is broken, and nothing here was checked either" rather
 * than a wall of red it has to learn to ignore — and a wall of red that is
 * ignored is how the one genuine FAIL gets missed.
 */
final class MoneyTechnicalReadinessCommand extends Command
{
    protected $signature = 'money:technical-readiness
        {--json : Emit machine-readable JSON (for deploy scripts and CI)}
        {--operation= : Scope the verdict to one money operation (deposit, withdrawal, bet, payout, conversion)}
        {--all-operations : Report the scoped verdict for every known operation}
        {--controls : Print the operation control surface instead of running the checks}
        {--strict : Treat warnings as failures}';

    protected $description = 'Audit the technical readiness of the real-money path without writing, and without contacting any provider.';

    public function handle(
        RealMoneyTechnicalReadinessService $readiness,
        MoneyOperationControlService $controls,
    ): int {
        $json = (bool) $this->option('json');

        // ---------------------------------------------------------------
        // --controls: the control surface, not a verdict. Printed without
        // running a single check, so it is safe to read on any box at any
        // time and answers "what WOULD be enforced here".
        // ---------------------------------------------------------------
        if ($this->option('controls')) {
            $operation = $this->option('operation');
            $operation = is_string($operation) && trim($operation) !== '' ? trim($operation) : null;

            // `controls()` is a read, so it REPORTS an unknown operation
            // (`known: false`) instead of throwing. That is right for a caller
            // holding the array and wrong for an operator at a console: printing
            // an empty control surface for `--operation=deposits` looks like an
            // answer. Refuse the same way the verdict path does, so a typo is a
            // typo whichever flag it arrives with.
            if ($operation !== null && ! in_array(strtolower($operation), RealMoneyTechnicalReadinessService::OPERATIONS, true)) {
                return $this->refuseUnknownOperation($json, FinancialException::withCode(
                    'real_money_operation_unknown',
                    trans('real_money.technical.operation_unknown', ['operation' => $operation]),
                    ['operation' => $operation, 'known' => implode(', ', RealMoneyTechnicalReadinessService::OPERATIONS)],
                ));
            }

            $surface = $controls->controls($operation);

            if ($json) {
                $this->line((string) json_encode($surface, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }

            $this->printControls($surface);

            return self::SUCCESS;
        }

        // ---------------------------------------------------------------
        // --all-operations: one scoped verdict per operation. Useful before a
        // launch, when the question is not "is the platform ready" but "which
        // operations may we turn on".
        // ---------------------------------------------------------------
        if ($this->option('all-operations')) {
            return $this->allOperations($readiness, $json);
        }

        $operation = $this->option('operation');
        $operation = is_string($operation) && trim($operation) !== '' ? trim($operation) : null;

        try {
            $report = $readiness->report($operation);
        } catch (FinancialException $e) {
            return $this->refuseUnknownOperation($json, $e);
        } catch (Throwable) {
            // Fail closed on doubt: a probe that dies must not exit 0, or a
            // deploy gate reading this command would treat a crash as a pass.
            return $this->refuseProbeFailure($json);
        }

        if ($json) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->printReport($report);
        }

        $blocking = count($report['blocking']);

        if ($blocking > 0) {
            if (! $json) {
                $this->components->error(sprintf(
                    'Money technical readiness FAILED: %d blocking problem(s)%s. Real money must not be opened until they are resolved.',
                    $blocking,
                    $operation === null ? '' : ' for the '.$operation.' operation',
                ));
            }

            return self::FAILURE;
        }

        if ($this->option('strict') && $report['summary']['warn'] > 0) {
            if (! $json) {
                $this->components->warn(sprintf(
                    'Money technical readiness produced %d warning(s) and --strict was passed.',
                    $report['summary']['warn'],
                ));
            }

            return 2;
        }

        if (! $json) {
            $this->components->info($report['real_money_enabled']
                ? 'Money technical readiness passed: real money is OPEN and every evaluated check is satisfied.'
                : 'Money technical readiness passed: real money is CLOSED, and nothing evaluated here is broken.');
        }

        return self::SUCCESS;
    }

    // =====================================================================
    // Printing. Presentation only — no verdict is decided here.
    // =====================================================================

    /**
     * @param  array<string, mixed>  $report
     */
    private function printReport(array $report): void
    {
        $this->newLine();
        $this->line(sprintf(
            '  <fg=cyan>Money technical readiness</> — environment <comment>%s</comment>, real money <comment>%s</comment>%s',
            (string) $report['environment'],
            $report['real_money_enabled'] === true ? 'OPEN' : 'CLOSED',
            is_string($report['operation'] ?? null) ? ', operation <comment>'.$report['operation'].'</comment>' : '',
        ));
        $this->newLine();

        foreach ((array) $report['checks'] as $check) {
            $status = (string) ($check['status'] ?? 'SKIP');
            $badge = match ($status) {
                'PASS' => '<fg=green>PASS</>',
                'WARN' => '<fg=yellow>WARN</>',
                'FAIL' => '<fg=red>FAIL</>',
                default => '<fg=gray>SKIP</>',
            };

            $this->line(sprintf(
                '  %s  %-36s %s',
                $badge,
                (string) ($check['id'] ?? ''),
                (string) ($check['detail'] ?? ''),
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            '  %d pass, %d warn, %d fail, %d skipped',
            (int) $report['summary']['pass'],
            (int) $report['summary']['warn'],
            (int) $report['summary']['fail'],
            (int) $report['summary']['skip'],
        ));

        $blocking = (array) $report['blocking'];
        if ($blocking !== []) {
            $this->newLine();
            $this->line('  <fg=red>Blocking:</>');
            foreach ($blocking as $id => $detail) {
                $this->line(sprintf('    - %s: %s', (string) $id, (string) $detail));
            }
        }
    }

    /**
     * @param  array<string, mixed>  $surface
     */
    private function printControls(array $surface): void
    {
        $this->newLine();
        $this->line(sprintf(
            '  <fg=cyan>Money operation controls</>%s',
            is_string($surface['operation'] ?? null) ? ' — operation <comment>'.$surface['operation'].'</comment>' : ' — all operations',
        ));
        $this->newLine();

        $this->line('  <fg=white>Modes</> (report = recorded only, enforce = refuses)');
        foreach ((array) $surface['modes'] as $control => $mode) {
            $badge = $mode === MoneyOperationControlService::MODE_ENFORCE
                ? '<fg=red>enforce</>'
                : '<fg=yellow>report </>';
            $this->line(sprintf('    %s  %s', $badge, (string) $control));
        }

        $this->newLine();
        $this->line('  <fg=white>Amount bounds</> (read from existing config, never invented here)');
        $bounds = (array) $surface['bounds'];
        $sources = (array) $surface['bound_sources'];
        foreach (['min', 'max'] as $side) {
            $value = $bounds[$side] ?? null;
            $source = $sources[$side] ?? null;
            $this->line(sprintf(
                '    %-4s %s%s',
                $side,
                is_string($value) ? $value : '<fg=gray>unbounded</>',
                is_string($source) ? '  <fg=gray>(from '.$source.')</>' : '',
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            '  <fg=white>Provider lane</>: %s',
            $surface['lane_required'] === true ? 'required for this operation' : 'not required for this operation',
        ));

        $required = (array) $surface['required_checks'];
        $this->newLine();
        $this->line(sprintf('  <fg=white>Deciding checks</> (%d)', count($required)));
        foreach ($required as $id) {
            $this->line(sprintf('    - %s', (string) $id));
        }
    }

    private function allOperations(RealMoneyTechnicalReadinessService $readiness, bool $json): int
    {
        $verdicts = [];
        $worst = self::SUCCESS;

        foreach (RealMoneyTechnicalReadinessService::OPERATIONS as $operation) {
            try {
                $report = $readiness->report($operation);
            } catch (Throwable) {
                return $this->refuseProbeFailure($json);
            }

            $blocking = count($report['blocking']);
            $verdicts[$operation] = [
                'operation' => $operation,
                'ready' => $blocking === 0,
                'blocking' => $report['blocking'],
                'summary' => $report['summary'],
            ];

            if ($blocking > 0) {
                $worst = self::FAILURE;
            } elseif ($this->option('strict') && (int) $report['summary']['warn'] > 0 && $worst === self::SUCCESS) {
                $worst = 2;
            }
        }

        if ($json) {
            $this->line((string) json_encode([
                'real_money_enabled' => $readiness->report()['real_money_enabled'],
                'operations' => $verdicts,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $worst;
        }

        $this->newLine();
        $this->line('  <fg=cyan>Money technical readiness</> — per operation');
        $this->newLine();

        foreach ($verdicts as $operation => $verdict) {
            $badge = $verdict['ready'] === true ? '<fg=green>READY  </>' : '<fg=red>NOT READY</>';
            $this->line(sprintf(
                '  %s  %-12s %d blocking, %d warn, %d skip',
                $badge,
                (string) $operation,
                count((array) $verdict['blocking']),
                (int) $verdict['summary']['warn'],
                (int) $verdict['summary']['skip'],
            ));

            foreach ((array) $verdict['blocking'] as $id => $detail) {
                $this->line(sprintf('           <fg=gray>-</> %s: %s', (string) $id, (string) $detail));
            }
        }

        $this->newLine();

        return $worst;
    }

    // =====================================================================
    // Refusals. Every one of these exits non-zero: a command that cannot
    // answer must never look like a command that answered "ready".
    // =====================================================================

    private function refuseUnknownOperation(bool $json, FinancialException $e): int
    {
        $known = $e->context()['known'] ?? null;

        $detail = sprintf(
            'Unknown operation "%s". Known operations: %s.',
            (string) ($e->context()['operation'] ?? ''),
            is_string($known) ? $known : implode(', ', RealMoneyTechnicalReadinessService::OPERATIONS),
        );

        if ($json) {
            $this->line((string) json_encode([
                'status' => 'FAIL',
                'ready' => false,
                'checks' => [[
                    'id' => 'real_money.operation',
                    'status' => 'FAIL',
                    'detail' => $detail,
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->components->error($detail);
        }

        return self::FAILURE;
    }

    private function refuseProbeFailure(bool $json): int
    {
        $detail = 'The readiness probe could not complete. An unanswered audit is not a passing audit.';

        if ($json) {
            $this->line((string) json_encode([
                'status' => 'FAIL',
                'ready' => false,
                'checks' => [[
                    'id' => 'real_money.probe',
                    'status' => 'FAIL',
                    'detail' => $detail,
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->components->error($detail);
        }

        return self::FAILURE;
    }
}
