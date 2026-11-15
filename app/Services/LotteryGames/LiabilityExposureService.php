<?php

declare(strict_types=1);

namespace App\Services\LotteryGames;

use App\DTOs\LotteryGames\GameDefinition;
use App\Exceptions\LotteryGameException;
use App\Models\Draw;
use App\Models\GameNumberExposure;
use Illuminate\Support\Facades\DB;

/**
 * THE SOLE WRITER of game_number_exposures, and the only place a liability cap is
 * enforced.
 *
 * WHY CONCENTRATION RISK NEEDS ITS OWN COUNTER
 * A digit game's risk is not spread over its draw; it is concentrated on single
 * numbers. Four hundred players taking "0042" at a 900x multiplier is a liability
 * of 360,000 times the stake on one outcome, and no per-draw bet count would have
 * predicted it. So the counter that matters is per (draw, exact number, market),
 * and it must be updated atomically at placement and at cancellation or it drifts
 * away from the bets that justify it.
 *
 * THE TWO CAPS, AND WHY BOTH ARE NEEDED
 *   max_liability_per_number  stops one selection becoming existential.
 *   max_liability_per_draw    stops ten thousand individually-acceptable selections
 *                             adding up to the same thing.
 * Both are operator values read from config/lottery_games.php. If either is
 * unconfigured, reserve() refuses: an uncapped game is not a game with no limit,
 * it is a game nobody has decided the limit of (Rule §0.3, fail closed).
 *
 * WHY THE PEAK IS CHECKED, NOT THE CURRENT TOTAL
 * A reservation is refused when the total AFTER it would exceed the cap. Checking
 * "is the cap already exceeded" would let the very bet that breaches it through,
 * which is the only bet that matters. Over-limit is impossible by construction, so
 * release() can never be asked to undo a refused reservation.
 *
 * CONCURRENCY: ROW LOCK + UNIQUE KEY, BOTH
 * The unique key (draw_id, number, bet_type) guarantees one counter per selection
 * whatever the interleaving. Inside the transaction each counter is re-read with
 * lockForUpdate() so the check-then-increment sequence is serialised: without the
 * lock, two placements could both read 9,000 against a 10,000 cap, both add 1,000,
 * and both be accepted. Lock ordering is deterministic (numbers sorted before
 * locking) so two multi-selection placements cannot deadlock against each other.
 *
 * WHY reserve() TAKES A LIST
 * A single bet ticket can carry several selections. Reserving them one at a time
 * would let the first succeed and the second breach the cap, leaving a bet that is
 * half-reserved and an operator with a ticket that cannot be settled honestly. The
 * whole list is checked and written in one transaction: either every selection is
 * reserved or none is.
 *
 * RELEASE IS NOT THE MIRROR IMAGE OF RESERVE
 * release() never throws for a missing row: a cancellation may arrive twice, and a
 * second release of an already-released selection must be a zero-write no-op rather
 * than an error. It floors at zero for the same reason — a negative counter would
 * hand the operator phantom headroom.
 *
 * THIS CLASS MOVES NO MONEY
 * It reserves exposure, which is a number, not a balance. Stakes and payouts are
 * moved by the existing wallet/ledger purchase path (Rule §0.7).
 */
final class LiabilityExposureService
{
    public function __construct(
        private readonly GameRegistry $registry,
        private readonly PayoutCalculator $payouts,
    ) {}

