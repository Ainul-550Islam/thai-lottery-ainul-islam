<?php

declare(strict_types=1);

namespace App\Listeners\Cashback;

use App\Enums\BetFundingSource;
use App\Events\BetCancelled;
use App\Services\LotteryGames\GameAuditRecorder;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reverse a Batch-1 cashback accrual when the bet that earned it is cancelled.
 *
 * WHOSE RULE IS THIS
 * The Batch-1 cashback lane owns accrual; this listener only tells it a fact it
 * cannot otherwise learn (the bet was cancelled after the fact). The service is
 * resolved from config('lottery_games.funding.cashback_service'), so this repository
 * does not have to contain it — and does not pretend to when it is absent.
 *
 * THE BASIS EXCLUSION IS READ, NOT ASSUMED
 * Bonus- and free-bet-funded stakes are excluded from the cashback basis
 * (config('lottery_games.funding.cashback_basis_excludes_funding_sources')). A
 * cancellation therefore only asks for a reversal when the bet's funding source is IN
 * the basis; a free-bet stake has no accrual to reverse, and asking anyway would
 * credit the player twice.
 */
final class ReverseCashbackOnBetCancelled
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly GameAuditRecorder $audit,
    ) {}

    public function handle(BetCancelled $event): void
    {
        $bet = $event->bet;
        $source = BetFundingSource::of($bet->funding_source instanceof BetFundingSource
            ? $bet->funding_source->value
            : (is_string($bet->funding_source) ? $bet->funding_source : null));

        /** @var list<string> $excluded */
        $excluded = (array) $this->config->get(
            'lottery_games.funding.cashback_basis_excludes_funding_sources',
            ['bonus', 'free_bet'],
        );

        if (in_array($source->value, $excluded, true)) {
            // Not part of the cashback basis: there is nothing to reverse.
            return;
        }

        $serviceClass = $this->config->get('lottery_games.funding.cashback_service');

        if (! is_string($serviceClass) || ! class_exists($serviceClass)) {
            // A cancelled, cash-funded bet whose cashback cannot be reversed is money
            // the platform paid out on a stake it refunded. A log line is easy to miss,
            // so the gap is written to the audit trail where a reconciliation can find
            // every bet it applies to.
            $this->recordUnreversed($bet, GameAuditRecorder::CASHBACK_REVERSAL_UNAVAILABLE, 'the Batch-1 cashback service is not installed on this deployment', $event->reason);

            Log::info('cashback.reversal_skipped', [
                'bet_id' => $bet->getKey(),
                'reason' => 'the Batch-1 cashback service is not installed on this deployment',
            ]);

            return;
        }

        try {
            $service = app($serviceClass);

            if (! method_exists($service, 'reverse')) {
                $this->recordUnreversed($bet, GameAuditRecorder::CASHBACK_REVERSAL_UNAVAILABLE, 'the configured cashback service has no reverse() method', $event->reason);

                Log::warning('cashback.reversal_skipped', [
                    'bet_id' => $bet->getKey(),
                    'reason' => 'the configured cashback service has no reverse() method',
                ]);

                return;
            }

            $service->reverse((int) $bet->user_id, (int) $bet->getKey(), $event->reason);
        } catch (Throwable $e) {
            $this->recordUnreversed($bet, GameAuditRecorder::CASHBACK_REVERSAL_FAILED, $e->getMessage(), $event->reason);

            Log::warning('cashback.reversal_failed', [
                'bet_id' => $bet->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Put an unreversed cashback on the audit trail.
     */
    private function recordUnreversed(\App\Models\Bet $bet, string $event, string $why, string $cancellationReason): void
    {
        $isDigitBet = is_string($bet->game_code) && $bet->game_code !== '';

        // "The service is not installed" is a deployment-wide fact. This listener hears
        // EVERY cancelled bet, Batch 1's included, so recording that fact for each of
        // them would add a row per pre-existing cancellation and change nothing about
        // how those bets behave - which is not this lane's business. A reversal that
        // was attempted and FAILED is an exception worth a row for any bet.
        if ($event === GameAuditRecorder::CASHBACK_REVERSAL_UNAVAILABLE && ! $isDigitBet) {
            return;
        }

        $this->audit->record(
            $event,
            $isDigitBet ? $bet->game_code : null,
            (int) $bet->user_id,
            'bet',
            (int) $bet->getKey(),
            [
                'why' => $why,
                'cancellation_reason' => $cancellationReason,
                'stake' => (string) $bet->stake_amount,
                'funding_source' => $bet->funding_source instanceof BetFundingSource
                    ? $bet->funding_source->value
                    : (is_string($bet->funding_source) ? $bet->funding_source : null),
            ],
        );
    }
}
