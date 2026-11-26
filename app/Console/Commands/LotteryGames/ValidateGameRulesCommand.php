<?php

declare(strict_types=1);

namespace App\Console\Commands\LotteryGames;

use App\Services\LotteryGames\GameRegistry;
use App\Services\LotteryGames\GameRulesValidator;
use App\Services\LotteryGames\RtpAnalyzer;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;

/**
 * lottery-games:validate-rules — the CI gate for batch-2 rules.
 *
 * EXIT 1 MEANS "DO NOT SHIP"
 * While either game still contains REQUIRED_INPUT, this command exits 1. That
 * is the correct state of this repository today: no operator has supplied the
 * official rules, so the gate must be red rather than green-by-default. It goes
 * green only when a complete, in-ceiling rule set is configured — which is the
 * same condition GameRegistry::verify() requires before a game can be enabled.
 *
 * It also prints the computed RTP per bet type, because an operator verifying a
 * payout table should be able to see the expected return next to the number
 * they are about to sign.
 */
final class ValidateGameRulesCommand extends Command
{
    protected $signature = 'lottery-games:validate-rules {game? : A single game code}';

    protected $description = 'Fail (exit 1) when a digit game rule set is incomplete or breaches the RTP ceiling';

    public function handle(
        GameRegistry $registry,
        GameRulesValidator $validator,
        RtpAnalyzer $rtp,
        Repository $config,
    ): int {
        $requested = $this->argument('game');
        $codes = is_string($requested) && $requested !== '' ? [$requested] : $registry->codes();
        $ceiling = (string) $config->get('lottery_games.rtp_ceiling', '0.98');

        if ($codes === []) {
            $this->error('No games are configured.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($codes as $code) {
            $game = $registry->find($code);

            if ($game === null) {
                $this->error("{$code}: unknown game");
                $failed = true;

                continue;
            }

            $report = $validator->validate($game);

            $this->newLine();
            $this->info("Game: {$code} ({$game->name})");

            if ($report['missing'] !== []) {
                $failed = true;
                $this->warn('  Missing operator input ('.count($report['missing']).'):');
                foreach ($report['missing'] as $path) {
                    $this->line('    - '.$path);
                }
            }

            if ($report['problems'] !== []) {
                $failed = true;
                $this->warn('  Rule problems:');
                foreach ($report['problems'] as $problem) {
                    $this->line('    - '.$problem);
                }
            }

            $analysis = $rtp->analyze($game, $ceiling);

            if ($analysis['bet_types'] !== []) {
                $this->line('  Theoretical RTP (ceiling '.$analysis['ceiling'].'):');

                foreach ($analysis['bet_types'] as $betType => $row) {
                    $this->line(sprintf(
                        '    %-14s p=%-12s x %-10s = %s',
                        $betType,
                        $row['probability'],
                        $row['multiplier'],
                        $row['rtp'],
                    ));
                }
            }

            if (! $analysis['within_ceiling']) {
                $failed = true;
                $this->error('  RTP ceiling breached: '.implode(', ', $analysis['breaches']));
            }

            if ($report['valid'] && $analysis['within_ceiling']) {
                $this->info('  OK — complete and inside the ceiling.');
            }
        }

        $this->newLine();

        if ($failed) {
            $this->error('lottery-games:validate-rules FAILED.');

            return self::FAILURE;
        }

        $this->info('lottery-games:validate-rules passed.');

        return self::SUCCESS;
    }
}
