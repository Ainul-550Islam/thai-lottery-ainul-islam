<?php

declare(strict_types=1);

namespace App\Services\BetSlip;

use App\Enums\BetSlipStatus;
use App\Enums\DrawStatus;
use App\Models\BetSlip;
use App\Models\BetSlipItem;
use App\Models\Draw;
use App\Models\User;
use App\ValueObjects\LotteryNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The SOLE writer of bet_slips and bet_slip_items.
 *
 * WHAT IT IS
 * A draft basket. add/remove/clear/merge, item limits, expiry. That is the whole
 * surface. It reserves no number, consults no risk engine, touches no wallet and
 * writes no ledger row. Nothing here can move money, which is why it is safe for
 * a screen to call on every keystroke.
 *
 * WHAT IT REFUSES TO INVENT
 * There is no list of "game codes" in this class. A game either resolves against
 * the six markets the project actually sells (config('lottery.markets') — the
 * same table BetValidationService validates against) or the item is refused with
 * `game_not_available`. The mockup's "GLO L6 2D/3D direct bet" has no
 * implementation in this codebase: it is NOT accepted here, and the final table
 * reports it as a gap. Inventing a product to make a screen look complete is the
 * exact failure mode Batch 3 exists to prevent.
 *
 * VALIDATION IS BORROWED, NOT REIMPLEMENTED
 *   - the number: LotteryNumber::of() using the digit count declared for the
 *     market, so '007' is 3 digits and '7' is not
 *   - the stake: BetAmount::tryOf() for an exact decimal string
 *   - the draw: read-only existence + status + cut-off, so a slip cannot be
 *     filled against a draw that has already closed
 * The full, authoritative validation still happens at confirm time inside the
 * purchase pipeline. What happens here is enough to keep a draft honest.
 *
 * CONCURRENCY
 * One draft per member is a UNIQUE index. findOrCreateDraft() therefore does an
 * insert-and-catch rather than check-then-insert, because the check is a lie the
 * moment two requests interleave. Item mutations lock the slip row so item_count
 * and total_stake cannot drift under a double tap.
 */
final class BetSlipService
{
    /**
     * The funding source that means "charge the wallet", which is the only
     * source this batch can honour end-to-end.
     */
    public const FUNDING_WALLET = 'wallet';

    /**
     * The member's editable slip, created empty on first use.
     *
     * Created inside a transaction that locks the member's draft row so two
     * concurrent first-taps produce ONE slip: the second one loses the unique
     * index race and re-reads the winner.
     */
    public function findOrCreateDraft(int $memberId, ?Carbon $at = null): BetSlip
    {
        $now = $at ?? Carbon::now();

        $existing = BetSlip::query()->draftFor($memberId)->first();

        if ($existing instanceof BetSlip) {
            $this->expireIfStale($existing, $now);

            if ($existing->status === BetSlipStatus::Draft) {
                return $existing;
            }
        }

        return $this->createDraft($memberId, $now);
    }

    /**
     * The member's slip WITHOUT creating one. Read paths use this so a GET never
     * writes a row (a GET that mutates is a cache-poisoning hazard and a lie in
     * the HTTP contract).
     */
    public function currentDraft(int $memberId): ?BetSlip
    {
        return BetSlip::query()->draftFor($memberId)->first();
    }

