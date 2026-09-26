<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\User;

/**
 * Grade-aware discount resolver (server-authoritative).
 *
 * Pricing paths MUST call this service (or the canonical pricing
 * service that composes it). Clients never supply discount rates.
 *
 * Product separation: GLO L6 / N3 official ticket prices are excluded
 * by config('account_grades.glo_excluded_products') and ignore grade
 * discounts entirely.
 */
final class AccountDiscountService
{
    public function __construct(
        private readonly AccountGradeService $grades,
    ) {
    }

    /**
     * Discount rate (decimal fraction string) for a product code, or '0.0000'.
     * GLO products always return zero — official ticket economics protected.
     */
    public function discountRateForProduct(User $user, string $productCode): string
    {
        $code = strtolower(trim($productCode));
        $excluded = array_map(
            static fn ($p): string => strtolower((string) $p),
            (array) config('account_grades.glo_excluded_products', []),
        );

        if (in_array($code, $excluded, true)) {
            return '0.0000';
        }

        $grade = $this->grades->current($user);
        $rate = (string) ($grade['discount_rate'] ?? '0.0000');
        if (! is_numeric($rate) || bccomp($rate, '0', 4) <= 0) {
            return '0.0000';
        }

        $max = (string) config('account_grades.max_discount_rate', '0.2500');
        if (bccomp($rate, $max, 4) > 0) {
            $rate = $max;
        }

        return bcadd($rate, '0', 4);
    }

    /**
     * Apply discount to a base price with BCMath. Returns decimal string.
     * Never allows negative or >100% outcomes; GLO codes are untouched.
     *
     * @return array{base: string, rate: string, discount: string, final: string, product: string}
     */
    public function priceFor(User $user, string $productCode, string $basePrice): array
    {
        if (! is_numeric($basePrice)) {
            throw new \InvalidArgumentException('Base price must be a decimal string.');
        }

        $base = bcadd((string) $basePrice, '0', 2);
        $rate = $this->discountRateForProduct($user, $productCode);

        if (bccomp($rate, '0', 4) === 0) {
            return [
                'base' => $base,
                'rate' => '0.0000',
                'discount' => '0.00',
                'final' => $base,
                'product' => strtolower(trim($productCode)),
            ];
        }

        // discount = base * rate, scale 2, half-up.
        $product = bcmul($base, $rate, 4);
        $discount = $this->round2($product);
        if (bccomp($discount, '0', 2) < 0) {
            $discount = '0.00';
        }
        if (bccomp($discount, $base, 2) > 0) {
            $discount = $base;
        }

        $final = bcsub($base, $discount, 2);
        if (bccomp($final, '0', 2) < 0) {
            $final = '0.00';
        }

        return [
            'base' => $base,
            'rate' => $rate,
            'discount' => $discount,
            'final' => $final,
            'product' => strtolower(trim($productCode)),
        ];
    }

    /**
     * Eligible discount presentation for the grade page.
     *
     * @return list<array<string, mixed>>
     */
    public function eligibleForDisplay(User $user): array
    {
        $grade = $this->grades->current($user);
        $rate = (string) ($grade['discount_rate'] ?? '0.0000');
        $percent = '0.00';
        if (is_numeric($rate)) {
            $percent = bcadd(bcmul($rate, '100', 2), '0', 2);
        }

        $rows = [
            [
                'scope' => 'operator_markets',
                'rate' => $rate,
                'percent_display' => $percent.'%',
                'note' => 'grade_discount_operator_markets',
            ],
            [
                'scope' => 'glo_excluded',
                'rate' => '0.0000',
                'percent_display' => '0%',
                'note' => 'grade_discount_glo_excluded',
            ],
        ];

        return $rows;
    }

    private function round2(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $abs = ltrim($value, '-');
        [$whole, $frac] = array_pad(explode('.', $abs, 2), 2, '0');
        $frac = str_pad($frac, 3, '0');
        $cut = substr($frac, 0, 2);
        $next = $frac[2];
        if ((int) $next >= 5) {
            $result = bcadd($whole.'.'.$cut, '0.01', 2);
        } else {
            $result = $whole.'.'.$cut;
        }

        return ($negative ? '-' : '').$result;
    }
}
