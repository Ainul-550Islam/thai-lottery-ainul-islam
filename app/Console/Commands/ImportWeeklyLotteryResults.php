<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Lottery\WeeklyLotteryImportService;
use App\Services\Lottery\WeeklyLotterySourceService;
use App\Services\Lottery\WeeklyResultIntegrityService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import, replay or dry-run one Weekly Lottery result (PROMPT 6, file 29).
 *
 * MODELLED ON THE EXISTING glo:import-result AND national-lottery:import
 * COMMANDS, on purpose: same option shape, same --json report, same exit-code
 * discipline. An operator who already runs one does not have to learn a
 * second convention, and a scheduler wrapper written for one works for the
 * others.
 *
 * THE PAYLOAD COMES FROM A FILE OR FROM EXPLICIT OPTIONS - NEVER FROM NOTHING.
 * There is no mode in which this command invents a draw or a number. --file
 * reads a JSON document; the individual options let an operator restate a
 * payload by hand. Either way the values are validated against
 * config('weekly_lottery.fields') and canonicalized by the integrity service
 * before anything is written.
 *
 * A MISSING RESULT IS IMPORTABLE, EXPLICITLY. Running with --date and no
 * number options records a draw whose numbers are unavailable. That is a real
 * state the reference product exhibits, and it is stored as NULLs with
 * result_status = unavailable - never as 000000 / 000 / 00.
 *
 * EXIT CODES
 *   0  imported, duplicate, or a successful dry run. A duplicate is a correct
 *      no-op, and a scheduler retrying after a timeout must not be told it
 *      failed.
 *   1  validation or provider error (bad payload, unknown provider,
 *      unconfigured provider, integrity refusal).
 *   2  CONFLICT. Never 0: a conflict needs a human, and a pipeline that reads
 *      exit codes must be able to stop on it.
 *
 * NOTHING SENSITIVE IS PRINTED. The command prints the provider, the source
 * state, the draw reference, the version and the integrity status. It never
 * prints a token, an endpoint URL, a stack trace or a driver message; a
 * failure prints the service's generic message and the exception goes to the
 * application's normal reporting channel.
 */
class ImportWeeklyLotteryResults extends Command
{
    /**
     * @var string
     */
    protected $signature = 'weekly-lottery:import
        {--provider=official : Source provider lane (official, internal, replay, fixture)}
        {--file= : Path to a JSON payload file}
        {--date= : Draw date, in one of the configured accepted formats}
        {--draw= : Draw reference to target; must agree with --date when both are given}
        {--first-6= : Six-digit 6Ball, leading zeros included}
        {--three-ball= : Three-digit 3Ball}
        {--two-ball= : Two-digit 2Ball}
        {--source-identifier= : The provider reference for this delivery}
        {--signature= : Hex Ed25519 signature over the canonical bytes, when a signed provider exists}
        {--actor= : User id to record as the importer}
        {--no-publish : Store the version without publishing it}
        {--dry-run : Validate and fingerprint only; write nothing}
        {--json : Emit a machine-readable report instead of a table}';

    /**
     * @var string
     */
    protected $description = 'Import one Weekly Lottery draw result with its provenance and integrity recorded.';

    public function handle(
        WeeklyLotteryImportService $importer,
        WeeklyLotterySourceService $sources,
        WeeklyResultIntegrityService $integrity,
    ): int {
        $provider = (string) $this->option('provider');

        if (! in_array($provider, $sources->priority(), true)) {
            return $this->reportFailure(
                'UNKNOWN_PROVIDER',
                'Unknown provider: use one of '.implode(', ', $sources->priority()).'.',
            );
        }

        try {
            $payload = $this->buildPayload();
        } catch (Throwable $exception) {
            report($exception);

            return $this->reportFailure('PAYLOAD_UNREADABLE', 'The payload could not be read.');
        }

        if ($payload === null) {
            return $this->reportFailure(
                'PAYLOAD_MISSING',
                'Provide --file, or at least --date.',
            );
        }

        $drawOption = $this->option('draw');

        if (is_string($drawOption) && $drawOption !== '') {
            // A stated reference must AGREE with the date rather than override
            // it. Letting an operator point a payload at a different draw by
            // hand is exactly how the wrong numbers get published.
            $payload['expected_draw_reference'] = $drawOption;
        }

        $actorOption = $this->option('actor');
        $actorId = is_string($actorOption) && preg_match('/^[0-9]{1,18}$/', $actorOption) === 1
            ? (int) $actorOption
            : null;

        $dryRun = (bool) $this->option('dry-run');

        if (! (bool) $this->option('json')) {
            $this->line(sprintf(
                'Integrity verifier: %s',
                $integrity->isNativeVerifierAvailable()
                    ? 'native (Rust) available'
                    : 'native binary not found; PHP canonicalizer only',
            ));
        }

        $outcome = $importer->import(
            payload: $payload,
            provider: $provider,
            actorId: $actorId,
            autoPublish: ! (bool) $this->option('no-publish'),
            dryRun: $dryRun,
        );

        $this->report($outcome);

        return match ($outcome['status']) {
            WeeklyLotteryImportService::STATUS_IMPORTED,
            WeeklyLotteryImportService::STATUS_DUPLICATE => self::SUCCESS,
            WeeklyLotteryImportService::STATUS_CONFLICT => 2,
            default => self::FAILURE,
        };
    }