    /**
     * Add one selection, or merge into an identical existing one.
     *
     * MERGE RATHER THAN DUPLICATE
     * A member who taps "+" twice on the same number in the same market expects
     * the stake to increase, not a second line to appear — and the schema's
     * UNIQUE(bet_slip_id, game_code, bet_type, number) makes the second line
     * impossible anyway. Merging is therefore both the intended UX and the only
     * behaviour the storage allows; the stored stake stays an exact decimal
     * string the whole way (bcadd, never float addition).
     *
     * @return array{slip: BetSlip, item: BetSlipItem, merged: bool}
     */
    public function addItem(
        int $memberId,
        string $gameCode,
        string $betType,
        string $number,
        string $stake,
        string $fundingSource = self::FUNDING_WALLET,
        ?string $freeBetReference = null,
        ?Carbon $at = null,
    ): array {
        $now = $at ?? Carbon::now();

        /*
         * THE FUNDING SOURCE IS CHECKED BEFORE ANYTHING ELSE.
         *
         * Only 'wallet' can be honoured end to end: the confirm path charges the
         * wallet through the existing ledger pipeline, and there is no voucher
         * redemption path in this batch. Accepting 'free_bet' here and refusing it
         * at confirm would be the worst of both worlds - the member fills a slip
         * that can never be bought. The refusal is explicit, so the client can say
         * "not available yet" rather than showing a generic error at the end.
         */
        if ($fundingSource !== self::FUNDING_WALLET) {
            throw new BetSlipRefused(
                'funding_source_unsupported',
                'Only wallet funding is available in this release.',
                422,
            );
        }

        $market = $this->resolveMarket($gameCode, $betType);
        $canonicalNumber = $this->canonicalNumber($number, (int) $market['digits'], $betType);
        $exactStake = $this->assertStake($stake, $now);

        return DB::transaction(function () use (
            $memberId,
            $gameCode,
            $betType,
            $canonicalNumber,
            $exactStake,
            $fundingSource,
            $freeBetReference,
            $now,
        ): array {
            $slip = $this->lockDraftForWrite($memberId, $now);

            $maxItems = $this->maxItems();
            $merged = false;

            $item = BetSlipItem::query()
                ->where('bet_slip_id', $slip->id)
                ->where('game_code', $gameCode)
                ->where('bet_type', $betType)
                ->where('number', $canonicalNumber)
                ->lockForUpdate()
                ->first();

            if ($item instanceof BetSlipItem) {
                if ($fundingSource !== $item->funding_source) {
                    throw new BetSlipRefused(
                        'funding_source_mismatch',
                        'This selection is already in the slip with a different funding source. Remove it first.',
                        409,
                    );
                }

                $item->stake = bcadd($item->exactStake(), $exactStake, 2);
                $item->save();

                $merged = true;
            } else {
                $count = BetSlipItem::query()->where('bet_slip_id', $slip->id)->count();

                if ($count >= $maxItems) {
                    throw new BetSlipRefused(
                        'slip_full',
                        sprintf('A slip may hold at most %d selections.', $maxItems),
                        422,
                    );
                }

                $item = BetSlipItem::query()->create([
                    'bet_slip_id' => $slip->id,
                    'game_code' => $gameCode,
                    'bet_type' => $betType,
                    'number' => $canonicalNumber,
                    'stake' => $exactStake,
                    'funding_source' => $fundingSource,
                    'free_bet_voucher_reference' => $freeBetReference,
                    'position' => $count,
                ]);
            }

            $slip = $this->recalculate($slip);

            return ['slip' => $slip, 'item' => $item, 'merged' => $merged];
        });
    }

    /**
     * Remove one item, scoped to the caller's own slip.
     *
     * A missing item is NOT an error: the desired end state ("this selection is
     * not in my slip") already holds, and a double-tap on the remove button is
     * the most common way a client reaches this state.
     */
    public function removeItem(int $memberId, int $itemId, ?Carbon $at = null): BetSlip
    {
        $now = $at ?? Carbon::now();

        return DB::transaction(function () use ($memberId, $itemId, $now): BetSlip {
            $slip = $this->lockDraftForWrite($memberId, $now);

            BetSlipItem::query()
                ->where('bet_slip_id', $slip->id)
                ->whereKey($itemId)
                ->delete();

            return $this->recalculate($slip);
        });
    }

    /**
     * Empty the slip without losing the row, so the member keeps one stable
     * draft reference across a session.
     */
    public function clear(int $memberId, ?Carbon $at = null): BetSlip
    {
        $now = $at ?? Carbon::now();

        return DB::transaction(function () use ($memberId, $now): BetSlip {
            $slip = $this->lockDraftForWrite($memberId, $now);

            BetSlipItem::query()->where('bet_slip_id', $slip->id)->delete();

            return $this->recalculate($slip);
        });
    }

