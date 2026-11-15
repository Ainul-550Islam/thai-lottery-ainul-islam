<?php

declare(strict_types=1);

namespace App\Console\Commands\LotteryGames;

use App\Contracts\LotteryGames\FreeBetVoucherProvider;
use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use ReflectionMethod;
use Throwable;

/**
 * lottery-games:check-funding-hooks - are the Batch-1 funding services actually wired?
 *
 * WHY THIS EXISTS
 * Two lane behaviours depend on Batch-1 services the lane cannot construct itself:
 *
 *   free_bet_provider   the voucher service behind a free-bet funded stake
 *   cashback_service    the accrual service whose reverse() undoes cashback on a cancel
 *
 * Both are named in configuration (lottery_games.funding.*) and both default to NULL,
 * because the lane must not guess a class name that may not exist. The cost of that
 * honesty is silence: with the defaults a free bet is always refused and a cancelled
 * bet never loses its cashback, and nothing in the running system says so. This command
 * says so, out loud, and exits non-zero until both resolve to something usable - which
 * makes it a deployment gate rather than a thing somebody remembers to check.
 *
 * WHAT "USABLE" MEANS HERE
 *   free_bet_provider   a container-resolvable class implementing FreeBetVoucherProvider
 *   cashback_service    a container-resolvable class with a public reverse() accepting
 *                       (int $userId, int $betId, string $reason) - the call the
 *                       cancellation listener makes
 * It checks SHAPE only. It does not call either service: a check that moved money or
 * touched a voucher would be a hazard to run against production.
 *
 * --allow-unset downgrades "not configured" (but never "configured and broken") to a
 * warning, for environments that deliberately do not run these lanes.
 */
final class CheckFundingHooksCommand extends Command
{
    protected $signature = 'lottery-games:check-funding-hooks
                            {--allow-unset : Treat a hook that is not configured as a warning, not a failure}';

    protected $description = 'Verify that the Batch-1 free-bet and cashback services named in configuration resolve and have the expected shape';

    public function handle(Repository $config): int
    {
        $failed = false;

        $failed = $this->checkFreeBetProvider($config) || $failed;
        $failed = $this->checkCashbackService($config) || $failed;

        $this->newLine();
        $this->line($failed ? '<error>funding hooks: NOT READY</error>' : '<info>funding hooks: ready</info>');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return bool true when this hook blocks readiness
     */
    private function checkFreeBetProvider(Repository $config): bool
    {
        $class = $config->get('lottery_games.funding.free_bet_provider');

        if (! is_string($class) || trim($class) === '') {
            return $this->unset('free_bet_provider', 'LOTTERY_GAMES_FREE_BET_PROVIDER');
        }

        if (! class_exists($class)) {
            $this->error(sprintf('free_bet_provider: class "%s" does not exist.', $class));

            return true;
        }

        try {
            $instance = app($class);
        } catch (Throwable $e) {
            $this->error(sprintf('free_bet_provider: "%s" cannot be resolved from the container: %s', $class, $e->getMessage()));

            return true;
        }

        if (! $instance instanceof FreeBetVoucherProvider) {
            $this->error(sprintf('free_bet_provider: "%s" does not implement %s.', $class, FreeBetVoucherProvider::class));

            return true;
        }

        $this->info(sprintf('free_bet_provider: %s (implements the voucher contract)', $class));

        return false;
    }

    /**
     * @return bool true when this hook blocks readiness
     */
    private function checkCashbackService(Repository $config): bool
    {
        $class = $config->get('lottery_games.funding.cashback_service');

        if (! is_string($class) || trim($class) === '') {
            return $this->unset('cashback_service', 'LOTTERY_GAMES_CASHBACK_SERVICE');
        }

        if (! class_exists($class)) {
            $this->error(sprintf('cashback_service: class "%s" does not exist.', $class));

            return true;
        }

        if (! method_exists($class, 'reverse')) {
            $this->error(sprintf('cashback_service: "%s" has no reverse() method.', $class));

            return true;
        }

        $method = new ReflectionMethod($class, 'reverse');

        if (! $method->isPublic()) {
            $this->error(sprintf('cashback_service: %s::reverse() is not public.', $class));

            return true;
        }

        // The listener calls reverse($userId, $betId, $reason): three positional values.
        if ($method->getNumberOfParameters() < 3 || $method->getNumberOfRequiredParameters() > 3) {
            $this->error(sprintf(
                'cashback_service: %s::reverse() takes %d parameter(s) (%d required); the cancellation listener passes exactly three (user id, bet id, reason).',
                $class,
                $method->getNumberOfParameters(),
                $method->getNumberOfRequiredParameters(),
            ));

            return true;
        }

        try {
            app($class);
        } catch (Throwable $e) {
            $this->error(sprintf('cashback_service: "%s" cannot be resolved from the container: %s', $class, $e->getMessage()));

            return true;
        }

        $this->info(sprintf('cashback_service: %s::reverse() accepts (userId, betId, reason)', $class));

        return false;
    }

    /**
     * @return bool true when the unset hook blocks readiness
     */
    private function unset(string $key, string $env): bool
    {
        $message = sprintf(
            '%s: not configured (set lottery_games.funding.%s / %s).',
            $key,
            $key,
            $env,
        );

        if ((bool) $this->option('allow-unset')) {
            $this->warn($message);

            return false;
        }

        $this->error($message);

        return true;
    }
}
