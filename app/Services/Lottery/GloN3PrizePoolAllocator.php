<?php

declare(strict_types=1);

namespace App\Services\Lottery;

use App\Exceptions\GloSalesException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * N3 prize-pool allocator (variable engine — GLO-7 baseline).
 *
 * VERIFIED BASELINE RULES (config('glo.n3')):
 *   ticket_price   20.00 THB
 *   pool_rate      0.60 → pool = gross_sales × 0.60 (BCMath only)
 *   group caps     first ≤ 30% of pool, second ≤ 30%, third ≤ 39%
 *   special        ≥ 1% of pool
 *   NO fixed payouts: amounts are always derived from the live pool.
 *   Banned constants (3686/749/531/252116) are never used.
 *
 * The allocator returns SHARE PERCENTAGES and absolute baht amounts computed
 * from the provided pool string. It does not pick numbers — that is
 * GloN3PrizeCalculator's CSPRNG job.
 */
class GloN3PrizePoolAllocator
{
    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * Compute the N3 pool from gross sales: gross × pool_rate (scale 2).
     */
    public function poolFromGrossSales(string $grossSales): string
    {
        $gross = $this->assertMoney($grossSales);
        $rate = $this->poolRate();

        return bcmul($gross, $rate, 2);
    }

    public function poolRate(): string
    {
        $rate = (string) $this->config->get('glo.n3.pool_rate', '0.60');

        if (! preg_match('/^\d+(\.\d+)?$/', $rate)) {
            throw GloSalesException::invalidConfiguration('glo.n3.pool_rate', 'must be a decimal string');
        }

        return $rate;
    }

    public function ticketPrice(): string
    {
        return bcadd((string) $this->config->get('glo.n3.ticket_price', '20.00'), '0.00', 2);
    }

