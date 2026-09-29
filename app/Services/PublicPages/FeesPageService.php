<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

use App\DTOs\Finance\FeeCalculationResult;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Public fee matrix from config/fees.php — never competitor values,
 * never floats, never internal accounting formulas.
 *
 * THE ONE AUTHORITY for the public fee projection. Both GET /fees and
 * GET /api/v1/fees render from publicFees()/publicFeeGroups(), and the
 * fee-preview endpoint calculates through calculateResult(), so the HTML
 * surface, the JSON surface and the calculator can never drift apart.
 *
 * Display rules:
 *   - only enabled + public_visible + non-internal + currently effective
 *     rows render ('never_public' is a second, independent guard);
 *   - a percentage/fixed row whose rate/amount is deliberately unspecified
 *     renders as NOT_CONFIGURED — an unspecified fee is a state, never a
 *     guessed number;
 *   - disabled rows are omitted entirely (not shown as free).
 *
 * LIVE-RULE MIRRORING
 *   A row carrying 'execution' => '<config path>' resolves its rate at
 *   RUNTIME from that live configuration path (a whole-percent decimal
 *   string converted to a fraction with bcmath). Those rows document the
 *   rate the finance engine actually charges, so the published schedule
 *   and live execution share one source of truth and can never disagree.
 *
 * EXACT ARITHMETIC
 *   Every monetary value is a decimal string. All arithmetic is bcmath at
 *   fixed scale. No binary floating point, no float casts, no native
 *   rounding calls and no client-supplied fee anywhere in this class.
 *
 * ROUNDING POLICY (documented contract)
 *   Percentage fees are computed as base × rate at intermediate scale 4
 *   and rounded HALF-UP to scale 2 — the same scale-2 half-up policy the
 *   finance engine (config/finance.php precision.amount = 2, rounding_mode
 *   half_up) and Money apply everywhere. The rounded fee is then clamped
 *   to the row's optional min/max, floored at 0.00, and — for percentage
 *   fees only — capped at 100% of the base so a misconfigured rate can
 *   never charge more than the amount itself.
 */
final class FeesPageService
{
    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    /** Monetary scale of every published fee calculation. */
    private const AMOUNT_SCALE = 2;

    /** Intermediate scale for base × rate before the single half-up rounding. */
    private const INTERMEDIATE_SCALE = 4;

    /** Scale for rate conversions (whole percent ↔ fraction). */
    private const RATE_SCALE = 6;

    /** Fee families whose rows are provider-specific and accept a provider key. */
    private const PROVIDER_FAMILIES = ['withdrawal', 'cash_in'];

    /**
     * Ordered public category keys (stable table order).
     *
     * @return list<string>
     */
    public function categoryOrder(): array
    {
        return [
            // account
            'account_renewal',
            'account_verification',
            'referral',
            'affiliation',
            // transfers
            'cash_balance_transfer',
            'win_balance_transfer',
            'cash_to_win',
            'win_to_cash',
            'personal_to_agent',
            // withdrawal (generic live rule first, then provider rows)
            'withdrawal',
            'withdrawal_bank',
            'withdrawal_skrill',
            'withdrawal_neteller',
            'withdrawal_paypal',
            'withdrawal_perfect_money',
            // cash in (generic live rule first, then provider rows)
            'cash_in',
            'cash_in_bank',
            'cash_in_skrill',
            'cash_in_neteller',
            'cash_in_paypal',
            'cash_in_perfect_money',
            // agent
            'agent_to_agent',
            'agent_to_win_commission',
            'agent_to_cash_commission',
            'personal_to_agent_commission',
            'agent_commission',
            // maintenance
            'maintenance',
        ];
    }

    /**
     * Ordered public group keys with their label translation keys.
     *
     * @return array<string, array{label_key: string}>
     */
    public function groups(): array
    {
        $groups = (array) config('fees.groups', []);

        return $groups === [] ? ['general' => ['label_key' => 'account_services.fees_title']] : $groups;
    }

    /**
     * Stable public provider keys whitelisted for the fee schedule.
     *
     * The payment layer (config/payment.php 'public_fees.providers') is the
     * single authority for provider identity; no arbitrary provider key
     * from a request can ever enter the whitelist.
     *
     * @return list<string>
     */
    public function providers(): array
    {
        $providers = (array) config('payment.public_fees.providers', []);

        return array_values(array_intersect(
            ['bank', 'skrill', 'neteller', 'paypal', 'perfect_money'],
            array_keys($providers),
        ));
    }