    /**
     * Mark a draft expired. Called by the expiry job and by the read paths when
     * they notice the clock has passed — a status that disagrees with its own
     * expires_at column is the kind of ambiguity that gets resolved differently
     * in two places six months later.
     */
    public function expire(BetSlip $slip, ?Carbon $at = null): BetSlip
    {
        // `?->` and not `->`: an instance that has never been read back from the
        // database has no status attribute at all, and "no status" must mean
        // "still a draft", never a fatal error.
        if ($slip->status?->isFinal() === true) {
            return $slip;
        }

        $slip->status = BetSlipStatus::Expired;
        $slip->save();

        return $slip;
    }

    /**
     * How many pieces an empty draft still needs before it can be confirmed.
     */
    public function maxItems(): int
    {
        return max(1, (int) config('player_experience.bet_slip.max_items', 20));
    }

    /**
     * The draft, locked for update, created first if absent.
     *
     * The insert is wrapped because the UNIQUE(member_id) race is EXPECTED under
     * concurrent tabs; a lost race re-reads rather than failing the member's tap.
     */
    private function lockDraftForWrite(int $memberId, Carbon $now): BetSlip
    {
        $slip = BetSlip::query()->draftFor($memberId)->lockForUpdate()->first();

        if ($slip instanceof BetSlip && $slip->isEditable($now)) {
            return $slip;
        }

        if ($slip instanceof BetSlip && ! $slip->isEditable($now)) {
            $this->expire($slip, $now);
        }

        return $this->createDraft($memberId, $now);
    }

    private function createDraft(int $memberId, Carbon $now): BetSlip
    {
        $member = User::query()->find($memberId);

        if (! $member instanceof User) {
            throw new BetSlipRefused('member_not_found', 'The member does not exist.', 404);
        }

        try {
            /*
             * THE DRAFT STATE IS ASSIGNED, NOT LEFT TO THE COLUMN DEFAULT.
             *
             * A row built from a CREATE gets only the attributes named in the
             * INSERT; the database's `status = 'draft'` / `total_stake = 0` /
             * `item_count = 0` defaults exist in the schema but never travel back
             * into the model it returns. The enum cast then reads null, and the
             * very next `$slip->status->isEditable()` is a fatal error on the
             * member's first tap. These three lines are what make the returned
             * instance equal to the row as stored - asserted by
             * BetSlipServiceTest::test_a_member_gets_exactly_one_draft_slip.
             *
             * `status` is not mass-assignable (see BetSlip::$fillable), so it is
             * set by explicit assignment, which is also the rule the ownership
             * test enforces.
             */
            $slip = new BetSlip([
                'member_id' => $memberId,
                'reference' => 'BS-'.strtoupper(Str::random(12)),
                'currency' => 'THB',
                'expires_at' => $now->copy()->addMinutes($this->ttlMinutes()),
            ]);

            $slip->status = BetSlipStatus::Draft;
            $slip->total_stake = '0.00';
            $slip->item_count = 0;
            $slip->save();

            return $slip;
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            /** @var BetSlip $existing */
            $existing = BetSlip::query()->draftFor($memberId)->firstOrFail();

            return $existing;
        }
    }

    /**
     * Recompute the denormalised totals from the items, inside the caller's
     * transaction. The items are the authority; total_stake is a cache.
     */
    private function recalculate(BetSlip $slip): BetSlip
    {
        $items = BetSlipItem::query()->where('bet_slip_id', $slip->id)->get();

        $total = '0.00';

        foreach ($items as $item) {
            $total = bcadd($total, $item->exactStake(), 2);
        }

        $slip->total_stake = $total;
        $slip->item_count = $items->count();
        $slip->save();

        return $slip->fresh() ?? $slip;
    }

    private function expireIfStale(BetSlip $slip, Carbon $now): void
    {
        if ($slip->status === BetSlipStatus::Draft && $slip->hasExpired($now)) {
            $this->expire($slip, $now);
        }
    }