    /**
     * Allocate absolute baht across groups from a computed pool, honouring
     * caps/special minimums. Returns exact 2-decimal strings.
     *
     * Allocation policy (deterministic, no floats):
     *  1. special = max(1% of pool, configured min share) — at least special.
     *  2. remaining = pool − special
     *  3. first  takes min(30% pool, share of remaining per config weights)
     *  4. second takes min(30% pool, …)
     *  5. third  takes min(39% pool, remainder of remaining after first+second)
     *  6. reserve = pool − (special+first+second+third)  — never distributed
     *     as a fixed payout.
     *
     * @return array{
     *     pool: string,
     *     groups: array<string, array{amount: string, share: string, count: int|null}>,
     *     reserve: string,
     *     caps: array<string, string>,
     *     engine: string
     * }
     */
    public function allocate(string $pool): array
    {
        $pool = $this->assertMoney($pool);

        if (bccomp($pool, '0.00', 2) <= 0) {
            throw GloSalesException::poolNotComputable('pool must be positive');
        }

        $groups = (array) $this->config->get('glo.n3.prize_engine.groups', []);

        if ($groups === []) {
            throw GloSalesException::invalidConfiguration('glo.n3.prize_engine.groups', 'missing');
        }

        $onePercent = bcmul($pool, '0.01', 2);
        $caps = [
            'first' => bcmul($pool, '0.30', 2),
            'second' => bcmul($pool, '0.30', 2),
            'third' => bcmul($pool, '0.39', 2),
        ];

        // Special: at least 1% of pool.
        $specialDeclared = (string) (($groups['special']['min_share'] ?? '0.01'));
        $specialFromShare = bcmul($pool, $specialDeclared, 2);
        $specialAmount = bccomp($specialFromShare, $onePercent, 2) >= 0
            ? $specialFromShare
            : $onePercent;
        $specialAmount = $this->floor2($specialAmount);

        $remainingAfterSpecial = bcsub($pool, $specialAmount, 2);

        // Greedy fill first → second → third under caps; third gets what is
        // left of remaining (still capped at 39% of pool).
        $firstWanted = $this->groupWanted($groups, 'first', $pool, $remainingAfterSpecial);
        $firstAmount = $this->minBc($firstWanted, $caps['first']);
        $firstAmount = $this->minBc($firstAmount, $remainingAfterSpecial);
        $firstAmount = $this->floor2($firstAmount);

        $afterFirst = bcsub($remainingAfterSpecial, $firstAmount, 2);
        $secondWanted = $this->groupWanted($groups, 'second', $pool, $afterFirst);
        $secondAmount = $this->minBc($secondWanted, $caps['second']);
        $secondAmount = $this->minBc($secondAmount, $afterFirst);
        $secondAmount = $this->floor2($secondAmount);

        $afterSecond = bcsub($afterFirst, $secondAmount, 2);
        $thirdWanted = $this->groupWanted($groups, 'third', $pool, $afterSecond);
        $thirdAmount = $this->minBc($thirdWanted, $caps['third']);
        $thirdAmount = $this->minBc($thirdAmount, $afterSecond);
        $thirdAmount = $this->floor2($thirdAmount);

        $distributed = bcadd(bcadd($specialAmount, $firstAmount, 2), bcadd($secondAmount, $thirdAmount, 2), 2);
        $reserve = bcsub($pool, $distributed, 2);

        // share = amount / pool (12 dp).
        $share = fn (string $amount): string => bcdiv($amount, $pool, 12);

        $built = [
            'special' => [
                'amount' => $specialAmount,
                'share' => $share($specialAmount),
                'count' => (int) ($groups['special']['count'] ?? 1),
            ],
            'first' => [
                'amount' => $firstAmount,
                'share' => $share($firstAmount),
                'count' => (int) ($groups['first']['count'] ?? 1),
            ],
            'second' => [
                'amount' => $secondAmount,
                'share' => $share($secondAmount),
                'count' => (int) ($groups['second']['count'] ?? 1),
            ],
            'third' => [
                'amount' => $thirdAmount,
                'share' => $share($thirdAmount),
                'count' => isset($groups['third']['count']) && $groups['third']['count'] !== null
                    ? (int) $groups['third']['count']
                    : null,
            ],
        ];

        // Invariant: special ≥ 1% of pool.
        if (bccomp($built['special']['amount'], $onePercent, 2) < 0) {
            throw GloSalesException::poolNotComputable('special group below 1% floor');
        }

        // Invariant: each capped group ≤ its cap.
        foreach (['first', 'second', 'third'] as $name) {
            if (bccomp($built[$name]['amount'], $caps[$name], 2) > 0) {
                throw GloSalesException::poolNotComputable($name.' group exceeds cap');
            }
        }

        // Invariant: distributed + reserve = pool.
        if (bccomp(bcadd($distributed, $reserve, 2), $pool, 2) !== 0) {
            throw GloSalesException::poolNotComputable('allocation does not balance to pool');
        }

        return [
            'pool' => $pool,
            'groups' => $built,
            'reserve' => $reserve,
            'caps' => $caps,
            'engine' => (string) $this->config->get('glo.n3.prize_engine.mode', 'pool_variable'),
        ];
    }

    /**
     * Configured max share (as baht) for a group, or available when uncapped.
     */
    private function groupWanted(array $groups, string $name, string $pool, string $available): string
    {
        $entry = $groups[$name] ?? null;

        if (! is_array($entry)) {
            return $available;
        }

        $maxShare = $entry['max_share'] ?? null;

        if ($maxShare === null) {
            return $available;
        }

        $wanted = bcmul($pool, (string) $maxShare, 2);

        return $wanted;
    }

    private function minBc(string $a, string $b): string
    {
        return bccomp($a, $b, 2) <= 0 ? $a : $b;
    }

    private function floor2(string $value): string
    {
        return bcadd($value, '0.00', 2);
    }

    private function assertMoney(string $value): string
    {
        if (! preg_match('/^\d+(\.\d{1,2})?$/', trim($value))) {
            throw GloSalesException::poolNotComputable('money must be a non-negative decimal string');
        }

        return bcadd(trim($value), '0.00', 2);
    }
}