    /**
     * Is this provider key one of the whitelisted stable keys?
     */
    public function isKnownProvider(string $provider): bool
    {
        return in_array(strtolower(trim($provider)), $this->providers(), true);
    }

    /**
     * Translated provider label (falls back to the stable key).
     */
    public function providerLabel(string $provider, ?string $locale = null): string
    {
        $locale = $this->locale($locale);
        $key = strtolower(trim($provider));
        $labelKey = (string) config('fees.providers.'.$key.'.label_key', '');

        if ($labelKey !== '') {
            $label = trans($labelKey, [], $locale);

            return is_string($label) && $label !== $labelKey ? $label : ucfirst(str_replace('_', ' ', $key));
        }

        return ucfirst(str_replace('_', ' ', $key));
    }

    /**
     * Is a fee row currently effective?
     *
     * effective_from <= now AND (effective_to IS NULL OR now < effective_to).
     * effective_to is EXCLUSIVE, matching LottoDiscountService's convention:
     * a row ending on the 8th does not linger through the 8th.
     *
     * @param  array<string, mixed>  $row
     */
    public function isEffective(array $row, ?\DateTimeInterface $at = null): bool
    {
        $now = $at !== null ? Carbon::instance($at) : now();

        $from = $row['effective_from'] ?? null;
        $to = $row['effective_to'] ?? null;

        if (is_string($from) && $from !== '' && $now->lt(Carbon::parse($from)->startOfDay())) {
            return false;
        }

        if (is_string($to) && $to !== '' && $now->gte(Carbon::parse($to)->startOfDay())) {
            return false;
        }

        return true;
    }

    /**
     * Is this category key a publishable public fee row right now?
     *
     * (enabled + public_visible + not internal + not never_public +
     * currently effective). Internal and disabled rows answer false, so
     * the preview endpoint can whitelist on the same predicate the page
     * renders with.
     */
    public function isPublicCategory(string $categoryKey): bool
    {
        $raw = $this->rawRow($categoryKey);

        if ($raw === null) {
            return false;
        }

        if (in_array($categoryKey, (array) config('fees.never_public', []), true)) {
            return false;
        }

        if (! (bool) ($raw['enabled'] ?? false)) {
            return false;
        }

        if (! (bool) ($raw['public_visible'] ?? false)) {
            return false;
        }

        if ((bool) ($raw['internal'] ?? false)) {
            return false;
        }

        return $this->isEffective($raw);
    }