    /**
     * Reserve liability for every selection of one placement, atomically.
     *
     * @param  list<array{number: string, bet_type: string, stake: string|int|float, potential_payout?: string|int|float}>  $selections
     * @return array{draw_id: int, game_code: string, reserved: list<array<string, string>>, per_number: array<string, string>, draw_potential_payout: string}
     *
     * @throws LotteryGameException game_not_configured|game_liability_exceeded|game_number_invalid
     */
    public function reserve(int $drawId, string $gameCode, array $selections): array
    {
        if ($selections === []) {
            // An empty reservation is a caller defect: it would open a transaction,
            // lock nothing and report success.
            throw LotteryGameException::releaseRefused(
                'A reservation must name at least one selection.',
                ['game_code' => $gameCode, 'draw_id' => $drawId],
            );
        }

        $game = $this->registry->definition($gameCode);

        $perNumberCap = $this->capValue($game, 'max_liability_per_number');
        $perDrawCap = $this->capValue($game, 'max_liability_per_draw');

        $rows = $this->normaliseSelections($game, $selections);

        return DB::transaction(function () use ($drawId, $gameCode, $rows, $perNumberCap, $perDrawCap): array {
            // THE DRAW ROW FIRST, THEN ITS COUNTERS - always in that order.
            //
            // Locking only the counter rows leaves a gap: on a draw with no counter yet,
            // the locking read matches nothing, so two first-ever placements hold no row
            // lock at all. On MySQL/InnoDB the empty range then becomes two shared gap
            // locks and the two inserts deadlock; on any engine the per-draw total they
            // each computed was computed from nothing. The draw row always exists, so
            // taking it first serialises every reservation on one draw, empty or not.
            $this->lockDraw($drawId);

            $locked = $this->lockDrawCounters($drawId, $gameCode);

            // The draw-level total is the sum of every live counter for this game on
            // this draw, recomputed under lock rather than cached, so a manual
            // reconciliation cannot leave a stale aggregate behind.
            $drawTotal = '0.00';

            foreach ($locked as $row) {
                $drawTotal = bcadd($drawTotal, $row->potentialPayoutTotal(), 2);
            }

            $reserved = [];
            $perNumber = [];

            foreach ($rows as $selection) {
                $key = $selection['bet_type'].'/'.$selection['number'];
                $row = $locked[$key] ?? null;

                $currentNumber = $row instanceof GameNumberExposure ? $row->potentialPayoutTotal() : '0.00';
                $nextNumber = bcadd($currentNumber, $selection['potential_payout'], 2);

                if (bccomp($nextNumber, $perNumberCap, 2) > 0) {
                    throw LotteryGameException::liabilityExceeded(
                        sprintf(
                            'Number %s of %s/%s would reach a potential payout of %s against a per-number cap of %s.',
                            $selection['number'],
                            $gameCode,
                            $selection['bet_type'],
                            $nextNumber,
                            $perNumberCap,
                        ),
                        [
                            'game_code' => $gameCode,
                            'draw_id' => $drawId,
                            'number' => $selection['number'],
                            'bet_type' => $selection['bet_type'],
                            'cap' => $perNumberCap,
                            'cap_scope' => 'per_number',
                            'would_be' => $nextNumber,
                            'current' => $currentNumber,
                        ],
                    );
                }

                $nextDraw = bcadd($drawTotal, $selection['potential_payout'], 2);

                if (bccomp($nextDraw, $perDrawCap, 2) > 0) {
                    throw LotteryGameException::liabilityExceeded(
                        sprintf(
                            'This bet would take the draw to a potential payout of %s against a per-draw cap of %s.',
                            $nextDraw,
                            $perDrawCap,
                        ),
                        [
                            'game_code' => $gameCode,
                            'draw_id' => $drawId,
                            'cap' => $perDrawCap,
                            'cap_scope' => 'per_draw',
                            'would_be' => $nextDraw,
                            'current' => $drawTotal,
                        ],
                    );
                }

                if (! $row instanceof GameNumberExposure) {
                    // Created at zero and then written through the same path as an
                    // existing row, so there is exactly one place that adds to a
                    // counter. The unique key makes a concurrent create impossible.
                    $row = GameNumberExposure::query()->create([
                        'draw_id' => $drawId,
                        'game_code' => $gameCode,
                        'number' => $selection['number'],
                        'bet_type' => $selection['bet_type'],
                        'stake_total' => '0.00',
                        'potential_payout_total' => '0.00',
                        'version' => 0,
                    ]);

                    $locked[$key] = $row;
                }

                $row->forceFill([
                    'stake_total' => bcadd($row->stakeTotal(), $selection['stake'], 2),
                    'potential_payout_total' => $nextNumber,
                    'version' => (int) $row->version + 1,
                ])->save();

                $drawTotal = $nextDraw;

                $reserved[] = [
                    'number' => $selection['number'],
                    'bet_type' => $selection['bet_type'],
                    'stake' => $selection['stake'],
                    'potential_payout' => $selection['potential_payout'],
                    'number_total' => $nextNumber,
                    'version' => (string) $row->version,
                ];

                $perNumber[$key] = $nextNumber;
            }

            return [
                'draw_id' => $drawId,
                'game_code' => $gameCode,
                'reserved' => $reserved,
                'per_number' => $perNumber,
                'draw_potential_payout' => $drawTotal,
            ];
        });
    }

