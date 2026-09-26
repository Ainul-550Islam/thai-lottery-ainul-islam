<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Exceptions\GloSalesException;
use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * N3 prize calculator — draws winning 3-digit numbers with CSPRNG and prices
 * them from the live pool (GLO-7 variable engine).
 *
 * NUMBER DRAWING
 *   random_int(0, 999) formatted to a 3-digit digit-STRING (leading zeros
 *   preserved). An injectable $randomInt callable replaces CSPRNG in tests;
 *   production default is random_int only.
 *
 * PRICING
 *   Baht amounts come exclusively from GloN3PrizePoolAllocator::allocate()
 *   on the settlement-time pool. Fixed historical constants (3686, 749, 531,
 *   252116) are banned and never read.
 *
 * MATCHING
 *   Exact 3-digit string equality — never integer-cast (007 ≠ 7).
 */
class GloN3PrizeCalculator
{
    /** Banned fixed payouts — referenced only to prove they are unused. */
    public const BANNED_FIXED_PAYOUTS = ['3686', '749', '531', '252116'];

    private Closure $randomInt;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly GloN3PrizePoolAllocator $allocator,
        ?callable $randomInt = null,
    ) {
        $this->randomInt = $randomInt !== null
            ? Closure::fromCallable($randomInt)
            : static fn (int $min, int $max): int => random_int($min, $max);
    }

    /**
     * Replace the CSPRNG source (tests only). Production leaves random_int.
     *
     * @param  Closure(int, int): int  $randomInt
     */
    public function setRandomInt(callable $randomInt): void
    {
        $this->randomInt = Closure::fromCallable($randomInt);
    }

    /**
     * Draw one 3-digit winner as a digit string (000–999).
     *
     * @param  list<string>|null  $exclude  Already-drawn numbers to avoid
     */
    public function drawNumber(?array $exclude = null): string
    {
        $exclude = $exclude ?? [];
        $seen = array_fill_keys($exclude, true);

        // Bounded retries: with ≤1000 numbers the loop terminates.
        for ($i = 0; $i < 2000; $i++) {
            $n = ($this->randomInt)(0, 999);
            $formatted = sprintf('%03d', $n);

            if (! isset($seen[$formatted])) {
                return $formatted;
            }
        }

        throw GloSalesException::poolNotComputable('CSPRNG exhausted unique 3-digit space');
    }

    /**
     * Full draw set for one N3 settlement: unique digit strings per group.
     *
     * third.count may be null → derived from remaining pool after pricing
     * (each third winner gets floor(remaining_third_amount / unit) style
     * split decided by allocator amount / count when count is set; when null
     * we default to 100 winners and price accordingly — still pool-derived).
     *
     * @return array{
     *     numbers: array<string, list<string>>,
     *     allocation: array{
     *         pool: string,
     *         groups: array<string, array{amount: string, share: string, count: int|null}>,
     *         reserve: string,
     *         caps: array<string, string>,
     *         engine: string
     *     },
     *     per_winner: array<string, string>,
     *     engine: string
     * }
     */
    public function drawForPool(string $pool): array
    {
        $allocation = $this->allocator->allocate($pool);
        $used = [];

        $numbers = [];

        foreach (['special', 'first', 'second', 'third'] as $group) {
            $count = (int) ($allocation['groups'][$group]['count'] ?? 0);

            if ($count <= 0) {
                // null count → default 100 third-place style slots, still pool-priced.
                $count = $group === 'third' ? 100 : 1;
                $allocation['groups'][$group]['count'] = $count;
            }

            $list = [];

            for ($i = 0; $i < $count; $i++) {
                $number = $this->drawNumber($used);
                $used[] = $number;
                $list[] = $number;
            }

            $numbers[$group] = $list;
        }

        // Per-winner baht: group amount / count (floor to satang; remainder
        // stays in reserve — never rounded up into a fixed payout).
        $perWinner = [];

        foreach ($numbers as $group => $list) {
            $count = (string) max(count($list), 1);
            $amount = (string) $allocation['groups'][$group]['amount'];
            $perWinner[$group] = bcdiv($amount, $count, 2);
        }

        return [
            'numbers' => $numbers,
            'allocation' => $allocation,
            'per_winner' => $perWinner,
            'engine' => (string) $this->config->get('glo.n3.prize_engine.mode', 'pool_variable'),
        ];
    }

    /**
     * Whether a 3-digit digit-string ticket matches a recorded group number.
     * Exact string equality only — no int-cast.
     *
     * @param  list<string>  $recorded
     */
    public function matches(string $ticketNumber, array $recorded): bool
    {
        if (! preg_match('/^\d{3}$/', $ticketNumber)) {
            return false;
        }

        foreach ($recorded as $candidate) {
            if ((string) $candidate === $ticketNumber) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gross sales → pool → full draw in one call (settlement convenience).
     *
     * @return array{pool: string} & array
     */
    public function calculateFromGrossSales(string $grossSales): array
    {
        $pool = $this->allocator->poolFromGrossSales($grossSales);
        $result = $this->drawForPool($pool);
        $result['pool'] = $pool;
        $result['gross_sales'] = bcadd($grossSales, '0.00', 2);
        $result['pool_rate'] = $this->allocator->poolRate();

        return $result;
    }
}