    /**
     * Category keys a client may ask the preview endpoint to calculate.
     *
     * @return list<string>
     */
    public function previewCategories(): array
    {
        $keys = [];

        foreach ($this->categoryOrder() as $key) {
            if ($this->isPublicCategory($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Flat ordered public rows (same projection the JSON API publishes).
     *
     * @return array<int, array<string, mixed>>
     */
    public function publicFees(?string $locale = null): array
    {
        $locale = $this->locale($locale);
        $rows = [];

        foreach ($this->categoryOrder() as $key) {
            $row = $this->publicRow($key, $locale);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Public rows grouped into the ordered page sections. Only groups that
     * still hold at least one public row are returned, so a fully disabled
     * section disappears instead of rendering an empty table.
     *
     * @return array<int, array{key: string, label: string, has_providers: bool, rows: array<int, array<string, mixed>>}>
     */
    public function publicFeeGroups(?string $locale = null): array
    {
        $locale = $this->locale($locale);
        $byGroup = [];

        foreach ($this->publicFees($locale) as $row) {
            $byGroup[(string) $row['group']][] = $row;
        }

        $groups = [];

        foreach ($this->groups() as $groupKey => $meta) {
            if (! isset($byGroup[$groupKey]) || $byGroup[$groupKey] === []) {
                continue;
            }

            $labelKey = (string) ($meta['label_key'] ?? '');
            $label = $labelKey !== '' ? trans($labelKey, [], $locale) : $groupKey;
            $label = is_string($label) && $label !== $labelKey ? $label : ucfirst($groupKey);

            $hasProviders = false;

            foreach ($byGroup[$groupKey] as $row) {
                if (! empty($row['provider'])) {
                    $hasProviders = true;

                    break;
                }
            }

            $groups[] = [
                'key' => $groupKey,
                'label' => $label,
                'has_providers' => $hasProviders,
                'rows' => $byGroup[$groupKey],
            ];
        }

        return $groups;
    }

    /**
     * Build one public row projection, or null when it must not render.
     *
     * @return array<string, mixed>|null
     */
    private function publicRow(string $key, string $locale): ?array
    {
        if (! $this->isPublicCategory($key)) {
            return null;
        }

        $raw = (array) $this->rawRow($key);
        $calculation = (string) ($raw['calculation'] ?? 'none');
        $rate = $this->resolveRate($raw);
        $amount = is_string($raw['amount'] ?? null) && $raw['amount'] !== '' ? $raw['amount'] : null;
        $currency = (string) ($raw['currency'] ?? config('fees.currency', 'THB'));
        $provider = is_string($raw['provider'] ?? null) && $raw['provider'] !== ''
            ? strtolower((string) $raw['provider'])
            : null;

        [$amountDisplay, $state] = $this->displayValue($key, $calculation, $rate, $amount, $currency, $locale);

        $descriptionKey = (string) ($raw['description_key'] ?? '');
        $description = '';

        if ($descriptionKey !== '') {
            $translated = trans($descriptionKey, [], $locale);
            $description = is_string($translated) && $translated !== $descriptionKey
                ? $translated
                : (string) ($raw['description'] ?? '');
        } else {
            $description = (string) ($raw['description'] ?? '');
        }

        $groupKey = (string) ($raw['group'] ?? '');
        $groupLabel = '';
        $groupMeta = (array) (config('fees.groups.'.$groupKey) ?? []);

        if ($groupMeta !== []) {
            $groupLabelKey = (string) ($groupMeta['label_key'] ?? '');
            $groupLabelTranslated = $groupLabelKey !== '' ? trans($groupLabelKey, [], $locale) : $groupKey;
            $groupLabel = is_string($groupLabelTranslated) && $groupLabelTranslated !== $groupLabelKey
                ? $groupLabelTranslated
                : ucfirst($groupKey);
        }

        return [
            'key' => $key,
            'label' => trans('account_services.fees_category_'.$key, [], $locale),
            'group' => $groupKey,
            'group_label' => $groupLabel,
            'provider' => $provider,
            'provider_label' => $provider !== null ? $this->providerLabel($provider, $locale) : null,
            'calculation' => $calculation,
            'calculation_label' => match ($calculation) {
                'fixed' => trans('account_services.fees_fixed', [], $locale),
                'percentage' => trans('account_services.fees_percentage', [], $locale),
                default => trans('account_services.fees_none', [], $locale),
            },
            'amount_display' => $amountDisplay,
            'state' => $state,
            'description_key' => $descriptionKey,
            'description' => $description,
            'effective_from' => is_string($raw['effective_from'] ?? null) ? $raw['effective_from'] : null,
            'effective_to' => is_string($raw['effective_to'] ?? null) ? $raw['effective_to'] : null,
            'rule_version' => (string) ($raw['rule_version'] ?? config('fees.rule_version', '1')),
            'currency' => $currency,
        ];
    }

    /**
     * Resolve the effective rate (as a decimal-string fraction of 1) for a
     * row, mirroring the live engine rule when the row declares one.
     *
     * @param  array<string, mixed>  $raw
     */
    public function resolveRate(array $raw): ?string
    {
        $execution = $raw['execution'] ?? null;

        if (is_string($execution) && $execution !== '') {
            $wholePercent = config($execution);

            if (! is_string($wholePercent) || $wholePercent === '' || ! is_numeric($wholePercent)) {
                return null; // live rule absent → NOT_CONFIGURED, never invented
            }

            if (bccomp($wholePercent, '0', self::RATE_SCALE) < 0) {
                return null; // a negative live rate is a misconfiguration, not a fee
            }

            // Whole percent ('8.00' = 8%) → fraction ('0.080000'), exact.
            return bcdiv($wholePercent, '100', self::RATE_SCALE);
        }

        $rate = $raw['rate'] ?? null;

        if (! is_string($rate) || $rate === '' || ! is_numeric($rate)) {
            return null;
        }

        if (bccomp($rate, '0', self::RATE_SCALE) < 0) {
            return null; // negative rates never render and never calculate
        }

        return $rate;
    }

    /**
     * Display value + configured state for a row.
     *
     * @return array{0: string, 1: string} [amount_display, state]
     */
    private function displayValue(
        string $key,
        string $calculation,
        ?string $rate,
        ?string $amount,
        string $currency,
        string $locale,
    ): array {
        if ($calculation === 'fixed') {
            if ($amount === null) {
                return [self::NOT_CONFIGURED, self::NOT_CONFIGURED];
            }

            return [$this->formatFixed($amount, $currency), FeeCalculationResult::STATE_CONFIGURED];
        }

        if ($calculation === 'percentage') {
            if ($rate === null && $key === 'agent_commission') {
                // Per-agent rate lives on the agent row; show ceiling honestly.
                $maxRate = (string) config('agent.commission.max_rate', '');

                return $maxRate !== ''
                    ? [$this->formatRate($maxRate).' max', FeeCalculationResult::STATE_CONFIGURED]
                    : [self::NOT_CONFIGURED, self::NOT_CONFIGURED];
            }

            if ($rate === null) {
                return [self::NOT_CONFIGURED, self::NOT_CONFIGURED];
            }

            return [$this->formatRate($rate), FeeCalculationResult::STATE_CONFIGURED];
        }

        if ($calculation === 'none') {
            return [(string) trans('account_services.fees_none', [], $locale), FeeCalculationResult::STATE_CONFIGURED];
        }

        return [self::NOT_CONFIGURED, self::NOT_CONFIGURED];
    }

    /**
     * BCMath fee calculator for a single category against a base amount.
     * Never uses float arithmetic. Returns a decimal string.
     *
     * Backwards-compatible scalar form of calculateResult(): the exact fee
     * for a CONFIGURED row, '0.00' for a none/zero rule.
     *
     * @throws InvalidArgumentException when the category is unknown or disabled
     */
    public function calculate(string $categoryKey, string $baseAmount): string
    {
        $raw = $this->rawRow($categoryKey);

        if ($raw === null || ! (bool) ($raw['enabled'] ?? false)) {
            throw new InvalidArgumentException('Fee category is not configured: '.$categoryKey);
        }

        if (! is_numeric($baseAmount)) {
            throw new InvalidArgumentException('Base amount must be a decimal string.');
        }

        $base = bcadd((string) $baseAmount, '0', self::AMOUNT_SCALE);
        $calculation = (string) ($raw['calculation'] ?? 'none');

        $fee = match ($calculation) {
            'none' => '0.00',
            'fixed' => $this->clamp(
                bcadd((string) ($raw['amount'] ?? '0'), '0', self::AMOUNT_SCALE),
                $raw,
            ),
            'percentage' => $this->percentageFee($base, (string) ($this->resolveRate($raw) ?? '0'), $raw),
            default => throw new InvalidArgumentException('Unknown calculation for fee: '.$calculation),
        };

        // Never negative; never above 100% of base for percentage fees.
        if (bccomp($fee, '0', self::AMOUNT_SCALE) < 0) {
            $fee = '0.00';
        }
        if ($calculation === 'percentage' && bccomp($fee, $base, self::AMOUNT_SCALE) > 0) {
            $fee = $base;
        }

        return $fee;
    }

    /**
     * Normalized, immutable fee calculation for the preview surface.
     *
     * The caller supplies only the category, an optional provider key and a
     * base amount; every other field of the result is resolved server-side.
     * A client-supplied fee amount has no path into this method at all.
     *
     * @param  string  $categoryKey  public category key (or a provider family)
     * @param  string  $baseAmount  non-negative decimal string, max 2 decimals
     * @param  string|null  $provider  stable provider key, only valid for the
     *                                 'withdrawal' / 'cash_in' families
     *
     * @throws InvalidArgumentException on unknown category, unknown provider,
     *                                  a provider on a non-family category, a
     *                                  non-public row, or a malformed base amount
     */
    public function calculateResult(string $categoryKey, string $baseAmount, ?string $provider = null): FeeCalculationResult
    {
        $resolvedKey = $this->resolvePreviewCategory($categoryKey, $provider);

        if (! $this->isPublicCategory($resolvedKey)) {
            throw new InvalidArgumentException('Fee category is not configured: '.$resolvedKey);
        }

        if (preg_match('/^\d{1,13}(\.\d{1,2})?$/', $baseAmount) !== 1) {
            throw new InvalidArgumentException(
                'Base amount must be a plain decimal string with at most two decimals.'
            );
        }

        $raw = (array) $this->rawRow($resolvedKey);
        $calculation = (string) ($raw['calculation'] ?? 'none');
        $rate = $this->resolveRate($raw);
        $amount = is_string($raw['amount'] ?? null) && $raw['amount'] !== '' ? $raw['amount'] : null;
        $currency = (string) ($raw['currency'] ?? config('fees.currency', 'THB'));

        // A row is NOT_CONFIGURED for calculation when its percentage or
        // fixed value is deliberately unspecified. agent_commission is the
        // one special case: the page displays the system ceiling, but the
        // live rate belongs to each agent's row, so a public preview can
        // never quote one number — NOT_CONFIGURED is the honest answer.
        $notConfigured =
            ($calculation === 'percentage' && $rate === null)
            || ($calculation === 'fixed' && $amount === null);

        if ($notConfigured) {
            return new FeeCalculationResult(
                category: $resolvedKey,
                provider: $this->rowProvider($raw),
                baseAmount: bcadd($baseAmount, '0', self::AMOUNT_SCALE),
                feeAmount: null,
                currency: $currency,
                calculation: $calculation,
                ruleVersion: (string) ($raw['rule_version'] ?? config('fees.rule_version', '1')),
                state: FeeCalculationResult::STATE_NOT_CONFIGURED,
                feeDisplay: self::NOT_CONFIGURED,
                effectiveFrom: is_string($raw['effective_from'] ?? null) ? $raw['effective_from'] : null,
                effectiveTo: is_string($raw['effective_to'] ?? null) ? $raw['effective_to'] : null,
            );
        }

        $fee = $this->calculate($resolvedKey, $baseAmount);
        $base = bcadd($baseAmount, '0', self::AMOUNT_SCALE);

        if ($calculation === 'percentage') {
            $display = $rate !== null ? $this->formatRate($rate) : self::NOT_CONFIGURED;
        } elseif ($calculation === 'fixed') {
            $display = $amount !== null ? $this->formatFixed($amount, $currency) : self::NOT_CONFIGURED;
        } else {
            $display = '0.00';
        }

        return new FeeCalculationResult(
            category: $resolvedKey,
            provider: $this->rowProvider($raw),
            baseAmount: $base,
            feeAmount: $fee,
            currency: $currency,
            calculation: $calculation,
            ruleVersion: (string) ($raw['rule_version'] ?? config('fees.rule_version', '1')),
            state: FeeCalculationResult::STATE_CONFIGURED,
            feeDisplay: $display,
            effectiveFrom: is_string($raw['effective_from'] ?? null) ? $raw['effective_from'] : null,
            effectiveTo: is_string($raw['effective_to'] ?? null) ? $raw['effective_to'] : null,
        );
    }

    /**
     * Map a preview request's (category, provider) pair onto a concrete row
     * key and reject every combination the public schedule does not define.
     *
     * @throws InvalidArgumentException
     */
    private function resolvePreviewCategory(string $categoryKey, ?string $provider): string
    {
        $categoryKey = strtolower(trim($categoryKey));

        if ($provider !== null && $provider !== '') {
            $provider = strtolower(trim($provider));

            if (! $this->isKnownProvider($provider)) {
                throw new InvalidArgumentException('Unknown fee provider: '.$provider);
            }

            if (! in_array($categoryKey, self::PROVIDER_FAMILIES, true)) {
                throw new InvalidArgumentException(
                    'A provider is only valid for the withdrawal or cash-in fee families.'
                );
            }

            return $categoryKey.'_'.$provider;
        }

        return $categoryKey;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function rowProvider(array $raw): ?string
    {
        $provider = $raw['provider'] ?? null;

        return is_string($provider) && $provider !== '' ? strtolower($provider) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rawRow(string $categoryKey): ?array
    {
        $raw = (array) config('fees.categories.'.$categoryKey, null);

        return $raw === [] ? null : $raw;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function percentageFee(string $base, string $rate, array $raw): string
    {
        if (! is_numeric($rate)) {
            return '0.00';
        }

        // fee = base × rate at intermediate scale 4, rounded half-up to 2.
        $product = bcmul($base, $rate, self::INTERMEDIATE_SCALE);
        $fee = $this->roundHalfUp($product, self::AMOUNT_SCALE);

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

        if (is_string($min) && $min !== '' && is_numeric($min) && bccomp($fee, $min, self::AMOUNT_SCALE) < 0) {
            $fee = bcadd($min, '0', self::AMOUNT_SCALE);
        }

        if (is_string($max) && $max !== '' && is_numeric($max) && bccomp($fee, $max, self::AMOUNT_SCALE) > 0) {
            $fee = bcadd($max, '0', self::AMOUNT_SCALE);
        }

        return $fee;
    }

    /**
     * Half-up rounding on a decimal string, without ever touching a float.
     */
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
        $normalized = bcadd($amount, '0', self::AMOUNT_SCALE);
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

        // percent = rate × 100 without float: scale-2 string math.
        $percent = bcmul($rate, '100', self::AMOUNT_SCALE);
        $percent = bcadd($percent, '0', self::AMOUNT_SCALE);

        return $percent.'%';
    }

    private function locale(?string $locale): string
    {
        return $locale !== null && $locale !== '' ? $locale : (string) app()->getLocale();
    }
}