    /**
     * Give reserved liability back, idempotently, without ever going negative.
     *
     * Called by the cancellation path AFTER the bet is definitively cancelled and
     * BEFORE any voucher is reversed (see ReleaseExposureOnBetCancelled: releasing
     * exposure last would leave the operator's cap consumed by a bet that no longer
     * exists if the voucher reversal failed).
     *
     * @param  list<array{number: string, bet_type: string, stake?: string|int|float, potential_payout?: string|int|float}>  $selections
     * @return array{draw_id: int, game_code: string, released: list<array<string, string>>, draw_potential_payout: string}
     */
    public function release(int $drawId, string $gameCode, array $selections): array
    {
        if ($selections === []) {
            return [
                'draw_id' => $drawId,
                'game_code' => $gameCode,
                'released' => [],
                'draw_potential_payout' => $this->reservedPotentialPayout($drawId, $gameCode),
            ];
        }

        $game = $this->registry->definition($gameCode);

        // The amounts to give back are the ones the caller recorded at placement.
        // They are NOT recomputed from the current configuration: a multiplier edit
        // between placement and cancellation must not change what is released.
        $rows = $this->normaliseSelections($game, $selections, release: true);

        return DB::transaction(function () use ($drawId, $gameCode, $rows): array {
            // Same lock order as reserve(): draw row, then counters. A release that took
            // them in the other order could deadlock against a placement.
            $this->lockDraw($drawId);

            $locked = $this->lockDrawCounters($drawId, $gameCode);
            $released = [];

            foreach ($rows as $selection) {
                $key = $selection['bet_type'].'/'.$selection['number'];
                $row = $locked[$key] ?? null;

                if (! $row instanceof GameNumberExposure) {
                    // Already released, or never reserved. A second release is a
                    // no-op by design, not an error.
                    continue;
                }

                $nextPayout = $this->floorAtZero(bcsub($row->potentialPayoutTotal(), $selection['potential_payout'], 2));
                $nextStake = $this->floorAtZero(bcsub($row->stakeTotal(), $selection['stake'], 2));

                if (bccomp($nextPayout, '0', 2) <= 0 && bccomp($nextStake, '0', 2) <= 0) {
                    // No liability left: the row is removed rather than left at zero,
                    // so "a row exists" always means "an exposure exists".
                    $row->delete();

                    $released[] = [
                        'number' => $selection['number'],
                        'bet_type' => $selection['bet_type'],
                        'potential_payout' => '0.00',
                        'deleted' => 'true',
                    ];

                    continue;
                }

                $row->forceFill([
                    'stake_total' => $nextStake,
                    'potential_payout_total' => $nextPayout,
                    'version' => (int) $row->version + 1,
                ])->save();

                $released[] = [
                    'number' => $selection['number'],
                    'bet_type' => $selection['bet_type'],
                    'potential_payout' => $nextPayout,
                    'deleted' => 'false',
                ];
            }

            $remaining = '0.00';

            foreach ($this->lockDrawCounters($drawId, $gameCode) as $row) {
                $remaining = bcadd($remaining, $row->potentialPayoutTotal(), 2);
            }

            return [
                'draw_id' => $drawId,
                'game_code' => $gameCode,
                'released' => $released,
                'draw_potential_payout' => $remaining,
            ];
        });
    }

