<?php

declare(strict_types=1);

namespace App\Console\Commands\LotteryGames;

use App\Services\LotteryGames\GameAuditRecorder;
use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\RulesImportValidator;
use App\Services\LotteryGames\RulesProfileResolver;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * TAKE AN OPERATOR'S FILLED-IN DOCUMENT, PROVE IT, AND PUT IT IN FORCE WITHOUT A DEPLOY.
 *
 * THE PROBLEM THIS SOLVES
 * The remaining work on this lane is not code, it is facts: multipliers, a draw schedule, a
 * cutoff, stake bounds, liability caps, a result endpoint and a publication projection. Those
 * live in config/lottery_games.php, which means today they need a code review and a deploy.
 * That is the wrong shape: rule values change on an operator's timetable, not a release's.
 * This command accepts them as a JSON document, writes them to
 * storage/app/lottery_games/rules.override.json, and RulesProfileResolver applies that layer
 * on the next request — no deploy, no merge conflict, no forgotten placeholder.
 *
 * WHAT IT REFUSES TO DO, AND WHY EACH REFUSAL MATTERS
 *
 *   1  IT NEVER MERGES FIELD BY FIELD ACROSS LAYERS. An incoming document is filled onto the
 *      CURRENT effective block (so a worksheet skeleton containing only the missing paths is
 *      a valid input) and the RESULT is validated and stored as a whole game block. Nothing
 *      half-applied ever reaches the resolver, because the resolver only accepts complete
 *      blocks and this command only writes complete ones.
 *   2  IT NEVER WRITES AN INVALID RULE SET. Every candidate goes through the same
 *      GameRulesValidator and RtpAnalyzer the gate command uses. A block with an unparseable
 *      cron, a cutoff outside the cadence, a per-number cap that cannot bind, an unreadable
 *      endpoint or an RTP above the ceiling is refused, with the reason, and nothing is written.
 *   3  IT NEVER TOUCHES rules_status. Verification is an audited operator action against a
 *      checksum, not a file; the imported block is forced to 'unverified' and the operator must
 *      then verify through the existing Filament action, which is the trail that matters.
 *   4  IT NEVER WRITES A PARTIAL FILE. The write is a temp file plus rename, so a process that
 *      dies mid-write cannot leave a truncated JSON that the resolver then ignores silently.
 *
 * --dry-run prints exactly what would be written, including the checksums, and writes nothing.
 */
final class RulesImportCommand extends Command
{
    protected $signature = 'lottery-games:rules:import
                            {file : Path to the JSON document (worksheet skeleton shape, or a full game block)}
                            {--game= : Import only this game code (required when the file is a bare block)}
                            {--dry-run : Validate and report, write nothing}';

    protected $description = 'Validate an operator-supplied rule document and install it as the runtime override';

