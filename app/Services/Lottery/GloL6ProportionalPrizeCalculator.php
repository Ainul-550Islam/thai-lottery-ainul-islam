<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Exceptions\GloSalesException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * L6 proportional prize calculator (GLO official 6-digit product).
 *
 * VERIFIED BASELINE RULES (config/glo.l6):
 *   ticket_price          80.00 THB
 *   full_allocation       48,000,000.00 THB across 14,168 prizes at full sell-out
 *   full_sale_units       1,000,000 units
 *   proportional unsold   each tier's paid amount scales as sold / 1,000,000
 *
 * Money is BCMath string decimal only — no float, no pre-round. The calculator
 * never invents baht amounts: it scales the DECLARED allocation by the sold
 * fraction. Unsold remainder is retained (not re-split into fake winners).
 */
class GloL6ProportionalPrizeCalculator
{
    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * Sold fraction as an exact decimal string with 12 fraction digits
     * (enough for unit-level precision; callers round money at 2 dp only
     * when writing currency columns via bcadd(..., 2)).
     */
    public function soldFraction(int $unitsSold, int $unitsFull = 1000000): string
    {
        if ($unitsFull <= 0) {
            throw GloSalesException::invalidConfiguration('glo.l6.full_sale_units', 'must be positive');
        }

        if ($unitsSold < 0) {
            throw GloSalesException::invalidUnits('units_sold must be non-negative');
        }

        if ($unitsSold > $unitsFull) {
            throw GloSalesException::overCapacity($unitsSold, $unitsFull);
        }

        return bcdiv((string) $unitsSold, (string) $unitsFull, 12);
    }

    /**
     * Gross prize pot for a draw given units sold: full_allocation × fraction,
     * floored to satang (scale 2) — never exceeds declared allocation.
     */
    public function allocatedGross(int $unitsSold, ?int $unitsFull = null): string
    {
        $full = $unitsFull ?? (int) ($this->config->get('glo.l6.full_sale_units', 1000000));
        $allocation = $this->fullAllocation();
        $fraction = $this->soldFraction($unitsSold, $full);

        return bcmul($allocation, $fraction, 2);
    }

    /**
     * Declared full-sell-out allocation as a 2-decimal string.
     */
    public function fullAllocation(): string
    {
        $raw = (string) $this->config->get('glo.l6.full_allocation', '48000000.00');

        return bcadd($raw, '0.00', 2);
    }

    /**
     * Ticket price as a 2-decimal string.
     */
    public function ticketPrice(): string
    {
        return bcadd((string) $this->config->get('glo.l6.ticket_price', '80.00'), '0.00', 2);
    }

    /**
     * Official total prize count at full sell-out (14,168).
     */
    public function totalPrizeCount(): int
    {
        return (int) $this->config->get('glo.l6.total_prize_count', 14168);
    }

    /**
     * Per-tier proportional amount at a given sold level.
     *
     * The official ladder's tier amounts are declared in config('glo.prizes')
     * per ticket. Proportional unsold scales each tier's TOTAL pot
     * (amount × winners) by the sold fraction, then the settlement engine
     * divides among actual winners in that tier (never below 0).
     *
     * @return array{
     *     units_sold: int,
     *     units_full: int,
     *     fraction: string,
     *     full_allocation: string,
     *     allocated_gross: string,
     *     gross_sales: string,
     *     ticket_price: string,
     *     total_prize_count: int,
     *     tiers: list<array{tier: string, full_pot: string, proportional_pot: string, winners: int}>
     * }
     */
    public function proportionalBreakdown(int $unitsSold, ?int $unitsFull = null): array
    {
        $full = $unitsFull ?? (int) ($this->config->get('glo.l6.full_sale_units', 1000000));
        $fraction = $this->soldFraction($unitsSold, $full);
        $price = $this->ticketPrice();
        $grossSales = bcmul($price, (string) $unitsSold, 2);
        $allocated = $this->allocatedGross($unitsSold, $full);
        $prizes = (array) $this->config->get('glo.prizes', []);

        $tiers = [];

        foreach ($prizes as $tier => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $amount = bcadd((string) ($entry['amount'] ?? '0.00'), '0.00', 2);
            $winners = (int) ($entry['winners'] ?? 0);
            $fullPot = bcmul($amount, (string) $winners, 2);
            $proportionalPot = bcmul($fullPot, $fraction, 2);

            $tiers[] = [
                'tier' => (string) $tier,
                'full_pot' => $fullPot,
                'proportional_pot' => $proportionalPot,
                'winners' => $winners,
            ];
        }

        return [
            'units_sold' => $unitsSold,
            'units_full' => $full,
            'fraction' => $fraction,
            'full_allocation' => $this->fullAllocation(),
            'allocated_gross' => $allocated,
            'gross_sales' => $grossSales,
            'ticket_price' => $price,
            'total_prize_count' => $this->totalPrizeCount(),
            'tiers' => $tiers,
        ];
    }

    /**
     * Expected gross for N units: price × units (BCMath, scale 2).
     */
    public function grossForUnits(int $units): string
    {
        if ($units < 0) {
            throw GloSalesException::invalidUnits('units must be non-negative');
        }

        return bcmul($this->ticketPrice(), (string) $units, 2);
    }
}
