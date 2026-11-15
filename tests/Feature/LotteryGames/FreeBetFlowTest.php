<?php

declare(strict_types=1);

namespace Tests\Feature\LotteryGames;

use App\DTOs\LotteryGames\DigitBetSelection;
use App\Enums\BetStatus;
use App\Exceptions\LotteryGameException;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\FinancialTransaction;
use App\Models\GameBetAttribution;
use App\Models\User;
use App\Models\Wallet;
use App\Services\LotteryGames\FreeBetFundingAdapter;
use App\Services\LotteryGames\GameBetPlacementService;
use Tests\Support\LotteryGames\FakeFreeBetVoucherProvider;
use Tests\Support\LotteryGames\GameLaneTestCase;

/**
 * Funding a digit bet with a free-bet voucher - and refusing to when the voucher lane
 * cannot be named.
 *
 * WHY THE MOST IMPORTANT TEST IN THIS FILE IS THE ONE WHERE NOTHING HAPPENS
 * The Batch-1 free-bet voucher lane (issuance, expiry, consumption, reversal) does not
 * exist on this deployment. The tempting implementation is to accept
 * funding_source=free_bet, record a bet, and move on - the player gets a slip, the
 * screen looks right, and the operator has booked a liability that no money and no
 * voucher stands behind. Rule §0.6 makes that the one thing the lane may not do. So the
 * first thing this suite pins is that a free-bet placement on this deployment FAILS,
 * loudly, in this lane's vocabulary, with no bet, no reservation and no wallet movement.
 *
 * THE SEAM, THEN, IS A CONTRACT RATHER THAN A GUESS
 * The lane resolves a provider from config('lottery_games.funding.free_bet_provider').
 * When an operator points that at the real Batch-1 service, the lane must:
 *   1. VALIDATE the voucher read-only, so a quote cannot burn it;
 *   2. RESERVE the liability BEFORE spending anything, so a risk refusal costs no voucher;
 *   3. CONSUME once, keyed by the placement's idempotency key, so a retry cannot spend
 *      the same voucher twice;
 *   4. REVERSE on any later failure, after the exposure is released.
 * Each of those is asserted below with a recorded fake (FakeFreeBetVoucherProvider), and
 * the failure paths are asserted to leave NO bet and NO reservation behind.
 *
 * WHAT IS NOT ASSERTED: THAT THE PLAYER'S BALANCE IS MADE WHOLE
 * That is the voucher lane's job, and it is not installed. A test asserting a credit here
 * would be asserting money the digit-game lane never moved - the exact confusion §0.6
 * exists to prevent. This file asserts instead that the lane moves NO money of its own:
 * the only balance change is Batch 1's ordinary stake debit from the purchase path.
 *
 * MONEY IS COMPARED AS DECIMAL STRINGS.
 */
final class FreeBetFlowTest extends GameLaneTestCase
{
    private const VOUCHER = '987654';

    private function placement(): GameBetPlacementService
    {
        return app(GameBetPlacementService::class);
    }

