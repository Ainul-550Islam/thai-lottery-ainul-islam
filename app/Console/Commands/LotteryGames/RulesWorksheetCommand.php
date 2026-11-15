<?php

declare(strict_types=1);

namespace App\Console\Commands\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\RulesProfileResolver;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * THE OPERATOR'S CHECKLIST, GENERATED FROM THE CONFIGURATION ITSELF.
 *
 * WHY IT EXISTS
 * `lottery-games:validate-rules` answers "is this deployment correct", which is the wrong
 * question for the person who has to make it correct. This command answers "what do I
 * still have to find out, and where does each answer come from" — one line per outstanding
 * value, in the order the documents are usually pulled apart, plus a JSON skeleton with
 * exactly those keys so the answer can be typed once instead of re-deriving the shape.
 *
 * WHERE THE QUESTIONS COME FROM
 * The outstanding list is `GameDefinition::requiredInputs()` — the literal REQUIRED_INPUT
 * placeholders in the effective configuration. Nothing here is a hardcoded checklist that
 * could drift from the configuration: if a new placeholder is added to a game block, it
 * appears here on the next run.
 *
 * WHAT EACH LINE ADDS
 * A dotted path (what is missing), a source hint (which document or system the value comes
 * from) and the projection instruction the value must satisfy where one exists. A path with
 * no hint still lists — silence would hide the requirement — and is reported as "source not
 * classified", which is itself a defect worth seeing.
 *
 * EXIT CODES
 * 0 always, unless --require-complete is passed: this is a REPORT, and a report that fails
 * a build is a report people stop running. The validate command is the gate.
 */
final class RulesWorksheetCommand extends Command
{
    protected $signature = 'lottery-games:rules:worksheet
                            {--game= : Limit the worksheet to one game code}
                            {--json : Emit the fill-in skeleton as JSON instead of a table}
                            {--write= : Write the JSON skeleton to this path}
                            {--require-complete : Exit 1 when anything is still outstanding}';

    protected $description = 'List every rule value an operator still has to supply, and emit a fill-in skeleton';

    /**
     * Where each value is supposed to come from. Keyed by the LAST segment of a dotted
     * path (or by the full path for the paths that mean something specific), because the
     * same field means the same thing in every game.
     *
     * @var array<string, string>
     */
    private const SOURCE_HINTS = [
        'digits' => 'official rules document — the number of digits drawn',
        'draw_cron' => 'official draw schedule + timezone (one cron expression per daily draw)',
        'cutoff_seconds' => 'operator selling policy / official terms (betting close, seconds before the draw)',
        'selection_digits' => 'official bet-type table — digits per market',
        'match_mode' => 'official bet-type table — exact | any_order | prefix | suffix',
        'payout_multiplier' => 'official payout table — the published multiple of stake',
        'min_stake' => 'operator commercial policy',
        'max_stake' => 'operator commercial policy',
        'max_liability_per_draw' => 'risk committee — cap on total potential payout for one draw',
        'max_liability_per_number' => 'risk committee — cap on potential payout for one exact number',
        'settlement_market_key' => 'the existing config/lottery.markets key this game settles through',
        'first_prize' => 'operator mapping decision: digits | digits_left_padded | digits_right_padded | first:N | last:N',
        'bottom_two' => 'operator mapping decision: digits | first:N | last:N',
        'base_url' => 'licensed result provider — API base URL',
        'latest_path' => 'licensed result provider — path for the latest result',
        'by_draw_no_path' => 'licensed result provider — path template for one draw number',
        'draw_no_field' => 'licensed result provider — JSON field carrying the draw number',
        'digits_field' => 'licensed result provider — JSON field carrying the winning digits',
        'drawn_at_field' => 'licensed result provider — JSON field carrying the draw timestamp',
        'schema_version' => 'licensed result provider — contract version you are integrating against',
        'driver' => 'result provider driver (official | fixture[non-production only])',
    ];

    public function handle(GameRegistry $registry, ConfigRepository $config, RulesProfileResolver $resolver): int
    {
        $requested = $this->option('game');
        $codes = is_string($requested) && $requested !== '' ? [$requested] : $registry->codes();

        $profile = $resolver->profileName();
        $override = $resolver->overridePath();
        $overrideExists = is_string($override) && is_file($override);

        $this->line('rule set in force: '.($profile === null
            ? 'config/lottery_games.php (declared)'
            : 'config/lottery_games.php + profile "'.$profile.'"'));
        $this->line('operator override: '.($overrideExists ? $override : 'none'));
        $this->newLine();

        $skeleton = ['games' => []];
        $outstanding = 0;

        foreach ($codes as $code) {
            $game = $registry->find($code);

            if (! $game instanceof GameDefinition) {
                $this->error($code.': unknown game code.');

                continue;
            }

            $paths = $game->requiredInputs();

            $this->info(sprintf('%s (%s) — %d value(s) outstanding', $code, $game->name, count($paths)));

            foreach ($paths as $path) {
                $outstanding++;
                $this->line(sprintf('    %-46s %s', $path, $this->hint($path)));
            }

            if ($paths !== []) {
                $this->newLine();
            }

            $skeleton['games'][$code] = $this->skeletonFor($game, $paths);
        }

        if (is_string($this->option('write')) && $this->option('write') !== '') {
            $path = (string) $this->option('write');
            $json = json_encode($skeleton, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if (is_string($json) && @file_put_contents($path, $json."\n") !== false) {
                $this->newLine();
                $this->info('skeleton written: '.$path);
                $this->line('Fill it in and run: php artisan lottery-games:rules:import '.$path);
            } else {
                $this->error('could not write '.$path);

                return self::FAILURE;
            }
        }

        $this->newLine();

        if ($this->option('json')) {
            $this->line((string) json_encode($skeleton, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->line(sprintf(
            'outstanding across %d game(s): %d value(s). Ceiling in force: %s',
            count($codes),
            $outstanding,
            (string) $config->get('lottery_games.rtp_ceiling', '0.98'),
        ));

        if ($outstanding === 0) {
            $this->info('Nothing outstanding: the effective rule set is complete.');

            return self::SUCCESS;
        }

        if ($this->option('require-complete')) {
            $this->error('rules are incomplete (--require-complete).');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The hint for one dotted path.
     *
     * Full-path hints win over last-segment hints because a few paths need a specific
     * instruction (the publication bridge is a projection, not a number).
     */
    private function hint(string $path): string
    {
        if (isset(self::SOURCE_HINTS[$path])) {
            return self::SOURCE_HINTS[$path];
        }

        $segments = explode('.', $path);
        $last = (string) end($segments);

        if (isset(self::SOURCE_HINTS[$last])) {
            return self::SOURCE_HINTS[$last];
        }

        // A placeholder nobody classified is a defect in THIS table, and saying so is
        // better than a blank column that looks like "no input needed".
        return 'source not classified — see the official rules document';
    }

    /**
     * A skeleton for one game: only the outstanding paths, with the declared scalar
     * values an operator may keep. Everything already configured is omitted, so a filled
     * document cannot overwrite a value a reviewer never saw.
     *
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function skeletonFor(GameDefinition $game, array $paths): array
    {
        $skeleton = [];

        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $cursor = &$skeleton;

            foreach ($segments as $index => $segment) {
                if ($index === count($segments) - 1) {
                    $cursor[$segment] = GameDefinition::REQUIRED_INPUT;

                    break;
                }

                if (! isset($cursor[$segment]) || ! is_array($cursor[$segment])) {
                    $cursor[$segment] = [];
                }

                $cursor = &$cursor[$segment];
            }

            unset($cursor);
        }

        return $skeleton;
    }
}
