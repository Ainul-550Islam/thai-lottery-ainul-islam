<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Lottery\BingoLotteryImportService;
use App\Services\Lottery\BingoLotterySourceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import, replay or dry-run one Mega Lottery result (PROMPT 8, file 29).
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
 * config('bingo_lottery.fields') and canonicalized by the import service
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
class ImportBingoLotteryResults extends Command
{
    /**
     * @var string
     */
    protected $signature = 'bingo-lottery:import
        {--provider=fixture : Source provider lane (official, internal, replay, fixture)}
        {--file= : Path to a JSON payload file}
        {--date= : Draw date, in one of the configured accepted formats}
        {--draw= : Draw reference to target; must agree with --date when both are given}
        {--first-6-mega= : Six-digit 6 Mega, leading zeros included}
        {--three-mega= : Three-digit 3 Mega}
        {--two-mega= : Two-digit 2 Mega}
        {--source-identifier= : The provider reference for this delivery}
        {--signature= : Hex Ed25519 signature. ACCEPTED BUT NOT VERIFIABLE HERE: this lane has no verifier, so supplying one REFUSES the import rather than passing it unchecked}
        {--actor= : User id to record as the importer}
        {--no-publish : Store the version without publishing it}
        {--dry-run : Validate and fingerprint only; write nothing}
        {--json : Emit a machine-readable report instead of a table}';

    /**
     * @var string
     */
    protected $description = 'Import one Mega Lottery draw result with its provenance and content hash recorded.';

    public function handle(
        BingoLotteryImportService $importer,
        BingoLotterySourceService $sources,
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
            // Stated every run so an operator is never left assuming this lane
            // checks more than it does. The Weekly lane can print something
            // stronger here because it actually runs a separate verifier.
            $this->line('Integrity: PHP canonicalizer only (MEGA1). This lane has no independent verifier.');
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
            BingoLotteryImportService::STATUS_IMPORTED,
            BingoLotteryImportService::STATUS_DUPLICATE => self::SUCCESS,
            BingoLotteryImportService::STATUS_CONFLICT => 2,
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
            'first_6_mega' => $this->stringOption('first-6-mega'),
            'three_mega' => $this->stringOption('three-mega'),
            'two_mega' => $this->stringOption('two-mega'),
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
            BingoLotteryImportService::STATUS_IMPORTED => $this->info($message),
            BingoLotteryImportService::STATUS_DUPLICATE => $this->line($message),
            BingoLotteryImportService::STATUS_CONFLICT => $this->warn($message),
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
                'status' => BingoLotteryImportService::STATUS_REJECTED,
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