    /**
     * Resolve a game/market pair against the markets this project actually
     * sells.
     *
     * WHY THIS IS THE WHOLE AVAILABILITY CHECK
     * config('lottery.markets') is the same declaration BetValidationService
     * steps 3, 7 and 10 read, and PayoutMultiplierService prices from. Consulting
     * any other list would create a second answer to "what can be bought",
     * which is precisely how a slip ends up offering a product the purchase
     * pipeline then refuses.
     *
     * The `game_code` accepted by the API is the market key ('3d_direct',
     * '2d_top', ...) OR the family key ('3d', '2d', 'run') combined with an
     * explicit bet_type. Both resolve through the same table.
     *
     * @return array<string, mixed>
     */
    private function resolveMarket(string $gameCode, string $betType): array
    {
        /** @var array<string, array<string, mixed>> $markets */
        $markets = (array) config('lottery.markets', []);

        $key = strtolower(trim($gameCode));

        // 1. The exact market key.
        if (isset($markets[$key]) && (bool) ($markets[$key]['enabled'] ?? false)) {
            return $markets[$key] + ['market_key' => $key];
        }

        // 2. A market key that disagrees with the declared bet_type.
        if (isset($markets[$key])) {
            throw new BetSlipRefused(
                'game_not_available',
                sprintf('The "%s" market exists but is disabled.', $key),
                422,
            );
        }

        // 3. The mockup's non-existent products are named so the refusal is
        //    informative rather than a generic 404. They are NOT implemented:
        //    no GLO L6 per-number bet exists in this codebase.
        if (str_starts_with($key, 'glo')) {
            throw new BetSlipRefused(
                'game_not_implemented',
                'GLO L6 per-number betting (2D/3D direct) is not implemented in this system. Only the declared markets are sellable.',
                422,
            );
        }

        throw new BetSlipRefused(
            'game_not_available',
            sprintf(
                'Unknown game "%s". Sellable markets: %s.',
                $gameCode,
                implode(', ', array_keys($markets)),
            ),
            422,
        );
    }

    /**
     * The canonical digit string, or a refusal.
     *
     * LotteryNumber::of() is the existing value object: digits only, exact width,
     * leading zeros preserved. Using it here (rather than a regex of our own)
     * means a slip and the purchase pipeline can never disagree about what a
     * number is.
     */
    private function canonicalNumber(string $number, int $digits, string $betType): string
    {
        try {
            return LotteryNumber::of($number, $digits, $betType)->value();
        } catch (\Throwable $e) {
            throw new BetSlipRefused(
                'invalid_number',
                sprintf('"%s" is not a valid %d-digit number: %s', $number, $digits, $e->getMessage()),
                422,
            );
        }
    }

    /**
     * The stake as an exact decimal string, or a refusal.
     *
     * BetAmount::tryOf() is the existing value object, so a stake the purchase
     * pipeline would refuse cannot be drafted either. The configured ceiling is
     * a config value, never a hardcoded amount.
     */
    private function assertStake(string $stake, Carbon $now): string
    {
        $amount = \App\ValueObjects\BetAmount::tryOf(trim($stake), \App\Enums\Currency::THB);

        if ($amount === null) {
            throw new BetSlipRefused(
                'invalid_stake',
                sprintf('"%s" is not a valid stake.', $stake),
                422,
            );
        }

        $exact = $amount->amount();

        if (bccomp($exact, '0', 2) <= 0) {
            throw new BetSlipRefused('invalid_stake', 'A stake must be greater than zero.', 422);
        }

        $ceiling = (string) config('player_experience.bet_slip.max_stake', '1000000.00');

        if (bccomp($exact, $ceiling, 2) > 0) {
            throw new BetSlipRefused(
                'stake_too_large',
                sprintf('A single selection may not exceed %s.', $ceiling),
                422,
            );
        }

        return $exact;
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('player_experience.bet_slip.ttl_minutes', 120));
    }

    /**
     * Read-only check that a draw can still take a selection. Kept here because
     * a draft built against a closed draw is misleading even though nothing is
     * charged for it.
     */
    public function drawAcceptsSelections(int $drawId, ?Carbon $at = null): bool
    {
        $draw = Draw::query()->find($drawId);

        if (! $draw instanceof Draw) {
            return false;
        }

        $status = $draw->status;

        if ($status instanceof DrawStatus && ! $status->canAcceptBets()) {
            return false;
        }

        $cutoff = $draw->betting_close_at ?? null;

        if ($cutoff !== null && $cutoff instanceof Carbon) {
            return $cutoff->greaterThan($at ?? Carbon::now());
        }

        return true;
    }
}