    /**
     * The reserved potential payout for one exact selection, as a scale-2 string.
     */
    public function exposureFor(int $drawId, string $gameCode, string $number, string $betType): string
    {
        $row = $this->row($drawId, $gameCode, $number, $betType);

        return $row instanceof GameNumberExposure ? $row->potentialPayoutTotal() : '0.00';
    }

    /**
     * The reserved potential payout for a whole draw of one game.
     *
     * Aggregated in PHP with bcadd rather than SUM(), because the two drivers in
     * play (SQLite in tests, MySQL in production) round a decimal SUM differently
     * once it exceeds the column precision.
     */
    public function reservedPotentialPayout(int $drawId, string $gameCode): string
    {
        $total = '0.00';

        foreach ($this->rows($drawId, $gameCode) as $row) {
            $total = bcadd($total, $row->potentialPayoutTotal(), 2);
        }

        return $total;
    }

    /**
     * Every live counter for a draw of one game, ordered so reports are stable.
     *
     * @return list<GameNumberExposure>
     */
    public function rows(int $drawId, string $gameCode): array
    {
        /** @var list<GameNumberExposure> $rows */
        $rows = GameNumberExposure::query()
            ->where('draw_id', $drawId)
            ->where('game_code', $gameCode)
            ->orderBy('bet_type')
            ->orderBy('number')
            ->get()
            ->all();

        return $rows;
    }

    /**
     * The headroom left under each cap, for the placement path and the liability
     * screen. Never negative: the caps are enforced on the way in, so a negative
     * remainder would mean someone wrote a counter behind this service's back.
     *
     * @return array{per_number_cap: string, per_draw_cap: string, draw_reserved: string, draw_remaining: string, numbers: array<string, array{reserved: string, remaining: string}>}
     */
    public function headroom(int $drawId, string $gameCode): array
    {
        $game = $this->registry->definition($gameCode);

        $perNumberCap = $this->capValue($game, 'max_liability_per_number');
        $perDrawCap = $this->capValue($game, 'max_liability_per_draw');

        $numbers = [];
        $drawReserved = '0.00';

        foreach ($this->rows($drawId, $gameCode) as $row) {
            $key = $row->bet_type.'/'.$row->number;
            $reserved = $row->potentialPayoutTotal();

            $numbers[$key] = [
                'reserved' => $reserved,
                'remaining' => $this->floorAtZero(bcsub($perNumberCap, $reserved, 2)),
            ];

            $drawReserved = bcadd($drawReserved, $reserved, 2);
        }

        return [
            'per_number_cap' => $perNumberCap,
            'per_draw_cap' => $perDrawCap,
            'draw_reserved' => $drawReserved,
            'draw_remaining' => $this->floorAtZero(bcsub($perDrawCap, $drawReserved, 2)),
            'numbers' => $numbers,
        ];
    }

    /**
     * One counter row, or null.
     */
    public function row(int $drawId, string $gameCode, string $number, string $betType): ?GameNumberExposure
    {
        $row = GameNumberExposure::query()
            ->where('draw_id', $drawId)
            ->where('game_code', $gameCode)
            ->where('number', $number)
            ->where('bet_type', $betType)
            ->first();

        return $row instanceof GameNumberExposure ? $row : null;
    }

    /**
     * Take the row lock on the draw itself.
     *
     * The draw is the one row every reservation on that draw has in common, which is
     * what makes it the right serialisation point (see reserve()). The return value is
     * deliberately discarded: a draw that does not exist is refused by the foreign key
     * on the counter insert, with the database's own error, and duplicating that
     * decision here would be a second place that knows what a valid draw is.
     */
    private function lockDraw(int $drawId): void
    {
        Draw::query()->whereKey($drawId)->lockForUpdate()->first();
    }

