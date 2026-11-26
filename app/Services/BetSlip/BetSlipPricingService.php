<?php

declare(strict_types=1);

namespace App\Services\BetSlip;

use App\Enums\Currency;
use App\Models\BetSlip;
use App\Models\BetSlipItem;
use App\Services\Betting\BetCalculationService;
use App\Services\Betting\MarketPayoutService;
use App\ValueObjects\BetAmount;

/**
 * Prices a draft slip for DISPLAY. Strictly read-only.
 *
 * THE PRICE IS NOT A PROMISE
 * A potential win shown in the basket is the price the draw would pay RIGHT NOW,
 * computed by the existing Batch-4.2 calculator (BetCalculationService) with the
 * existing multiplier resolver (MarketPayoutService). It is recomputed on every
 * read and never stored, because the multiplier is configuration and the draw
 * can close while the member is still editing. The authoritative price is the
 * one the purchase pipeline computes at confirm time; if the two differ, the
 * pipeline's number is the one that is charged and this service says so in its
 * payload (`provisional: true`).
 *
 * WHAT IT DOES NOT DO
 * No wallet read, no balance check, no reservation, no write, no ledger. A
 * member with an empty wallet still sees prices — the affordability answer
 * belongs to the confirmation, which is the only place that can act on it
 * consistently with the wallet's row lock.
 */
final class BetSlipPricingService
{
    public function __construct(
        private readonly BetCalculationService $calculator,
        private readonly MarketPayoutService $payouts,
    ) {
    }

    /**
     * Price every item in the slip, plus the totals.
     *
     * Items whose market can no longer be priced (a market disabled by an
     * operator while the slip was open) are reported with `priceable: false` and
     * a reason, rather than silently dropped or priced at zero. The confirm path
     * will refuse them; the member needs to see which line is the problem.
     *
     * @return array{
     *     currency: string,
     *     items: list<array<string, mixed>>,
     *     total_stake: string,
     *     total_potential_win: string,
     *     provisional: bool,
     *     priceable: bool
     * }
     */
    public function price(BetSlip $slip): array
    {
        $items = $slip->items()->get();

        $totalStake = '0.00';
        $totalWin = '0.00';
        $allPriceable = true;
        $priced = [];

        foreach ($items as $item) {
            $line = $this->priceItem($item);

            $totalStake = bcadd($totalStake, $line['stake'], 2);

            if ($line['priceable'] && $line['potential_win'] !== null) {
                $totalWin = bcadd($totalWin, $line['potential_win'], 2);
            } else {
                $allPriceable = false;
            }

            $priced[] = $line;
        }

        return [
            'currency' => $slip->currency,
            'items' => $priced,
            'total_stake' => $totalStake,
            'total_potential_win' => $totalWin,
            'provisional' => true,
            'priceable' => $allPriceable,
        ];
    }

    /**
     * Price one item. Never throws: a refusal is part of the payload, because a
     * single unpriceable line must not blank the whole basket.
     *
     * @return array<string, mixed>
     */
    public function priceItem(BetSlipItem $item): array
    {
        $stake = $item->exactStake();

        $base = [
            'id' => $item->id,
            'game_code' => $item->game_code,
            'bet_type' => $item->bet_type,
            'number' => $item->number,
            'stake' => $stake,
            'funding_source' => $item->funding_source,
            'priceable' => false,
            'potential_win' => null,
            'multiplier' => null,
            'reason' => null,
        ];

        // canResolve() lives on PayoutMultiplierService; MarketPayoutService is
        // the authoritative resolver and throws for an unknown market, so the
        // probe is a cheap read of the same declared table rather than a second
        // implementation of the rule.
        if (! array_key_exists($item->game_code, (array) config('lottery.markets', []))) {
            return $base + ['reason' => 'market_not_priceable'];
        }

        try {
            $amount = BetAmount::of($stake, Currency::THB);
            $result = $this->calculator->calculateForMarket($amount, $item->game_code);
        } catch (\Throwable $e) {
            return $base + ['reason' => 'pricing_failed: '.$e->getMessage()];
        }

        return [
            'id' => $item->id,
            'game_code' => $item->game_code,
            'bet_type' => $item->bet_type,
            'number' => $item->number,
            'stake' => $stake,
            'funding_source' => $item->funding_source,
            'priceable' => true,
            'potential_win' => $result->potentialPayout->amount(),
            'multiplier' => $result->multiplier->value(),
            'reason' => null,
        ];
    }
}
