<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Lottery\NationalLotteryImportService;
use App\Services\Lottery\NationalLotterySourceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Import one National Lottery result (PROMPT 5, file 29).
 *
 * MODELLED ON THE EXISTING glo:import-result COMMAND, on purpose: same
 * option shape, same JSON report option, same exit-code discipline. An
 * operator who already runs the GLO importer does not have to learn a second
 * convention, and a scheduler wrapper written for one works for the other.
 *
 * THE PAYLOAD COMES FROM A FILE OR FROM EXPLICIT OPTIONS - NEVER FROM
 * NOTHING. There is no mode in which this command invents a draw. --file
 * reads a JSON document; the individual options let an operator restate a
 * payload by hand. Either way the values are validated against
 * config('national_lottery.fields') before anything is written.
 *
 * EXIT CODES
 *   0  imported, or duplicate (a duplicate is a correct no-op, and a
 *      scheduler retrying after a timeout must not be told it failed);
 *   1  rejected (bad payload, unknown provider, unconfigured provider);
 *   2  conflict - the payload disagrees with the verified version.
 *      A distinct code, because a conflict needs a human, while a rejection
 *      usually needs a fixed feed.
 *
 * NOTHING SENSITIVE IS PRINTED. The command prints the provider, the source
 * state, the draw reference and the version. It never prints a token, an
 * endpoint URL, a stack trace or a driver message; a failure prints the
 * service's generic message and the exception is reported through the
 * application's normal channel.
 */
class ImportNationalLotteryResults extends Command
{
    /**
     * @var string
     */
    protected $signature = 'national-lottery:import
        {--file= : Path to a JSON payload file}
        {--provider=official : Source provider lane (official, internal, fixture)}
        {--draw-date= : Draw date, in one of the configured accepted formats}
        {--first-prize= : Six-digit first prize, leading zeros included}
        {--three-up= : Three-digit 3 Up}
        {--two-up= : Two-digit 2 Up}
        {--two-down= : Two-digit 2 Down}
        {--three-front=* : Three-digit 3 Front value; repeat the option for each value}
        {--three-after=* : Three-digit 3 After value; repeat the option for each value}
        {--source-identifier= : The provider reference for this delivery}
        {--actor= : User id to record as the importer}
        {--no-publish : Store the version without publishing it}
        {--json : Emit a machine-readable report instead of a table}';

    /**
     * @var string
     */
    protected $description = 'Import one National Lottery draw result with its provenance recorded.';

    public function handle(
        NationalLotteryImportService $importer,
        NationalLotterySourceService $sources,
    ): int {
        $provider = (string) $this->option('provider');

        if (! in_array($provider, $sources->priority(), true)) {
            return $this->reportFailure('UNKNOWN_PROVIDER', 'Unknown provider: use one of '.implode(', ', $sources->priority()).'.');
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
                'Provide --file, or at least --draw-date and --first-prize.',
            );
        }

        $actorOption = $this->option('actor');
        $actorId = is_string($actorOption) && preg_match('/^[0-9]{1,18}$/', $actorOption) === 1
            ? (int) $actorOption
            : null;

        $outcome = $importer->import(
            payload: $payload,
            provider: $provider,
            actorId: $actorId,
            autoPublish: ! (bool) $this->option('no-publish'),
        );

        $this->report($outcome);

        return match ($outcome['status']) {
            NationalLotteryImportService::STATUS_IMPORTED,
            NationalLotteryImportService::STATUS_DUPLICATE => self::SUCCESS,
            NationalLotteryImportService::STATUS_CONFLICT => 2,
            default => self::FAILURE,
        };
    }

    /**
     * Assemble the payload from --file or from the explicit options.
     *
     * Values are passed through UNCHANGED. The command does not pad, trim to
     * width, reformat or reorder anything: '004615' stays '004615', and a
     * malformed value is left malformed so the import service can reject it
     * rather than having it quietly repaired here.
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

        $drawDate = $this->option('draw-date');
        $firstPrize = $this->option('first-prize');

        if (! is_string($drawDate) || $drawDate === '' || ! is_string($firstPrize) || $firstPrize === '') {
            return null;
        }

        return [
            'draw_date' => $drawDate,
            'first_prize' => $firstPrize,
            'three_up' => $this->stringOption('three-up'),
            'two_up' => $this->stringOption('two-up'),
            'two_down' => $this->stringOption('two-down'),
            // Repeated options arrive as a list in the order given, and that
            // order is preserved all the way into storage.
            'three_front' => $this->listOption('three-front'),
            'three_after' => $this->listOption('three-after'),
            'source_identifier' => $this->stringOption('source-identifier'),
            'retrieved_at' => now()->toIso8601String(),
        ];
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function listOption(string $name): array
    {
        $values = $this->option($name);

        if (! is_array($values)) {
            return [];
        }

        $out = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $out[] = $value;
            }
        }

        return $out;
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

        $this->table(
            ['Field', 'Value'],
            [
                ['status', (string) $outcome['status']],
                ['draw', (string) ($outcome['draw_reference'] ?? '-')],
                ['version', $outcome['version'] === null ? '-' : (string) $outcome['version']],
                ['source_state', (string) $outcome['source_state']],
                ['published', ((bool) $outcome['published']) ? 'yes' : 'no'],
                ['errors', $outcome['errors'] === [] ? '-' : implode(', ', $outcome['errors'])],
            ],
        );

        $message = (string) $outcome['message'];

        match ($outcome['status']) {
            NationalLotteryImportService::STATUS_IMPORTED => $this->info($message),
            NationalLotteryImportService::STATUS_DUPLICATE => $this->line($message),
            NationalLotteryImportService::STATUS_CONFLICT => $this->warn($message),
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
                'status' => NationalLotteryImportService::STATUS_REJECTED,
                'draw_reference' => null,
                'version' => null,
                'source_state' => 'UNAVAILABLE',
                'published' => false,
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