    private function funding(): FreeBetFundingAdapter
    {
        return app(FreeBetFundingAdapter::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{game: \App\Models\LotteryGame, draw: Draw, user: User, wallet: Wallet}
     */
    private function ready(array $overrides = []): array
    {
        $lane = $this->openLane('lane_game', $overrides);
        $player = $this->player('1000.00');

        $this->limitFor($lane['draw'], '3d_direct', '123');

        return [...$lane, ...$player];
    }

    /**
     * Install the fake provider and return it.
     */
    private function installProvider(string $value = '10.00'): FakeFreeBetVoucherProvider
    {
        config()->set('lottery_games.funding.free_bet_provider', FakeFreeBetVoucherProvider::class);

        $provider = new FakeFreeBetVoucherProvider();
        $provider->value = $value;

        // The adapter resolves app($class) per call; the bound instance is the one the
        // tests inspect, and it is the same object every time.
        $this->app->instance(FakeFreeBetVoucherProvider::class, $provider);

        return $provider;
    }

    /**
     * @return array<string, mixed>
     */
    private function placeFreeBet(User $user, Draw $draw, string $seed, string $stake = '10.00'): array
    {
        return $this->placement()->place(
            userId: (int) $user->getKey(),
            selection: new DigitBetSelection('lane_game', '3d_direct', '123', $stake),
            drawId: (int) $draw->getKey(),
            idempotencyKey: $seed === '' ? '' : $this->key($seed),
            voucherId: self::VOUCHER,
            fundingSource: 'free_bet',
        );
    }

    /**
     * Everything that must be true after a free-bet placement was refused.
     */
    private function assertNothingWasPlaced(): void
    {
        $this->assertSame(0, Bet::query()->count(), 'a refused free bet buys nothing');
        $this->assertSame(0, GameBetAttribution::query()->count(), 'and attributes nothing');
    }

    // =========================================================================
    // The shipped deployment: fail closed
    // =========================================================================

    public function test_a_free_bet_is_refused_when_no_voucher_provider_is_installed(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        // This deployment, exactly as it ships: the Batch-1 voucher lane is not here.
        config()->set('lottery_games.funding.free_bet_provider', null);

        $this->assertFalse($this->funding()->isAvailable());

        try {
            $this->placeFreeBet($user, $draw, 'freebet-absent');
            $this->fail('a free bet cannot be funded by a lane that does not exist');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_funding_unavailable', $e->errorCode());
            $this->assertSame(
                'lottery_games.funding.free_bet_provider',
                (string) ($e->context()['config_key'] ?? ''),
                'the refusal names the configuration key an operator has to fill in',
            );
        }

        $this->assertNothingWasPlaced();
        $this->assertSame('1000.00', bcadd((string) $wallet->fresh()->balance, '0', 2), 'no stake left the wallet');
        $this->assertSame(0, FinancialTransaction::query()->count(), 'no transaction of any kind');
    }

    public function test_a_configured_class_that_cannot_fund_a_bet_is_also_refused(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        // A class exists, but it does not implement the contract. Calling into it would be
        // worse than refusing, because nothing about it can be relied on.
        config()->set('lottery_games.funding.free_bet_provider', \stdClass::class);

        $this->assertFalse($this->funding()->isAvailable());

        try {
            $this->placeFreeBet($user, $draw, 'freebet-wrong-class');
            $this->fail('a provider that does not implement the contract cannot fund a bet');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_funding_unavailable', $e->errorCode());
        }

        $this->assertNothingWasPlaced();
    }

    public function test_a_free_bet_placement_that_names_no_voucher_is_refused(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        $provider = $this->installProvider();

        try {
            $this->placement()->place(
                userId: (int) $user->getKey(),
                selection: new DigitBetSelection('lane_game', '3d_direct', '123', '10.00'),
                drawId: (int) $draw->getKey(),
                idempotencyKey: $this->key('freebet-novch'),
                fundingSource: 'free_bet',
            );
            $this->fail('free_bet funding without a voucher names no pot to spend');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_funding_unavailable', $e->errorCode());
        }

        $this->assertNothingWasPlaced();
        $this->assertCount(0, $provider->validations, 'nothing to validate without a voucher id');
        $this->assertSame(0, $provider->consumeCount());
    }

    // =========================================================================
    // The happy path, with the seam wired to a provider
    // =========================================================================

    public function test_a_covering_voucher_funds_the_bet_and_is_consumed_exactly_once(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $provider = $this->installProvider('10.00');

        $receipt = $this->placeFreeBet($user, $draw, 'freebet-happy');

        // 1. The receipt says the bet was funded by a free bet, not by cash.
        $this->assertSame('accepted', $receipt['status']);
        $this->assertSame('free_bet', $receipt['funding_source']);
        $this->assertSame('3d_direct', $receipt['market_key']);
        $this->assertSame('10.00', $receipt['stake']);

        // 2. The voucher was validated once and spent once, under the placement's key.
        // VALIDATION MAY HAPPEN TWICE; A SPEND MAY NOT.
        // The lane validates the voucher read-only to price the bet and to refuse an
        // underfunded stake before any reservation, and BetPurchaseService validates it
        // again immediately before spending. Both are reads, so both are free. What must
        // happen exactly once is the SPEND, under ONE key - and that key is the purchase's
        // own idempotency key, so the voucher lane can match a spend to the bet it paid for
        // and a retried request cannot spend twice.
        $this->assertGreaterThanOrEqual(1, count($provider->validations));
        foreach ($provider->validations as $validation) {
            $this->assertSame(self::VOUCHER, $validation['voucher_id']);
            $this->assertSame((int) $user->getKey(), $validation['user_id']);
        }

        $this->assertSame(1, $provider->consumeCount(), 'one voucher, one spend');
        $this->assertStringStartsWith(
            'betpurchase:',
            (string) $provider->consumptions[0]['idempotency_key'],
            'the spend is keyed by the BET it funds, not by a caller-local key',
        );
        $this->assertSame(0, $provider->reverseCount(), 'a successful placement gives nothing back');

        // 3. Where the money came from is recorded on BOTH records: the attribution says
        //    the bet was funded by a free bet, and the bet itself carries the voucher id so
        //    an operator can answer "which voucher paid for this slip?" without joining
        //    Batch 2's table.
        $attribution = GameBetAttribution::query()->findOrFail($receipt['attribution_id']);
        $this->assertSame('free_bet', $attribution->funding_source->value);

        $bet = Bet::query()->findOrFail($receipt['bet_id']);
        $this->assertSame('free_bet', (string) $bet->funding_source);
        $this->assertSame((int) self::VOUCHER, (int) $bet->free_bet_voucher_id);
        $this->assertSame('123', (string) $bet->game_number, 'the game facts sit beside the bet, not inside its rules');

        // 4. THE LANE MOVED NO MONEY OF ITS OWN. The only balance change is Batch 1's
        //    ordinary stake debit from the purchase path - one transaction, no credit.
        $this->assertSame('990.00', bcadd((string) $wallet->fresh()->balance, '0', 2));
        $this->assertSame(1, FinancialTransaction::query()->count(), 'exactly one transaction: the stake debit');
        $this->assertSame(
            0,
            FinancialTransaction::query()->where('type', \App\Enums\FinancialTransactionType::Payout->value)->count(),
            'a placement pays nobody, free bet or not',
        );

        $this->assertSame(BetStatus::Active, Bet::query()->findOrFail($receipt['bet_id'])->status);
    }

    public function test_a_replayed_placement_does_not_spend_the_voucher_a_second_time(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $provider = $this->installProvider('10.00');

        $first = $this->placeFreeBet($user, $draw, 'freebet-replay');
        $second = $this->placeFreeBet($user, $draw, 'freebet-replay');

        // The replay is reported as a replay, not as a new bet.
        $this->assertSame('accepted', $first['status']);
        $this->assertSame('replayed', $second['status']);
        $this->assertSame($first['bet_id'], $second['bet_id']);

        $this->assertSame(1, Bet::query()->count(), 'one bet, however many times the client retries');
        $this->assertSame(1, $provider->consumeCount(), 'one voucher spend, however many times the client retries');
        // One stake left the wallet, not two.
        $this->assertSame('990.00', bcadd((string) $wallet->fresh()->balance, '0', 2));
    }

    public function test_a_voucher_that_does_not_cover_the_stake_is_refused_before_anything_is_spent(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $provider = $this->installProvider('5.00');

        try {
            $this->placeFreeBet($user, $draw, 'freebet-short', '10.00');
            $this->fail('a 5.00 voucher cannot fund a 10.00 stake');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_funding_unavailable', $e->errorCode());
            $this->assertSame('voucher_value_exceeded', $e->getMessage());
            $this->assertSame('5.00', (string) ($e->context()['voucher_value'] ?? ''));
            $this->assertSame('10.00', (string) ($e->context()['stake'] ?? ''));
        }

        // The voucher was never spent and no money moved - the check happens before the
        // spend, and the stake is REPORTED rather than clamped: silently lowering the stake
        // would create a bet the player never asked for.
        $this->assertSame(0, $provider->consumeCount());
        $this->assertCount(1, $provider->validations, 'validated, so the refusal can name the value');
        $this->assertNothingWasPlaced();
        $this->assertSame('1000.00', bcadd((string) $wallet->fresh()->balance, '0', 2));
    }

    public function test_a_voucher_refused_by_the_provider_leaves_no_bet_and_no_reservation(): void
    {
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready();

        $provider = $this->installProvider('10.00');
        $provider->validationFailure = LotteryGameException::fundingUnavailable('This voucher has expired.', [
            'voucher_id' => self::VOUCHER,
        ]);

        try {
            $this->placeFreeBet($user, $draw, 'freebet-expired');
            $this->fail('an expired voucher cannot fund a bet');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_funding_unavailable', $e->errorCode());
            $this->assertSame('This voucher has expired.', $e->getMessage());
        }

        $this->assertNothingWasPlaced();
        $this->assertSame(0, $provider->consumeCount());
        $this->assertSame('1000.00', bcadd((string) $wallet->fresh()->balance, '0', 2));
    }

    // =========================================================================
    // Compensation: a risk refusal costs nothing, a later failure gives the voucher back
    // =========================================================================

    public function test_a_liability_refusal_leaves_the_voucher_unspent(): void
    {
        // A per-number cap of 5.00 against a 10.00 stake at 900x: the reservation must
        // refuse, and it does so BEFORE the spend (step 8 before step 9) precisely so that
        // a risk decision cannot burn a player's voucher.
        ['draw' => $draw, 'user' => $user, 'wallet' => $wallet] = $this->ready([
            'max_liability_per_number' => '5.00',
        ]);

        $provider = $this->installProvider('10.00');

        try {
            $this->placeFreeBet($user, $draw, 'freebet-cap');
            $this->fail('the per-number liability cap must refuse this placement');
        } catch (LotteryGameException $e) {
            $this->assertSame('game_liability_exceeded', $e->errorCode());
        }

        $this->assertSame(0, $provider->consumeCount(), 'a risk refusal never spends the voucher');
        $this->assertSame(0, $provider->reverseCount(), 'and therefore has nothing to give back');
        $this->assertNothingWasPlaced();
        $this->assertSame('1000.00', bcadd((string) $wallet->fresh()->balance, '0', 2));
    }

    public function test_a_purchase_failure_after_the_spend_gives_the_voucher_back(): void
    {
        // An empty wallet: the reservation succeeds (it is a liability decision, not a
        // funding one) and the spend happens, then Batch 1 refuses the purchase for want of
        // funds. This is the only order in which compensation can be exercised, and it is
        // the order that matters: the voucher must come back.
        $lane = $this->openLane('lane_game');
        $player = $this->player('0.00');

        $this->limitFor($lane['draw'], '3d_direct', '123');

        $provider = $this->installProvider('10.00');

        try {
            $this->placeFreeBet($player['user'], $lane['draw'], 'freebet-unfunded');
            $this->fail('a purchase with no funds must fail even when a voucher funded the bet');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        $this->assertSame(1, $provider->consumeCount(), 'the spend did happen');
        $this->assertSame(1, $provider->reverseCount(), 'and was given back');
        $this->assertSame(
            $provider->consumptions[0]['idempotency_key'],
            $provider->reversals[0]['idempotency_key'],
            'the reversal is keyed by the SAME key as the spend, so the voucher lane can match them',
        );

        $this->assertNothingWasPlaced();
        $this->assertSame('0.00', bcadd((string) $player['wallet']->fresh()->balance, '0', 2));
    }

    // =========================================================================
    // Quotes never spend
    // =========================================================================

    public function test_a_quote_checks_the_voucher_without_spending_it(): void
    {
        ['draw' => $draw, 'user' => $user] = $this->ready();

        $provider = $this->installProvider('10.00');

        $quote = $this->placement()->quote(
            gameCode: 'lane_game',
            betType: '3d_direct',
            number: '123',
            stake: '10.00',
            fundingSource: 'free_bet',
            voucherId: self::VOUCHER,
            userId: (int) $user->getKey(),
        );

        $this->assertTrue($quote['sellable']);
        $this->assertSame('free_bet', $quote['funding_source']);

        // A free bet does not return the stake. At the shipped
        // config('lottery_games.funding.free_bet_returns_stake') = false, a 10.00 stake at
        // 3d_direct's 900x quotes 8990.00 rather than 9000.00: the part of the payout that
        // belongs to the player is the MULTIPLIED amount, because the stake itself was never
        // theirs to win back. This is quoting policy, pinned here so a change to it is a
        // deliberate act rather than a silent reprice of every free bet.
        $this->assertSame('8990.00', $quote['potential_payout']);

        // A quote that spent the voucher would make the price of asking the price a bet.
        $this->assertSame(0, $provider->consumeCount(), 'a quote never consumes');
        $this->assertCount(1, $provider->validations);
        $this->assertNothingWasPlaced();
    }

    public function test_a_quote_refuses_an_underfunded_voucher_and_says_why(): void
    {
        ['user' => $user] = $this->ready();

        $this->installProvider('5.00');

        $quote = $this->placement()->quote(
            gameCode: 'lane_game',
            betType: '3d_direct',
            number: '123',
            stake: '10.00',
            fundingSource: 'free_bet',
            voucherId: self::VOUCHER,
            userId: (int) $user->getKey(),
        );

        $this->assertFalse($quote['sellable']);
        $this->assertSame('voucher_value_exceeded', $quote['reason']);
    }

    public function test_a_quote_without_a_voucher_identifier_refuses_free_bet_funding(): void
    {
        ['user' => $user] = $this->ready();

        $provider = $this->installProvider('10.00');

        $quote = $this->placement()->quote(
            gameCode: 'lane_game',
            betType: '3d_direct',
            number: '123',
            stake: '10.00',
            fundingSource: 'free_bet',
            voucherId: null,
            userId: (int) $user->getKey(),
        );

        $this->assertFalse($quote['sellable']);
        $this->assertSame('voucher_required', $quote['reason']);
        $this->assertCount(0, $provider->validations);
    }

    // =========================================================================
    // The adapter's own arithmetic
    // =========================================================================

    public function test_the_coverage_check_reports_a_voucher_of_unknown_value(): void
    {
        $this->installProvider();

        $this->assertSame('voucher_value_unknown', $this->funding()->coversStake(['voucher_id' => self::VOUCHER], '10.00'));
    }

    public function test_the_coverage_check_refuses_a_stake_that_is_not_positive(): void
    {
        $this->installProvider();

        $this->assertSame('stake_not_positive', $this->funding()->coversStake(['value' => '10.00'], '0.00'));
        $this->assertSame('stake_not_positive', $this->funding()->coversStake(['value' => '10.00'], '-1.00'));
    }

    public function test_the_coverage_check_accepts_a_stake_exactly_equal_to_the_value(): void
    {
        $this->installProvider();

        $this->assertNull(
            $this->funding()->coversStake(['value' => '10.00'], '10.00'),
            'a voucher for exactly the stake covers it',
        );
        $this->assertSame('voucher_value_exceeded', $this->funding()->coversStake(['value' => '10.00'], '10.01'));
    }
}
