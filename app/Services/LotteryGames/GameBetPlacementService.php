<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\DTOs\BetPurchaseData;
use App\DTOs\LotteryGames\DigitBetSelection;
use App\DTOs\LotteryGames\GameDefinition;
use App\Enums\BetFundingSource;
use App\Exceptions\LotteryGameException;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\GameBetAttribution;
use App\Services\Betting\BetPurchaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * THE ONLY WAY A DIGIT-GAME BET IS CREATED.
 *
 * WHY A SINGLE ENTRY POINT
 * Placing a game bet is five decisions that must agree: is the game sellable, which
 * draw is it for, is the selection a real selection of that market, would it breach a
 * liability cap, and can the stake be funded. If a controller, a job and a seeder each
 * sequenced those four steps themselves, one of them would eventually reserve exposure
 * without checking the cap, or debit a wallet and then discover the number was
 * malformed. So the sequence lives here, once, and the HTTP layer is reduced to
 * validation-plus-delegation.
 *
 * THE ORDER, AND WHY IT IS THIS ORDER
 *   1  normalise the selection   (refuses "42" for a 4-digit market)
 *   2  availability gates        (all nine, or nothing proceeds)
 *   3  resolve the draw          (the ONE draw a bet may belong to, window included)
 *   4  stake bounds              (from configuration)
 *   5  map the market            (which existing config('lottery.markets') key settles it)
 *   6  fund                      (voucher validated/consumed only for free bets)
 *   7  reserve exposure          (a counter, before any money moves)
 *   8  purchase                  (the EXISTING Batch-1 wallet/ledger purchase path)
 *   9  attribute                 (the game facts, in the batch-2 table; retried, audited)
 *
 *   6b recover                   if Batch 1 already holds a purchase for exactly this
 *                                request but no attribution exists (the process died
 *                                between the two commits), finish the attribution and
 *                                reserve / buy / spend NOTHING a second time
 *
 * Every placement, every failed placement and every recovery is written to the lane's
 * append-only audit trail (GameAuditRecorder) with who, when and the facts it rested on.
 *
 * Exposure is reserved BEFORE the purchase and given back if the purchase fails. The
 * reverse order — purchase, then reserve — would take a player's money and then refuse
 * the bet because the cap was full, which is the one failure a player would never
 * forgive. Reserving first means the worst case is a reservation that is compensated
 * immediately (step 8's catch), and compensation is a single counter decrement inside
 * the same request.
 *
 * WHY THERE IS NO SINGLE TRANSACTION AROUND EVERYTHING
 * The Batch-1 purchase owns its own transaction, its own row locks, its own ledger
 * entries and its own idempotency record. Wrapping it in a second outer transaction
 * would hold a liability lock across a wallet commit and make a deadlock between two
 * simultaneous placements not merely possible but likely. So this class is deliberately
 * a compensating sequence: every step that can fail after an earlier step succeeded has
 * an explicit undo, and the undos run in reverse order (unwind(): release exposure
 * first, then reverse the voucher).
 *
 * ABOUT THE DRAW LOCK
 * The spec's order names "lock draw" between the cutoff check and the reservation. That
 * lock is taken INSIDE LiabilityExposureService::reserve() (the draw row first, then the
 * counters), because the lock must live exactly as long as the read-check-increment it
 * protects: a lock taken here and held across the Batch-1 purchase would be the
 * outer-transaction deadlock described above.
 *
 * THIS CLASS MOVES NO MONEY ITSELF
 * No wallet is read, no ledger entry is written, no balance is computed. The stake is
 * spent by BetPurchaseService::purchase() — the same call a 2D or 3D bet uses — which is
 * what satisfies Rule §0.7 and §0.2 at once. What this class adds is the digit-game
 * meaning of the bet, and it stores that in game_bet_attributions rather than in
 * bets.metadata, because Batch 1 hashes that column into its ownership proof.
 */
final class GameBetPlacementService
{
    /**
     * How many times attributing a bet that already exists is attempted before the
     * failure is recorded and surfaced. The bet is bought and its liability is
     * reserved by then, so the only sensible thing to do with a transient database
     * error is to try the (idempotent) attribution again.
     */
    private const ATTRIBUTION_ATTEMPTS = 3;

    public function __construct(
        private readonly GameRegistry $registry,
        private readonly GameAvailabilityService $availability,
        private readonly DigitNumberValidator $validator,
        private readonly PayoutCalculator $payouts,
        private readonly LiabilityExposureService $exposure,
        private readonly UpcomingDrawsService $upcoming,
        private readonly FreeBetFundingAdapter $funding,
        private readonly BetPurchaseService $purchaseService,
        private readonly GameAuditRecorder $audit,
    ) {}

    /**
     * Place one game bet and return its receipt.
     *
     * @param  int  $drawId  the draw the caller believes it is betting on. Zero means
     *                       "the open draw of this game", which is what the API uses;
     *                       an explicit id is honoured only if it is still
     *                       accepting bets, so a stale client cannot bet into a
     *                       closed draw.
     * @return array{
     *     bet_id: int,
     *     game_code: string,
     *     bet_type: string,
     *     number: string,
     *     stake: string,
     *     potential_payout: string,
     *     market_key: string,
     *     funding_source: string,
     *     status: string,
     *     draw_id: int,
     *     attribution_id: int
     * }
     *
     * @throws LotteryGameException
     */
    public function place(
        int $userId,
        DigitBetSelection $selection,
        int $drawId,
        string $idempotencyKey,
        ?string $voucherId = null,
        ?string $fundingSource = null,
    ): array {
        if (trim($idempotencyKey) === '') {
            // The purchase path needs the key for its own replay protection, and a
            // free-bet consumption needs it to avoid spending a voucher twice.
            throw LotteryGameException::notSellable(
                'game_idempotency_key_required',
                ['game_code' => $selection->gameCode],
            );
        }

        $game = $this->registry->definition($selection->gameCode);
        $source = BetFundingSource::of($fundingSource);

        // 1. The selection must be a real selection of a real market.
        $number = $this->validator->normalize($game, $selection->betType, $selection->number);
        $stake = $this->payouts->roundMoney($selection->stake);

        // 2. A REPLAY returns the original placement instead of making a second one.
        //
        // This check is here - before the gates, before the draw, before the reservation -
        // because that is what idempotency means: a retry of a request the platform has
        // already accepted must receive the same answer, not "the draw has since closed".
        // It is also the only defence against the expensive failure: without it a replayed
        // key reserves the liability a SECOND time (the counter has no memory of the key),
        // spends nothing (Batch 1 replays the purchase), and leaves the operator carrying
        // double the exposure it believes it has on that number.
        $replay = $this->existingPlacement($userId, $idempotencyKey);

        if ($replay instanceof GameBetAttribution) {
            return $this->replayReceipt($replay, $game, $selection, $number, $stake);
        }

        // 3. All nine gates.
        $this->availability->assertSellable($game->code);

        // 4. The draw, with its betting window already checked.
        $draw = $this->resolveDraw($game, $drawId);

        // 5. Stake bounds.
        $violation = $this->payouts->stakeViolation($game, $stake);

        if ($violation !== null) {
            throw LotteryGameException::notSellable($violation, [
                'game_code' => $game->code,
                'stake' => $stake,
                'min_stake' => $game->minStake,
                'max_stake' => $game->maxStake,
            ]);
        }

        // 6. Which existing market settles this bet.
        $marketKey = $game->marketKeyFor($selection->betType);

        if ($marketKey === null || trim($marketKey) === '') {
            throw LotteryGameException::notConfigured(
                $game->code,
                ['settlement_market_key', 'bet_types.'.$selection->betType.'.market_key'],
                sprintf(
                    'Bet type "%s" of game "%s" is not mapped to a config("lottery.markets") key, '
                    .'so the bet could never settle.',
                    $selection->betType,
                    $game->code,
                ),
            );
        }

        // The mapped market must be able to hold a selection of this width. Without this
        // check a market with no `market_key` of its own falls back to the game's default
        // settlement market, and a three-digit exact bet would then be sold into a
        // two-digit suffix market - priced by one rule set and settled by another.
        $this->assertMarketAcceptsWidth($game, $selection->betType, $marketKey);

        $potentialPayout = $this->payouts->potentialPayout($game, $selection->betType, $stake, $source);

        // The purchase this placement makes, built ONCE so the recovery check below and
        // the real purchase can never disagree about what is being bought.
        //
        // NOTHING batch-2 specific goes in metadata on purpose: Batch 1 hashes
        // bets.metadata into its ownership proof (Rule §0.2).
        $purchaseData = new BetPurchaseData(
            userId: $userId,
            drawId: $draw->getKey(),
            marketKey: $marketKey,
            rawNumber: $number,
            rawStake: $stake,
            idempotencyKey: $idempotencyKey,
            metadata: [],
            ignoredClientFields: [],
        );

        // 6b. RECOVERY: an earlier attempt bought this bet and died before attributing it.
        //
        // The attribution is the lane's only memory of a placement (step 2's replay check
        // reads it). If the process died between the purchase committing and the
        // attribution committing, a retry finds no attribution, reaches this line, and
        // would otherwise reserve the liability a SECOND time and then have Batch 1 replay
        // the purchase - leaving the operator carrying double the exposure on that number
        // for a bet that exists once. Batch 1 can answer "does a purchase already exist
        // for exactly this request" (existingPurchaseFor), so ask it BEFORE reserving or
        // funding anything.
        //
        // The earlier attempt reserved its exposure BEFORE it purchased, so that
        // reservation is still held: this path reserves nothing, buys nothing, spends no
        // voucher, and only writes the missing attribution. It sits ahead of the funding
        // check on purpose: a free-bet voucher is already spent by then, and validating it
        // again would refuse a recovery of a bet the player has already paid for.
        $alreadyBought = $this->purchaseService->existingPurchaseFor($purchaseData);

        if ($alreadyBought !== null) {
            return $this->recoverPlacement(
                $userId,
                $alreadyBought->betId(),
                $draw,
                $game,
                $selection,
                $number,
                $stake,
                $potentialPayout,
                $marketKey,
                $source,
                $idempotencyKey,
                $voucherId,
            );
        }

        // 7. Funding. A free bet must be validated AND covered before anything is
        // spent, and is consumed only after the exposure reservation succeeds, so a
        // cap refusal never leaves a spent voucher behind.
        $voucher = null;

        if ($source->requiresVoucherReversal()) {
            if ($voucherId === null || trim($voucherId) === '') {
                throw LotteryGameException::fundingUnavailable(
                    'A free-bet placement must name a voucher.',
                    ['game_code' => $game->code],
                );
            }

            $voucher = $this->funding->validateUsable($userId, $voucherId);

            $coverage = $this->funding->coversStake($voucher, $stake);

            if ($coverage !== null) {
                throw LotteryGameException::fundingUnavailable($coverage, [
                    'game_code' => $game->code,
                    'voucher_id' => $voucherId,
                    'stake' => $stake,
                    'voucher_value' => $voucher['value'] ?? null,
                ]);
            }
        }

        // 8. Reserve the liability. This is the step that can legitimately refuse, so
        // it happens before any money or voucher is spent.
        $reservation = $this->exposure->reserve($draw->getKey(), $game->code, [[
            'number' => $number,
            'bet_type' => $selection->betType,
            'stake' => $stake,
            'potential_payout' => $potentialPayout,
        ]]);

        // 9. Spend. Everything from here on is compensated by unwind() on failure.
        //
        // THE VOUCHER IS SPENT BY THE PURCHASE SERVICE, NOT HERE.
        // BetPurchaseService::purchase() takes an optional free_bet_voucher_id and routes it
        // to FreeBetFundingAdapter itself, keyed by the purchase's own idempotency key, and
        // gives it back if the purchase fails. Funding therefore has ONE owner for the whole
        // platform instead of one per caller - a second consume() call here was a second
        // money path wearing a different name, and it also keyed the spend by a DIFFERENT
        // key from the bet it funded, so a retry could have burned a voucher without buying
        // anything.
        //
        // This lane keeps only its own responsibility: the liability reservation above, and
        // the release of it if the purchase refuses (unwind(), with no voucher of its own to
        // hand back).
        try {
            $result = $this->purchaseService->purchase(
                $purchaseData,
                $source->requiresVoucherReversal() && is_string($voucherId) ? $voucherId : null,
            );
        } catch (Throwable $e) {
            $this->audit->record(
                GameAuditRecorder::BET_PLACEMENT_FAILED,
                $game->code,
                $userId,
                'draw',
                $draw->getKey(),
                [
                    'bet_type' => $selection->betType,
                    'number' => $number,
                    'stake' => $stake,
                    'potential_payout' => $potentialPayout,
                    'funding_source' => $source->value,
                    'idempotency_key' => $idempotencyKey,
                    'error' => $e::class,
                    'reason' => $e->getMessage(),
                ],
            );

            $this->unwind($userId, $draw->getKey(), $game->code, $number, $selection->betType, $stake, $potentialPayout, null, $idempotencyKey, $e->getMessage());

            throw $e;
        }

        // 10. Attribute. The bet now exists; the game facts are recorded about it.
        //
        // From here the bet is bought and its liability is reserved, so a failure may NOT
        // release anything: it can only be retried and, failing that, recorded loudly. The
        // retry that the CALLER makes is then safe because of step 6b.
        $attribution = $this->attributeWithRetry(
            $userId,
            $result->betId(),
            $draw,
            $game,
            $selection->betType,
            $number,
            $stake,
            $potentialPayout,
            $marketKey,
            $source,
            $idempotencyKey,
            $voucherId,
            $reservation['draw_potential_payout'],
        );

        $this->audit->record(
            GameAuditRecorder::BET_PLACED,
            $game->code,
            $userId,
            'bet',
            $result->betId(),
            [
                'draw_id' => (int) $draw->getKey(),
                'bet_type' => $selection->betType,
                'number' => $number,
                'stake' => $stake,
                'potential_payout' => $potentialPayout,
                'market_key' => $marketKey,
                'funding_source' => $source->value,
                'idempotency_key' => $idempotencyKey,
                'draw_potential_payout' => $reservation['draw_potential_payout'],
                'rules_checksum' => $game->checksum(),
            ],
        );

        Log::info('lottery_games.bet_placed', [
            'bet_id' => $result->betId(),
            'game_code' => $game->code,
            'draw_id' => $draw->getKey(),
            'bet_type' => $selection->betType,
            'funding_source' => $source->value,
            'potential_payout' => $potentialPayout,
            'draw_potential_payout' => $reservation['draw_potential_payout'],
        ]);

        return [
            'bet_id' => $result->betId(),
            'game_code' => $game->code,
            'bet_type' => $selection->betType,
            'number' => $number,
            'stake' => $stake,
            'potential_payout' => $potentialPayout,
            'market_key' => $marketKey,
            'funding_source' => $source->value,
            'status' => 'accepted',
            'draw_id' => (int) $draw->getKey(),
            'attribution_id' => (int) $attribution->getKey(),
        ];
    }

    /**
     * What a bet would cost and pay, without placing anything.
     *
     * Deliberately shares the validator, the calculator and the draw resolution with
     * place(): a quote that used different arithmetic or a different draw than the
     * placement would be worse than no quote at all.
     *
     * @return array{game_code: string, bet_type: string, number: string, stake: string, potential_payout: string, match_mode: string|null, market_key: string|null, funding_source: string, draw_id: int|null, seconds_remaining: int|null, sellable: bool, reason: string, missing: list<string>}
     */
    public function quote(
        string $gameCode,
        string $betType,
        string $number,
        string $stake,
        ?string $fundingSource = null,
        ?string $voucherId = null,
        ?int $userId = null,
    ): array {
        $game = $this->registry->definition($gameCode);
        $source = BetFundingSource::of($fundingSource);

        $normalised = $this->validator->normalize($game, $betType, $number);
        $normalisedStake = $this->payouts->roundMoney($stake);

        $capability = $this->availability->capability($gameCode);
        $next = $this->upcoming->summary($gameCode);

        $reason = $capability->reason;
        $payout = '0.00';
        $marketKey = $game->marketKeyFor($betType);

        // The same width guard place() applies, reported as a refusal instead of thrown:
        // a quote's job is to answer "could this be sold", and "no, and here is why" is an
        // answer. Sharing the guard is what stops the two paths from disagreeing.
        try {
            if (is_string($marketKey) && trim($marketKey) !== '') {
                $this->assertMarketAcceptsWidth($game, $betType, $marketKey);
            }
        } catch (LotteryGameException $e) {
            $reason = $e->errorCode();
        }

        if ($capability->isSellable() && $reason === $capability->reason) {
            $violation = $this->payouts->stakeViolation($game, $normalisedStake);

            if ($violation !== null) {
                $reason = $violation;
            } else {
                $payout = $this->payouts->potentialPayout($game, $betType, $normalisedStake, $source);

                if ($source->requiresVoucherReversal()) {
                    // A quote must never consume a voucher, only check it.
                    try {
                        $voucher = $userId !== null && $voucherId !== null
                            ? $this->funding->validateUsable($userId, $voucherId)
                            : null;
                        $coverage = $voucher === null ? 'voucher_required' : $this->funding->coversStake($voucher, $normalisedStake);

                        if ($coverage !== null) {
                            $reason = $coverage;
                        }
                    } catch (LotteryGameException $e) {
                        $reason = $e->errorCode();
                    }
                }
            }
        }

        return [
            'game_code' => $game->code,
            'bet_type' => $betType,
            'number' => $normalised,
            'stake' => $normalisedStake,
            'potential_payout' => $payout,
            'match_mode' => $game->matchModeFor($betType)?->value,
            'market_key' => $marketKey,
            'funding_source' => $source->value,
            'draw_id' => $next['draw_id'],
            'seconds_remaining' => $next['seconds_remaining'],
            'sellable' => $reason === 'sellable' && bccomp($payout, '0', 2) > 0,
            'reason' => $reason,
            'missing' => $capability->missing,
        ];
    }

    /**
     * Compensate a placement that failed after its reservation.
     *
     * ORDER IS THE WHOLE POINT: the exposure is released FIRST, then the voucher is
     * reversed. If the voucher reversal throws, the operator's liability cap is
     * already correct — the counter no longer holds liability for a bet that does not
     * exist — and the only outstanding item is an unreversed voucher, which is a
     * reconcilable, visible state. The reverse order would leave reserved liability
     * behind on a bet that never existed, which nothing reconciles automatically.
     *
     * A compensation never re-throws: the caller is already handling the original
     * failure, and losing that error behind a second one would hide the root cause.
     * Every step is logged.
     *
     * @param  list<array{number: string, bet_type: string, stake: string, potential_payout: string}>|null  $selections
     * @return array{released: bool, voucher_reversed: bool}
     */
    public function unwind(
        int $userId,
        int $drawId,
        string $gameCode,
        string $number,
        string $betType,
        string $stake,
        string $potentialPayout,
        ?string $voucherId,
        string $idempotencyKey,
        string $reason,
        ?array $selections = null,
    ): array {
        $released = false;
        $reversed = false;

        try {
            $this->exposure->release($drawId, $gameCode, $selections ?? [[
                'number' => $number,
                'bet_type' => $betType,
                'stake' => $stake,
                'potential_payout' => $potentialPayout,
            ]]);

            $released = true;
        } catch (Throwable $e) {
            Log::error('lottery_games.unwind.release_failed', [
                'user_id' => $userId,
                'draw_id' => $drawId,
                'game_code' => $gameCode,
                'number' => $number,
                'bet_type' => $betType,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }

        if ($voucherId !== null) {
            try {
                $this->funding->reverse($userId, $voucherId, $idempotencyKey);
                $reversed = true;
            } catch (Throwable $e) {
                Log::error('lottery_games.unwind.voucher_reverse_failed', [
                    'user_id' => $userId,
                    'voucher_id' => $voucherId,
                    'reason' => $reason,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['released' => $released, 'voucher_reversed' => $reversed];
    }

    /**
     * The placement already made with this key by this user, if any.
     *
     * Scoped to the user because an idempotency key is generated by a CLIENT. Two players
     * choosing the same key is a coincidence, not a replay, and treating it as one would
     * hand the second player the first player's bet.
     */
    private function existingPlacement(int $userId, string $idempotencyKey): ?GameBetAttribution
    {
        $attribution = GameBetAttribution::query()
            ->where('idempotency_key', $idempotencyKey)
            ->whereHas('bet', static fn ($query) => $query->where('user_id', $userId))
            ->orderBy('id')
            ->first();

        return $attribution instanceof GameBetAttribution ? $attribution : null;
    }

    /**
     * The receipt for a placement that already happened.
     *
     * The amount is the FROZEN one from the attribution, never a fresh calculation: the
     * multiplier may have changed since, and a replay that re-priced the bet would
     * disagree with the liability actually reserved for it.
     *
     * A key reused for a DIFFERENT bet is refused rather than replayed. Returning the old
     * receipt would silently drop the new bet on the floor; placing it would break the
     * caller's contract that one key means one bet.
     *
     * @return array<string, mixed>
     *
     * @throws LotteryGameException game_not_sellable when the key belongs to another bet
     */
    private function replayReceipt(
        GameBetAttribution $attribution,
        GameDefinition $game,
        DigitBetSelection $selection,
        string $number,
        string $stake,
    ): array {
        $sameBet = (string) $attribution->game_code === $game->code
            && (string) $attribution->bet_type === $selection->betType
            && (string) $attribution->number === $number
            && bccomp((string) $attribution->stake, $stake, 2) === 0;

        if (! $sameBet) {
            throw LotteryGameException::notSellable(
                'game_idempotency_key_reused',
                [
                    'game_code' => $game->code,
                    'idempotency_key' => (string) $attribution->idempotency_key,
                    'original' => [
                        'game_code' => (string) $attribution->game_code,
                        'bet_type' => (string) $attribution->bet_type,
                        'number' => (string) $attribution->number,
                        'stake' => (string) $attribution->stake,
                    ],
                    'requested' => [
                        'game_code' => $game->code,
                        'bet_type' => $selection->betType,
                        'number' => $number,
                        'stake' => $stake,
                    ],
                ],
            );
        }

        Log::info('lottery_games.bet_placement_replayed', [
            'bet_id' => (int) $attribution->bet_id,
            'game_code' => $game->code,
            'idempotency_key' => (string) $attribution->idempotency_key,
        ]);

        return [
            'bet_id' => (int) $attribution->bet_id,
            'game_code' => $game->code,
            'bet_type' => (string) $attribution->bet_type,
            'number' => (string) $attribution->number,
            'stake' => bcadd((string) $attribution->stake, '0', 2),
            'potential_payout' => bcadd((string) $attribution->potential_payout, '0', 2),
            'market_key' => (string) $attribution->market_key,
            'funding_source' => $attribution->funding_source instanceof BetFundingSource
                ? $attribution->funding_source->value
                : (string) $attribution->funding_source,
            'status' => 'replayed',
            'draw_id' => (int) $attribution->draw_id,
            'attribution_id' => (int) $attribution->getKey(),
        ];
    }

    /**
     * Refuse when the market a bet type maps to cannot hold a selection of that width.
     *
     * WHY THIS EXISTS DESPITE GameDefinition::marketKeyFor()
     * marketKeyFor() answers "which existing market key does this bet type use", and it
     * falls back to the game-level `settlement_market_key` when a bet type declares none.
     * That fallback is convenient and, on its own, unsafe: the game-level key is a market
     * with its OWN digit width (2d_bottom is two digits, 3d_direct is three), so a
     * three-digit market inheriting a two-digit default would sell a bet that Batch 1
     * then refuses to register - or, if the widths happened to agree, one that settles
     * against the wrong digit rule. Both are silent mispricing.
     *
     * The comparison is done HERE rather than inside the DTO because it needs
     * config('lottery.markets'), and a value object that reads configuration is no longer
     * a description of a configuration: it becomes a second place that knows the layout.
     *
     * An UNKNOWN market key is left alone: Batch 1's own validator refuses it with a
     * better message, and duplicating that refusal here would mean two codes for one
     * defect.
     */
    private function assertMarketAcceptsWidth(GameDefinition $game, string $betType, string $marketKey): void
    {
        $market = config('lottery.markets.'.$marketKey);

        if (! is_array($market)) {
            return;
        }

        $marketWidth = (int) ($market['digits'] ?? 0);
        $selectionWidth = $game->selectionDigitsFor($betType);

        if ($marketWidth <= 0 || $selectionWidth === null || $marketWidth === $selectionWidth) {
            return;
        }

        throw LotteryGameException::notConfigured(
            $game->code,
            ['bet_types.'.$betType.'.market_key'],
            sprintf(
                'Bet type "%s" of game "%s" is %d digit(s) but the market it maps to ("%s") holds %d: '
                .'declare a market_key for this bet type, or the bet would be priced and settled by different rules.',
                $betType,
                $game->code,
                $selectionWidth,
                $marketKey,
                $marketWidth,
            ),
        );
    }

    /**
     * Finish a placement whose purchase already exists but whose attribution does not.
     *
     * See step 6b of place(). Nothing is reserved, bought or spent here: the earlier
     * attempt did all of that before it died. The receipt reports `replayed`, which is
     * what a retry of an accepted request is.
     *
     * @return array<string, mixed>
     *
     * @throws Throwable when the attribution still cannot be written
     */
    private function recoverPlacement(
        int $userId,
        int $betId,
        Draw $draw,
        GameDefinition $game,
        DigitBetSelection $selection,
        string $number,
        string $stake,
        string $potentialPayout,
        string $marketKey,
        BetFundingSource $source,
        string $idempotencyKey,
        ?string $voucherId,
    ): array {
        $attribution = $this->attributeWithRetry(
            $userId,
            $betId,
            $draw,
            $game,
            $selection->betType,
            $number,
            $stake,
            $potentialPayout,
            $marketKey,
            $source,
            $idempotencyKey,
            $voucherId,
            null,
        );

        $this->audit->record(
            GameAuditRecorder::BET_ATTRIBUTION_RECOVERED,
            $game->code,
            $userId,
            'bet',
            $betId,
            [
                'draw_id' => (int) $draw->getKey(),
                'bet_type' => $selection->betType,
                'number' => $number,
                'stake' => $stake,
                'potential_payout' => $potentialPayout,
                'funding_source' => $source->value,
                'idempotency_key' => $idempotencyKey,
            ],
        );

        Log::warning('lottery_games.attribution_recovered', [
            'bet_id' => $betId,
            'game_code' => $game->code,
            'idempotency_key' => $idempotencyKey,
        ]);

        return [
            'bet_id' => $betId,
            'game_code' => $game->code,
            'bet_type' => $selection->betType,
            'number' => $number,
            'stake' => $stake,
            'potential_payout' => $potentialPayout,
            'market_key' => $marketKey,
            'funding_source' => $source->value,
            'status' => 'replayed',
            'draw_id' => (int) $draw->getKey(),
            'attribution_id' => (int) $attribution->getKey(),
        ];
    }

    /**
     * Attribute a bet that exists, trying again on a transient failure.
     *
     * attribute() is a single transaction that writes the five bets columns and the
     * attribution row, so a failed attempt leaves neither; trying again is safe. When
     * every attempt fails the failure is written to the audit trail with everything an
     * operator needs to repair the bet by hand, and the last error is rethrown: the
     * caller's own retry is then handled by step 6b of place().
     *
     * @throws Throwable the last attribution error
     */
    private function attributeWithRetry(
        int $userId,
        int $betId,
        Draw $draw,
        GameDefinition $game,
        string $betType,
        string $number,
        string $stake,
        string $potentialPayout,
        string $marketKey,
        BetFundingSource $source,
        string $idempotencyKey,
        ?string $voucherId,
        ?string $drawPotentialPayout,
    ): GameBetAttribution {
        $last = null;

        for ($attempt = 1; $attempt <= self::ATTRIBUTION_ATTEMPTS; $attempt++) {
            try {
                return $this->attribute($betId, $draw, $game, $betType, $number, $stake, $potentialPayout, $marketKey, $source, $idempotencyKey, $voucherId);
            } catch (Throwable $e) {
                $last = $e;

                Log::warning('lottery_games.attribution_attempt_failed', [
                    'bet_id' => $betId,
                    'game_code' => $game->code,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->audit->record(
            GameAuditRecorder::BET_ATTRIBUTION_FAILED,
            $game->code,
            $userId,
            'bet',
            $betId,
            [
                'draw_id' => (int) $draw->getKey(),
                'bet_type' => $betType,
                'number' => $number,
                'stake' => $stake,
                'potential_payout' => $potentialPayout,
                'draw_potential_payout' => $drawPotentialPayout,
                'funding_source' => $source->value,
                'idempotency_key' => $idempotencyKey,
                'attempts' => self::ATTRIBUTION_ATTEMPTS,
                'error' => $last?->getMessage(),
            ],
        );

        Log::critical('lottery_games.attribution_failed', [
            'bet_id' => $betId,
            'game_code' => $game->code,
            'idempotency_key' => $idempotencyKey,
            'error' => $last?->getMessage(),
        ]);

        throw $last ?? new \RuntimeException('attribution failed without an error');
    }

    /**
     * The draw this bet belongs to.
     *
     * An explicit id is checked rather than trusted: a client that quotes a draw and
     * then submits after the cutoff must be refused, not silently re-pointed at a
     * later draw, because the stake it agreed to was priced against the draw it saw.
     */
    private function resolveDraw(GameDefinition $game, int $drawId): Draw
    {
        if ($drawId === 0) {
            return $this->upcoming->drawForPlacement($game->code);
        }

        $draw = Draw::query()->find($drawId);

        if (! $draw instanceof Draw || (string) $draw->game_type !== $game->code) {
            throw LotteryGameException::notSellable('game_draw_unavailable', [
                'game_code' => $game->code,
                'draw_id' => $drawId,
            ]);
        }

        if (! $draw->isOpenForBetting()) {
            // The window closed between the quote and the placement.
            throw LotteryGameException::notSellable('game_draw_closed', [
                'game_code' => $game->code,
                'draw_id' => $drawId,
                'status' => $draw->status->value,
            ]);
        }

        return $draw;
    }

    /**
     * Record the game facts of a bet that now exists.
     *
     * Two writes, both additive: one row in game_bet_attributions (everything
     * descriptive) and the five new NULLABLE columns on bets (the ones an operator
     * joins on). Nothing existing is modified, and bets.metadata is not touched.
     */
    private function attribute(
        int $betId,
        Draw $draw,
        GameDefinition $game,
        string $betType,
        string $number,
        string $stake,
        string $potentialPayout,
        string $marketKey,
        BetFundingSource $source,
        string $idempotencyKey,
        ?string $voucherId,
    ): GameBetAttribution {
        return DB::transaction(function () use ($betId, $draw, $game, $betType, $number, $stake, $potentialPayout, $marketKey, $source, $idempotencyKey, $voucherId): GameBetAttribution {
            Bet::query()->whereKey($betId)->update([
                'game_code' => $game->code,
                'game_number' => $number,
                'game_bet_type' => $betType,
                'funding_source' => $source->value,
                'free_bet_voucher_id' => $voucherId !== null && ctype_digit($voucherId) ? (int) $voucherId : null,
            ]);

            return GameBetAttribution::query()->create([
                'bet_id' => $betId,
                'draw_id' => (int) $draw->getKey(),
                'game_code' => $game->code,
                'bet_type' => $betType,
                'number' => $number,
                'stake' => $stake,
                'potential_payout' => $potentialPayout,
                'market_key' => $marketKey,
                'funding_source' => $source->value,
                'idempotency_key' => $idempotencyKey,
                'metadata' => [
                    'match_mode' => $game->matchModeFor($betType)?->value,
                    'selection_digits' => $game->selectionDigitsFor($betType),
                ],
            ]);
        });
    }
}
