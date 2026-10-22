<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\BetPlaced;
use App\Events\DepositStatusUpdated;
use App\Events\DrawResultPublished;
use App\Events\DrawStatusUpdated;
use App\Events\WalletBalanceUpdated;
use App\Events\WithdrawalStatusUpdated;
use App\Models\Bet;
use App\Models\Deposit;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Database\Eloquent\Model;

/**
 * The publish side of the player's real-time lane: bet placement, wallet, deposit, withdrawal,
 * draw state and draw results.
 *
 * WHY AN OBSERVER RATHER THAN A DISPATCH AT EACH CALL SITE
 *
 * Before this file existed the six events in app/Events were declared, channel-authorized and
 * subscribed to by resources/js/echo.js, and nothing ever dispatched them: measured, six
 * listeners on the client against zero publishers, plus one publisher (PrizePayoutCompleted)
 * nobody listened to. A player's balance, bet, deposit and withdrawal rows therefore only ever
 * moved on a page reload, which is the one thing a lottery at a million concurrent players is
 * not supposed to need.
 *
 * The reason it stayed broken is structural, and it is the reason to publish from here rather
 * than from the services: the facts this lane reports are written in many places. Wallet balances
 * move in WalletService (3 sites), WalletHoldService (4), TransferPanelService and
 * AgentOnboardingService; deposit and withdrawal state moves through
 * FinancialStateTransitionService::transitionDeposit()/transitionWithdrawal(), which is itself
 * called by eight different services (webhooks, approvals, completions, refunds, disbursement).
 * A dispatch list is a promise that nobody will add a ninth caller, and every broken real-time
 * lane in this codebase started as exactly that promise. An observer on the row states the rule
 * once: when the number a player is waiting for changes, the player is told.
 *
 * WHY NOT `saved()` WITH A wasRecentlyCreated GUARD
 * That is what this file said first, and the test caught it: Eloquent sets wasRecentlyCreated to
 * true on insert and never sets it back, so a row created and then updated in the same request -
 * which is exactly the bet lifecycle, created Pending then markPlaced() in one request - would
 * have been swallowed and the lane would have looked implemented while staying dead. `created()`
 * and `updated()` are the framework's own answer to "is this the first write or a change", and
 * they are mutually exclusive, so nothing publishes twice.
 *
 * WHY IT IS SAFE TO EVENT FROM INSIDE A TRANSACTION
 * Each of these events declares $afterCommit, so the broadcast job is only pushed when the
 * surrounding transaction commits, and is dropped when it rolls back. Publishing a balance that
 * the database then rejected is a money bug with a friendly face - the player sees a number they
 * never had, and the next reload takes it away. See tests/Feature/Realtime, which measures
 * exactly that with a real queue connection rather than trusting this sentence.
 */
final class MoneyChainRealtimeObserver
{
    /**
     * A row that arrives already holding the fact is published on insert, because there is no
     * update to hang the notification on. Only bets and results can arrive that way: the two
     * facts a row can be complete about the moment it exists.
     */
    public function created(Model $model): void
    {
        if ($model instanceof Bet && (string) $model->status?->value === 'active') {
            event(new BetPlaced($model));
        }

        if ($model instanceof DrawResult && $model->published_at !== null) {
            $draw = Draw::query()->find($model->draw_id);

            if ($draw instanceof Draw) {
                event(new DrawResultPublished($draw, $model));
            }
        }
    }

    public function updated(Model $model): void
    {
        if ($model instanceof Wallet) {
            $this->wallet($model);
        } elseif ($model instanceof Bet) {
            $this->bet($model);
        } elseif ($model instanceof Deposit) {
            $this->deposit($model);
        } elseif ($model instanceof Withdrawal) {
            $this->withdrawal($model);
        } elseif ($model instanceof Draw) {
            $this->draw($model);
        } elseif ($model instanceof DrawResult) {
            $this->drawResult($model);
        }
    }

    private function wallet(Wallet $wallet): void
    {
        if (! $wallet->wasChanged(['balance', 'locked_balance'])) {
            return;
        }

        // One event per row save, whatever the save was doing: a settlement that pays one player
        // for fifty bets writes the wallet once and tells them once. That is why the payload
        // carries the balance itself and not a pointer to a ledger row.
        $changed = array_values(array_intersect(
            ['balance', 'locked_balance'],
            array_keys($wallet->getChanges())
        ));

        event(new WalletBalanceUpdated($wallet, implode('+', $changed)));
    }

    private function bet(Bet $bet): void
    {
        if (! $bet->wasChanged('status') || (string) $bet->status?->value !== 'active') {
            return;
        }

        event(new BetPlaced($bet));
    }

    private function deposit(Deposit $deposit): void
    {
        if ($deposit->wasChanged('status')) {
            event(new DepositStatusUpdated($deposit));
        }
    }

    private function withdrawal(Withdrawal $withdrawal): void
    {
        if ($withdrawal->wasChanged('status')) {
            event(new WithdrawalStatusUpdated($withdrawal));
        }
    }

    private function draw(Draw $draw): void
    {
        if ($draw->wasChanged(['status', 'is_open'])) {
            event(new DrawStatusUpdated($draw));
        }
    }

    private function drawResult(DrawResult $result): void
    {
        if (! $result->wasChanged('published_at') || $result->published_at === null) {
            return;
        }

        // preventLazyLoading() is on outside production, and a lazy $result->draw here would throw
        // on exactly the path that publishes results. One keyed read per publication is the honest
        // cost; a draw is published once, not once per ticket.
        $draw = Draw::query()->find($result->draw_id);

        if (! $draw instanceof Draw) {
            return;
        }

        // Same mechanism as every other hop: the event defers its own queue push to the commit,
        // so one rule covers six publishers and the test can check the rule instead of the list.
        event(new DrawResultPublished($draw, $result));
    }
}