    /**
     * Every counter for the draw of one game, locked, keyed by market/number.
     *
     * The deterministic order is what makes two multi-selection placements safe
     * against each other: they take the same locks in the same sequence.
     *
     * @return array<string, GameNumberExposure>
     */
    private function lockDrawCounters(int $drawId, string $gameCode): array
    {
        /** @var list<GameNumberExposure> $rows */
        $rows = GameNumberExposure::query()
            ->where('draw_id', $drawId)
            ->where('game_code', $gameCode)
            ->orderBy('bet_type')
            ->orderBy('number')
            ->lockForUpdate()
            ->get()
            ->all();

        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$row->bet_type.'/'.$row->number] = $row;
        }

        return $keyed;
    }

    /**
     * Validate, normalise and order the selections of one reservation.
     *
     * Ordering is by market then number so lock acquisition is deterministic. Each
     * selection must name a real market and a selection of the configured width:
     * reserving "42" for a 4-digit market would reserve the wrong liability for the
     * wrong number.
     *
     * @param  list<array<string, mixed>>  $selections
     * @return list<array{number: string, bet_type: string, stake: string, potential_payout: string}>
     */
    private function normaliseSelections(GameDefinition $game, array $selections, bool $release = false): array
    {
        $normalised = [];

        foreach ($selections as $selection) {
            $betType = (string) ($selection['bet_type'] ?? '');
            $rawNumber = $selection['number'] ?? '';

            if (! $game->hasBetType($betType)) {
                throw LotteryGameException::invalidNumber(
                    sprintf('Game "%s" does not sell bet type "%s".', $game->code, $betType),
                    ['game_code' => $game->code, 'bet_type' => $betType],
                );
            }

            $number = trim((string) $rawNumber);
            $expected = $game->selectionDigitsFor($betType);

            if ($number === '' || ! ctype_digit($number)) {
                throw LotteryGameException::invalidNumber(
                    'A reserved selection must be a non-empty digit string.',
                    ['game_code' => $game->code, 'bet_type' => $betType, 'number' => $number],
                );
            }

            if ($expected !== null && strlen($number) !== $expected) {
                throw LotteryGameException::invalidNumber(
                    sprintf('Reserved selection must be %d digits.', $expected),
                    ['game_code' => $game->code, 'bet_type' => $betType, 'number' => $number],
                );
            }

            $stake = $this->payouts->roundMoney($selection['stake'] ?? '0');
            $payout = array_key_exists('potential_payout', $selection)
                ? $this->payouts->roundMoney($selection['potential_payout'])
                // Only a release may omit the amount, and only because the caller is
                // giving back exactly what it recorded; a reserve always names it.
                : ($release ? '0.00' : $this->payouts->potentialPayout($game, $betType, $stake));

            $normalised[] = [
                'number' => $number,
                'bet_type' => $betType,
                'stake' => $stake,
                'potential_payout' => $payout,
            ];
        }

        usort(
            $normalised,
            static fn (array $left, array $right): int => [$left['bet_type'], $left['number']] <=> [$right['bet_type'], $right['number']],
        );

        return $normalised;
    }

    /**
     * The configured cap, or a fail-closed refusal.
     *
     * An unconfigured cap is REQUIRED_INPUT in config, which resolves to null. The
     * lane's answer to "what is the limit?" must never be "no limit".
     */
    private function capValue(GameDefinition $game, string $key): string
    {
        $value = $key === 'max_liability_per_draw'
            ? $game->maxLiabilityPerDraw
            : $game->maxLiabilityPerNumber;

        if ($value === null) {
            throw LotteryGameException::notConfigured(
                $game->code,
                [$key],
                sprintf('Game "%s" has no %s, so liability cannot be reserved for it.', $game->code, $key),
            );
        }

        if (! is_numeric($value)) {
            throw LotteryGameException::notConfigured(
                $game->code,
                [$key],
                sprintf('Game "%s" has a non-numeric %s.', $game->code, $key),
            );
        }

        return $this->payouts->roundMoney($value);
    }

    /**
     * Clamp a subtraction at zero: an exposure counter may never go negative.
     */
    private function floorAtZero(string $value): string
    {
        return bccomp($value, '0', 2) < 0 ? '0.00' : $value;
    }
}