    public function handle(
        RulesImportValidator $validator,
        RulesProfileResolver $resolver,
        GameRegistry $registry,
        ConfigRepository $config,
        GameAuditRecorder $audit,
    ): int {
        $path = (string) $this->argument('file');

        if (! is_file($path)) {
            $this->error('File not found: '.$path);

            return self::FAILURE;
        }

        $raw = @file_get_contents($path);

        if (! is_string($raw) || trim($raw) === '') {
            $this->error('File is empty: '.$path);

            return self::FAILURE;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            $this->error('File is not valid JSON: '.json_last_error_msg());

            return self::FAILURE;
        }

        $incoming = is_array($decoded['games'] ?? null) ? $decoded['games'] : null;
        $only = $this->option('game');

        if ($incoming === null) {
            // A bare block needs to be told which game it belongs to.
            if (! is_string($only) || $only === '') {
                $this->error('The file has no "games" object; pass --game=<code> to name the game it describes.');

                return self::FAILURE;
            }

            $incoming = [$only => $decoded];
        }

        // THE CURRENT BLOCK COMES FROM THE REGISTRY, NOT FROM config().
        // GameRegistry is the lane's single read point for rule values (there is an
        // architecture test for exactly that), and it already applies the layers in force.
        // Reaching past it here would mean the importer merged an incoming document onto a
        // DIFFERENT block than the one the pricing path uses - the one bug class that would
        // make an imported rule set silently disagree with the rules a bet was priced under.
        /** @var array<string, mixed> $effective */
        $effective = [];

        foreach ($registry->codes() as $declared) {
            $definition = $registry->find($declared);

            if ($definition !== null) {
                $effective[$declared] = $definition->raw;
            }
        }
        $ceiling = (string) $config->get('lottery_games.rtp_ceiling', '0.98');

        $accepted = [];
        $failed = false;

        foreach ($incoming as $code => $block) {
            $code = (string) $code;

            if (is_string($only) && $only !== '' && $code !== $only) {
                continue;
            }

            if (! is_array($block)) {
                $this->error($code.': the entry is not an object.');

                $failed = true;

                continue;
            }

            $current = $effective[$code] ?? null;

            if (! is_array($current)) {
                $this->error($code.': not declared in config/lottery_games.php, so it cannot be given rules here.');

                $failed = true;

                continue;
            }

            // Fill the incoming values onto the block currently in force. This is what
            // makes the worksheet skeleton — which contains only the outstanding paths —
            // a legal input, without ever merging two DIFFERENT rule sets.
            $candidate = array_replace_recursive($current, $block);
            $candidate['code'] = $code;
            $candidate['rules_status'] = 'unverified';

            $report = $validator->inspect($code, $candidate, $ceiling);

            $this->newLine();
            $this->info($code.' — '.(string) ($candidate['name'] ?? $code));

            if ($report['missing'] !== []) {
                $this->warn('  still outstanding ('.count($report['missing']).'):');

                foreach ($report['missing'] as $item) {
                    $this->line('    - '.$item);
                }
            }

            if ($report['problems'] !== []) {
                $this->error('  refused:');

                foreach ($report['problems'] as $problem) {
                    $this->line('    - '.$problem);
                }
            }

            foreach ($report['warnings'] as $warning) {
                $this->line('  warning: '.$warning);
            }

            if ($report['rtp'] !== null) {
                foreach ((array) ($report['rtp']['bet_types'] ?? []) as $betType => $row) {
                    $this->line(sprintf(
                        '  %-14s p=%-12s x %-10s = %s',
                        (string) $betType,
                        (string) ($row['probability'] ?? ''),
                        (string) ($row['multiplier'] ?? ''),
                        (string) ($row['rtp'] ?? ''),
                    ));
                }
            }

            if (! $report['valid']) {
                $failed = true;

                continue;
            }

            $accepted[$code] = $candidate;
            $this->info('  accepted — checksum '.substr(hash('sha256', (string) json_encode($candidate)), 0, 16));
        }

        if ($accepted === []) {
            $this->newLine();
            $this->error('Nothing was accepted; the override file was not written.');

            return self::FAILURE;
        }

        $existing = $resolver->overrideGames();
        $merged = is_array($existing) ? $existing : [];

        foreach ($accepted as $code => $block) {
            $merged[$code] = $block;
        }

        $payload = [
            '_meta' => [
                'written_by' => 'lottery-games:rules:import',
                'written_at' => gmdate('c'),
                'source_file' => basename($path),
                'rtp_ceiling' => $ceiling,
                'note' => 'Values supplied by an operator from official documents. Verification is a separate, audited action.',
            ],
            'games' => $merged,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($json)) {
            $this->error('Could not encode the override document.');

            return self::FAILURE;
        }

        $target = $resolver->overridePath();

        if ($target === null) {
            $this->error('Could not resolve the storage path for the override file.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($this->option('dry-run')) {
            $this->warn('--dry-run: nothing was written. Target would be: '.$target);

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $directory = dirname($target);

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error('Could not create '.$directory);

            return self::FAILURE;
        }

        // Temp file + rename: a half-written override must never exist, because the
        // resolver would treat it as an operator typo and quietly ignore the layer.
        $temp = $target.'.'.bin2hex(random_bytes(6)).'.tmp';

        if (@file_put_contents($temp, $json."\n") === false || ! @rename($temp, $target)) {
            @unlink($temp);
            $this->error('Could not write '.$target);

            return self::FAILURE;
        }

        // Installing a rule document changes what the platform will promise to pay, so
        // it is attributable: what was installed (by hash and by game), where, and when.
        // There is no operator seat in a CLI process, hence no actor.
        $audit->record(
            GameAuditRecorder::RULES_OVERRIDE_INSTALLED,
            is_string($only) && $only !== '' ? $only : null,
            null,
            'rules_override',
            basename($target),
            [
                'target' => $target,
                'sha256' => hash('sha256', $json),
                'games' => array_map('strval', array_keys($accepted)),
            ],
        );

        $this->info('override written: '.$target);
        $this->line('Next: php artisan lottery-games:validate-rules   (then verify the checksum in the admin panel)');
        $this->line('Applied on the next request; no deploy and no restart are needed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
