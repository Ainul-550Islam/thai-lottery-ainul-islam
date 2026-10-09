<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Lottery\ResultImportService;
use Illuminate\Console\Command;
use JsonException;

/**
 * Universal Lottery Result Import Command.
 *
 * Imports results for any supported lottery lane (national, weekly, bingo, pcso, glo)
 * from JSON payload file or directory.
 */
class ImportLotteryResults extends Command
{
    protected $signature = 'lottery:import
        {--lane= : Lottery lane (national, weekly, bingo, pcso, glo)}
        {--file= : Path to JSON result file or directory}
        {--provider=official : Source provider identifier}
        {--dry-run : Validate and fingerprint without persisting}
        {--no-publish : Ingest version without publishing}';

    protected $description = 'Ingest lottery result payloads with validation, provenance, and integrity checks.';

    public function handle(ResultImportService $importer): int
    {
        $lane = (string) $this->option('lane');
        $file = (string) $this->option('file');
        $provider = (string) $this->option('provider');
        $dryRun = (bool) $this->option('dry-run');
        $autoPublish = ! (bool) $this->option('no-publish');

        if (empty($lane)) {
            $this->error('The --lane option is required (national, weekly, bingo, pcso, glo).');

            return Command::FAILURE;
        }

        if (empty($file) || ! file_exists($file)) {
            $this->error(sprintf('File or directory [%s] not found.', $file));

            return Command::FAILURE;
        }

        $files = is_dir($file)
            ? glob(rtrim($file, '/').'/*.json')
            : [$file];

        if (empty($files)) {
            $this->error('No JSON files found to import.');

            return Command::FAILURE;
        }

        $imported = 0;
        $failed = 0;

        foreach ($files as $filePath) {
            $content = file_get_contents($filePath);
            if ($content === false) {
                $this->error("Failed to read file: {$filePath}");
                $failed++;

                continue;
            }

            try {
                $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->error("Malformed JSON in {$filePath}: {$e->getMessage()}");
                $failed++;

                continue;
            }

            if (! is_array($payload)) {
                $this->error("Payload in {$filePath} must be a JSON object.");
                $failed++;

                continue;
            }

            try {
                $result = $importer->import(
                    lane: $lane,
                    payload: $payload,
                    provider: $provider,
                    actorId: null,
                    autoPublish: $autoPublish,
                    dryRun: $dryRun
                );

                // STATUS_REJECTED and STATUS_FAILED are DIFFERENT answers and
                // were collapsed into one branch before. A rejection means the
                // caller's payload or request was refused; a failure means the
                // SOURCE was (an upstream outage, an unconfigured provider).
                // Reporting both as "Rejected" blames the wrong system and sends
                // an operator to inspect a file that is perfectly valid.
                if ($result['status'] === ResultImportService::STATUS_REJECTED) {
                    // The message is printed, not just the error codes. On the
                    // GLO lane the message is the actionable part: it says that a
                    // GLO result can never be auto-published and names the two
                    // commands that stage it instead. Codes alone would leave an
                    // operator staring at AUTO_PUBLISH_FORBIDDEN with no next step.
                    $this->error(sprintf(
                        'Rejected %s [%s] %s',
                        $filePath,
                        implode(', ', $result['errors']),
                        $result['message'] ?? '',
                    ));
                    $failed++;
                } elseif ($result['status'] === ResultImportService::STATUS_FAILED) {
                    $this->warn(sprintf(
                        'Source failure for %s [%s] %s',
                        $filePath,
                        implode(', ', $result['errors']),
                        $result['message'] ?? '',
                    ));
                    $failed++;
                } else {
                    $this->info(sprintf(
                        'Processed %s (status: %s) %s',
                        $filePath,
                        $result['status'],
                        $result['message'] ?? '',
                    ));
                    $imported++;
                }
            } catch (\Throwable $e) {
                $this->error("Exception importing {$filePath}: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->line("Summary: {$imported} processed, {$failed} failed.");

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
