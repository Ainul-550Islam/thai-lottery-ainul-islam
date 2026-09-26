<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

/**
 * Public fee matrix from config/fees.php — never competitor values,
 * never floats, never internal accounting formulas.
 *
 * Display rules:
 *   - only enabled + public_visible + non-internal rows render
 *   - missing amount/rate on an enabled row → NOT_CONFIGURED
 *   - disabled rows are omitted entirely (not shown as free)
 */
final class FeesPageService
{
    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    /**
     * Ordered public category keys (stable table order).
     *
     * @return list<string>
     */
    public function categoryOrder(): array
    {
        return [
            'account_renewal',
            'account_verification',
            'referral',
            'affiliation',
            'cash_balance_transfer',
            'win_balance_transfer',
            'cash_to_win',
            'win_to_cash',
            'personal_to_agent',
            'withdrawal',
            'cash_in',
            'agent_to_agent',
            'agent_commission',
            'maintenance',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function publicFees(?string $locale = null): array
    {
        $locale = $locale !== null && $locale !== '' ? $locale : (string) app()->getLocale();
        $categories = (array) config('fees.categories', []);
        $neverPublic = (array) config('fees.never_public', []);
        $rows = [];

        foreach ($this->categoryOrder() as $key) {
            if (in_array($key, $neverPublic, true)) {
                continue;
            }
            $raw = $categories[$key] ?? null;
            if (! is_array($raw)) {
                continue;
            }
            if (! (bool) ($raw['enabled'] ?? false)) {
                continue;
            }
            if (! (bool) ($raw['public_visible'] ?? false)) {
                continue;
            }
            if ((bool) ($raw['internal'] ?? false)) {
                continue;
            }

            $calculation = (string) ($raw['calculation'] ?? 'none');
            $amount = $raw['amount'] ?? null;
            $rate = $raw['rate'] ?? null;

            $amountDisplay = self::NOT_CONFIGURED;
            if ($calculation === 'fixed' && is_string($amount) && $amount !== '') {
                $amountDisplay = $this->formatFixed($amount, (string) ($raw['currency'] ?? 'THB'));
            } elseif ($calculation === 'percentage' && is_string($rate) && $rate !== '') {
                $amountDisplay = $this->formatRate($rate);
            } elseif ($calculation === 'none') {
                $amountDisplay = trans('account_services.fees_none', [], $locale);
            } elseif ($calculation === 'percentage' && $key === 'agent_commission') {
                // Per-agent rate lives on the agent row; show ceiling honestly.
                $maxRate = (string) config('agent.commission.max_rate', '');
                $amountDisplay = $maxRate !== ''
                    ? $this->formatRate($maxRate).' max'
                    : self::NOT_CONFIGURED;
            }

            $rows[] = [
                'key' => $key,
                'label' => trans('account_services.fees_category_'.$key, [], $locale),
                'calculation' => $calculation,
                'calculation_label' => match ($calculation) {
                    'fixed' => trans('account_services.fees_fixed', [], $locale),
                    'percentage' => trans('account_services.fees_percentage', [], $locale),
                    default => trans('account_services.fees_none', [], $locale),
                },
                'amount_display' => $amountDisplay,
                'description' => (string) ($raw['description'] ?? ''),
                'effective_from' => is_string($raw['effective_from'] ?? null) ? $raw['effective_from'] : null,
                'effective_to' => is_string($raw['effective_to'] ?? null) ? $raw['effective_to'] : null,
                'rule_version' => (string) ($raw['rule_version'] ?? config('fees.rule_version', '1')),
                'currency' => (string) ($raw['currency'] ?? config('fees.currency', 'THB')),
            ];
        }

        return $rows;
    }

    /**
     * BCMath fee calculator for a single category against a base amount.
     * Never uses float arithmetic. Returns a decimal string.
     *
     * @throws \InvalidArgumentException when the category is unknown or disabled
     */
    public function calculate(string $categoryKey, string $baseAmount): string
    {
        $categories = (array) config('fees.categories', []);
        $raw = $categories[$categoryKey] ?? null;
        if (! is_array($raw) || ! (bool) ($raw['enabled'] ?? false)) {
            throw new \InvalidArgumentException('Fee category is not configured: '.$categoryKey);
        }

        if (! is_numeric($baseAmount)) {
            throw new \InvalidArgumentException('Base amount must be a decimal string.');
        }

        $base = bcadd((string) $baseAmount, '0', 2);
        $calculation = (string) ($raw['calculation'] ?? 'none');

        $fee = match ($calculation) {
            'none' => '0.00',
            'fixed' => $this->clamp(
                bcadd((string) ($raw['amount'] ?? '0'), '0', 2),
                $raw,
            ),
            'percentage' => $this->percentageFee($base, (string) ($raw['rate'] ?? '0'), $raw),
            default => throw new \InvalidArgumentException('Unknown calculation for fee: '.$calculation),
        };

        // Never negative; never above 100% of base for percentage fees.
        if (bccomp($fee, '0', 2) < 0) {
            $fee = '0.00';
        }
        if ($calculation === 'percentage' && bccomp($fee, $base, 2) > 0) {
            $fee = $base;
        }

        return $fee;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function percentageFee(string $base, string $rate, array $raw): string
    {
        if (! is_numeric($rate)) {
            return '0.00';
        }
        // fee = base * rate  with scale 4 intermediate then round half-up to 2.
        $product = bcmul($base, $rate, 4);
        $fee = $this->roundHalfUp($product, 2);

        return $this->clamp($fee, $raw);
    }

    /**
     * Apply optional min/max bounds from the fee row (decimal strings).
     *
     * @param  array<string, mixed>  $raw
     */
    private function clamp(string $fee, array $raw): string
    {
        $min = $raw['min'] ?? null;
        $max = $raw['max'] ?? null;
        if (is_string($min) && $min !== '' && is_numeric($min) && bccomp($fee, $min, 2) < 0) {
            $fee = bcadd($min, '0', 2);
        }
        if (is_string($max) && $max !== '' && is_numeric($max) && bccomp($fee, $max, 2) > 0) {
            $fee = bcadd($max, '0', 2);
        }

        return $fee;
    }

    private function roundHalfUp(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $abs = ltrim($value, '-+');
        [$whole, $frac] = array_pad(explode('.', $abs, 2), 2, '0');
        $frac = str_pad($frac, $scale + 1, '0');
        $cut = substr($frac, 0, $scale);
        $next = $frac[$scale] ?? '0';
        if ((int) $next >= 5) {
            $result = bcadd($whole.'.'.$cut, '0.'.str_repeat('0', $scale - 1).'1', $scale);
        } else {
            $result = $whole.'.'.str_pad($cut, $scale, '0');
        }

        return ($negative ? '-' : '').$result;
    }

    private function formatFixed(string $amount, string $currency): string
    {
        $normalized = bcadd($amount, '0', 2);
        $whole = explode('.', $normalized, 2)[0];
        $grouped = number_format((int) $whole, 0, '.', ',');
        $frac = explode('.', $normalized, 2)[1] ?? '00';

        return $grouped.'.'.$frac.' '.$currency;
    }

    private function formatRate(string $rate): string
    {
        // rate is a fraction string '0.0150' → '1.50%'
        if (! is_numeric($rate)) {
            return self::NOT_CONFIGURED;
        }
        // percent = rate * 100 without float: scale 2 on string math.
        $percent = bcmul($rate, '100', 2);
        $percent = bcadd($percent, '0', 2);

        return $percent.'%';
    }
}