    /**
     * Assemble the payload from --file or from the explicit options.
     *
     * Values are passed through UNCHANGED. The command does not pad, trim to
     * width, reformat or reorder anything: '049' stays '049', and a malformed
     * value is left malformed so the import service can reject it rather than
     * having it quietly repaired here.
     *
     * An omitted number option is an ABSENCE, not a zero. Omitting all three
     * records an unavailable result, which is a real state.
     *
     * @return array<string, mixed>|null
     */
    private function buildPayload(): ?array
    {
        $file = $this->option('file');

        if (is_string($file) && $file !== '') {
            if (! is_file($file) || ! is_readable($file)) {
                return null;
            }

            $contents = file_get_contents($file);

            if ($contents === false) {
                return null;
            }

            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        }

        $date = $this->option('date');

        if (! is_string($date) || $date === '') {
            return null;
        }

        return [
            'draw_date' => $date,
            'first_6' => $this->stringOption('first-6'),
            'three_ball' => $this->stringOption('three-ball'),
            'two_ball' => $this->stringOption('two-ball'),
            'source_identifier' => $this->stringOption('source-identifier'),
            'signature_hex' => $this->stringOption('signature'),
            'retrieved_at' => now()->toIso8601String(),
        ];
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $outcome
     */
    private function report(array $outcome): void
    {
        if ((bool) $this->option('json')) {
            $json = json_encode($outcome, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->line(is_string($json) ? $json : '{}');

            return;
        }

        $integrity = is_array($outcome['integrity'] ?? null) ? $outcome['integrity'] : [];

        $this->table(
            ['Field', 'Value'],
            [
                ['status', (string) $outcome['status']],
                ['draw', (string) ($outcome['draw_reference'] ?? '-')],
                ['version', $outcome['version'] === null ? '-' : (string) $outcome['version']],
                ['source_state', (string) $outcome['source_state']],
                ['result_status', (string) ($outcome['result_status'] ?? '-')],
                ['published', ((bool) $outcome['published']) ? 'yes' : 'no'],
                ['integrity', (string) ($integrity['status'] ?? '-')],
                ['integrity_native', ((bool) ($integrity['native'] ?? false)) ? 'yes' : 'no'],
                ['fingerprint', (string) ($integrity['fingerprint'] ?? '-')],
                ['errors', $outcome['errors'] === [] ? '-' : implode(', ', $outcome['errors'])],
            ],
        );

        $message = (string) $outcome['message'];

        match ($outcome['status']) {
            WeeklyLotteryImportService::STATUS_IMPORTED => $this->info($message),
            WeeklyLotteryImportService::STATUS_DUPLICATE => $this->line($message),
            WeeklyLotteryImportService::STATUS_CONFLICT => $this->warn($message),
            default => $this->error($message),
        };
    }

    /**
     * Named reportFailure(), not fail(): Illuminate\Console\Command already
     * declares a public fail() and overriding it with a private method of a
     * different signature is a fatal error.
     */
    private function reportFailure(string $code, string $message): int
    {
        if ((bool) $this->option('json')) {
            $json = json_encode([
                'status' => WeeklyLotteryImportService::STATUS_REJECTED,
                'draw_reference' => null,
                'version' => null,
                'source_state' => 'UNAVAILABLE',
                'result_status' => null,
                'published' => false,
                'integrity' => null,
                'errors' => [$code],
                'message' => $message,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            $this->line(is_string($json) ? $json : '{}');
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
