<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Models\User;
use App\Services\Account\AccountDiscountService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Canonical server-side discount resolution (PROMPT 4).
 *
 * THE CLIENT NEVER DECIDES A PRICE.
 * Nothing in this class reads a request. It takes a product, a market and a
 * BASE AMOUNT supplied by the caller's own pricing source, and returns the
 * breakdown. A browser that posts discount=99 changes nothing, because no
 * discount value is ever accepted as input - only looked up.
 *
 * EXACT DECIMAL ARITHMETIC ONLY.
 * Every amount is a decimal STRING and every operation is bcmath at a fixed
 * scale. There is no float in this file. Percentages are stored in
 * whole-percent units ('2.50' = 2.50%) and are divided by 100 with bcdiv at
 * scale 6 before use, so 2.50% of 199.99 is computed exactly and rounded once.
 *
 * GLO PRICES ARE NOT THIS ENGINE'S TO MOVE.
 * config('discounts.immutable_products') lists the official state-lottery
 * products. quote() refuses them outright with GLO_PRICE_IMMUTABLE rather
 * than returning an unchanged price, so a rule that tries to discount an
 * official ticket is a visible error instead of a silent no-op. The guard is
 * belt-and-braces: AccountDiscountService independently returns 0.0000 for
 * the same products via config('account_grades.glo_excluded_products').
 *
 * PRECEDENCE AND DOUBLE-APPLICATION.
 * Layers run in config('discounts.precedence') order, each layer contributes
 * at most once, and a rule id can be granted at most once per quote. The
 * first applicable non-stackable rule wins alone and terminates resolution.
 */
final class LottoDiscountService
{
    public const GLO_IMMUTABLE = 'GLO_PRICE_IMMUTABLE';

    private const RATE_SCALE = 6;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
        private readonly AccountDiscountService $accountDiscounts,
    ) {}

    /**
     * Public, anonymous catalogue for GET /discounts.
     *
     * Only enabled + public_visible + currently effective rules are returned,
     * and never the internal rule object: each row is rebuilt field by field.
     *
     * @return array{
     *     currency: string,
     *     catalogue_version: string,
     *     generated_at: string,
     *     products: list<array<string, mixed>>,
     *     immutable_products: list<array<string, mixed>>
     * }
     */
    public function publicCatalogue(?string $locale = null): array
    {
        $locale = $locale ?? (string) app()->getLocale();
        $version = (string) $this->config->get('discounts.catalogue_version', '1');

        $build = fn (): array => $this->buildPublicCatalogue($locale);

        if ((bool) $this->config->get('discounts.cache.enabled', true) === false) {
            return $build();
        }

        $key = sprintf(
            '%s.%s.%s',
            (string) $this->config->get('discounts.cache.key_prefix', 'discounts.public'),
            $locale,
            $version,
        );

        return $this->cache->remember(
            $key,
            (int) $this->config->get('discounts.cache.ttl_seconds', 300),
            $build,
        );
    }

    /**
     * Resolve the price of one purchase.
     *
     * @return array{
     *     product: string,
     *     market: string|null,
     *     base: string,
     *     discount_total: string,
     *     final: string,
     *     currency: string,
     *     layers: list<array{layer: string, rule_id: string, type: string, value: string, discount: string, rule_version: string}>,
     *     refused: string|null,
     *     catalogue_version: string
     * }
     */
    public function quote(string $product, ?string $market, string $baseAmount, ?User $user = null): array
    {
        $product = strtolower(trim($product));
        $market = $market !== null && $market !== '' ? strtolower(trim($market)) : null;

        if (! is_numeric($baseAmount)) {
            throw new InvalidArgumentException('Base amount must be a decimal string.');
        }

        $scale = (int) $this->config->get('discounts.limits.scale', 2);
        $base = bcadd($baseAmount, '0', $scale);

        if (bccomp($base, '0', $scale) < 0) {
            throw new InvalidArgumentException('Base amount must not be negative.');
        }

        $currency = (string) $this->config->get('discounts.currency', 'THB');
        $catalogueVersion = (string) $this->config->get('discounts.catalogue_version', '1');

        // --- Immutable official products: refuse, do not silently pass -----
        if ($this->isImmutableProduct($product)) {
            return [
                'product' => $product,
                'market' => $market,
                'base' => $base,
                'discount_total' => '0.00',
                'final' => $base,
                'currency' => $currency,
                'layers' => [],
                'refused' => self::GLO_IMMUTABLE,
                'catalogue_version' => $catalogueVersion,
            ];
        }

        $layers = [];
        $granted = [];
        $discountTotal = bcadd('0', '0', $scale);

        foreach ((array) $this->config->get('discounts.precedence', []) as $layer) {
            $layer = (string) $layer;

            if ($layer === 'base' || $layer === 'final') {
                continue;
            }

            if ($layer === 'account_grade') {
                $applied = $this->accountGradeLayer($product, $base, $discountTotal, $user, $scale);
            } else {
                $applied = $this->ruleLayer($layer, $product, $market, $base, $discountTotal, $granted, $scale);
            }

            if ($applied === null) {
                continue;
            }

            $granted[] = $applied['rule_id'];
            $layers[] = $applied;
            $discountTotal = bcadd($discountTotal, $applied['discount'], $scale);

            if ($applied['stops'] === true) {
                break;
            }
        }

        // --- Global ceilings ------------------------------------------------
        $discountTotal = $this->capTotal($base, $discountTotal, $scale);

        $final = bcsub($base, $discountTotal, $scale);

        $minFinal = (string) $this->config->get('discounts.limits.min_final_price', '0.00');

        if (bccomp($final, $minFinal, $scale) < 0) {
            $final = bcadd($minFinal, '0', $scale);
            $discountTotal = bcsub($base, $final, $scale);
        }

        return [
            'product' => $product,
            'market' => $market,
            'base' => $base,
            'discount_total' => $discountTotal,
            'final' => $final,
            'currency' => $currency,
            'layers' => array_map(
                static fn (array $l): array => [
                    'layer' => $l['layer'],
                    'rule_id' => $l['rule_id'],
                    'type' => $l['type'],
                    'value' => $l['value'],
                    'discount' => $l['discount'],
                    'rule_version' => $l['rule_version'],
                ],
                $layers,
            ),
            'refused' => null,
            'catalogue_version' => $catalogueVersion,
        ];
    }

    /**
     * Is this rule effective at the given instant?
     *
     * effective_from <= now AND (effective_to IS NULL OR now < effective_to).
     * effective_to is EXCLUSIVE so a rule ending on the 8th does not linger
     * through the 8th.
     *
     * @param  array<string, mixed>  $rule
     */
    public function isEffective(array $rule, ?\DateTimeInterface $at = null): bool
    {
        $now = $at !== null ? Carbon::instance($at) : now();

        $from = $rule['effective_from'] ?? null;
        $to = $rule['effective_to'] ?? null;

        if (is_string($from) && $from !== '' && $now->lt(Carbon::parse($from))) {
            return false;
        }

        if (is_string($to) && $to !== '' && $now->gte(Carbon::parse($to))) {
            return false;
        }

        return true;
    }

    /**
     * A rule is usable at all only when it is structurally sane. An out-of-
     * bounds percentage or a negative amount disables the rule rather than
     * producing a wrong price.
     *
     * @param  array<string, mixed>  $rule
     */
    public function isRuleValid(array $rule): bool
    {
        $type = (string) ($rule['discount_type'] ?? 'none');

        if ($type === 'none') {
            return false;
        }

        $value = (string) ($rule['discount_value'] ?? '');

        if (! is_numeric($value) || bccomp($value, '0', self::RATE_SCALE) < 0) {
            return false;
        }

        if ($type === 'percentage') {
            $min = (string) $this->config->get('discounts.limits.min_percentage', '0');
            $max = (string) $this->config->get('discounts.limits.max_percentage', '100');

            return bccomp($value, $min, self::RATE_SCALE) >= 0
                && bccomp($value, $max, self::RATE_SCALE) <= 0;
        }

        return $type === 'fixed';
    }

    public function isImmutableProduct(string $product): bool
    {
        $immutable = (array) $this->config->get('discounts.immutable_products', []);

        return array_key_exists(strtolower(trim($product)), $immutable);
    }

    /**
     * Cross-check that the official prices this engine refuses to touch are
     * still the prices configured for the GLO products. Used by the tests and
     * by the public page footer note.
     *
     * @return array<string, array{expected: string, configured: string|null, matches: bool}>
     */
    public function immutablePriceAudit(): array
    {
        $audit = [];

        foreach ((array) $this->config->get('discounts.immutable_products', []) as $product => $meta) {
            $source = (string) ($meta['price_source'] ?? '');
            $configured = $source !== '' ? $this->config->get($source) : null;
            $expected = (string) ($meta['expected_price'] ?? '');

            $audit[(string) $product] = [
                'expected' => $expected,
                'configured' => $configured !== null ? (string) $configured : null,
                'matches' => $configured !== null
                    && is_numeric((string) $configured)
                    && bccomp((string) $configured, $expected, 2) === 0,
            ];
        }

        return $audit;
    }

    /**
     * @return array{layer: string, rule_id: string, type: string, value: string, discount: string, rule_version: string, stops: bool}|null
     */
    private function ruleLayer(
        string $layer,
        string $product,
        ?string $market,
        string $base,
        string $alreadyDiscounted,
        array $granted,
        int $scale,
    ): ?array {
        $candidates = [];

        foreach ((array) $this->config->get('discounts.rules', []) as $key => $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $ruleId = (string) ($rule['rule_id'] ?? $key);

            if (in_array($ruleId, $granted, true)) {
                continue; // never twice
            }

            if ((string) ($rule['layer'] ?? 'market_rule') !== $layer) {
                continue;
            }

            if ((bool) ($rule['enabled'] ?? false) === false) {
                continue;
            }

            if (strtolower((string) ($rule['product'] ?? '')) !== $product) {
                continue;
            }

            $ruleMarket = (string) ($rule['market'] ?? '*');

            if ($ruleMarket !== '*' && ($market === null || strtolower($ruleMarket) !== $market)) {
                continue;
            }

            $excluded = array_map(
                static fn ($p): string => strtolower((string) $p),
                (array) ($rule['excluded_products'] ?? []),
            );

            if (in_array($product, $excluded, true)) {
                continue;
            }

            if (! $this->isEffective($rule) || ! $this->isRuleValid($rule)) {
                continue;
            }

            $minimum = $rule['minimum_amount'] ?? null;

            if (is_string($minimum) && $minimum !== '' && bccomp($base, $minimum, $scale) < 0) {
                continue;
            }

            $candidates[] = ['rule_id' => $ruleId, 'rule' => $rule, 'priority' => (int) ($rule['priority'] ?? 100)];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        $chosen = $candidates[0];
        $rule = $chosen['rule'];

        $discount = $this->discountFor($rule, $base, $scale);
        $discount = $this->clampAgainstRemaining($base, $alreadyDiscounted, $discount, $scale);

        return [
            'layer' => $layer,
            'rule_id' => $chosen['rule_id'],
            'type' => (string) $rule['discount_type'],
            'value' => (string) $rule['discount_value'],
            'discount' => $discount,
            'rule_version' => (string) ($rule['rule_version'] ?? '1'),
            'stops' => (bool) ($rule['stackable'] ?? true) === false,
        ];
    }

    /**
     * The account-grade layer is OWNED by AccountDiscountService. This method
     * only decides whether the layer participates and then asks that service
     * for the number - the grade rate is never restated here.
     *
     * @return array{layer: string, rule_id: string, type: string, value: string, discount: string, rule_version: string, stops: bool}|null
     */
    private function accountGradeLayer(
        string $product,
        string $base,
        string $alreadyDiscounted,
        ?User $user,
        int $scale,
    ): ?array {
        if ($user === null) {
            return null;
        }

        if ((bool) $this->config->get('discounts.account_grade_layer.enabled', true) === false) {
            return null;
        }

        $excluded = array_map(
            static fn ($p): string => strtolower((string) $p),
            (array) $this->config->get('discounts.account_grade_layer.excluded_products', []),
        );

        if (in_array($product, $excluded, true)) {
            return null;
        }

        $priced = $this->accountDiscounts->priceFor($user, $product, $base);
        $discount = (string) $priced['discount'];

        if (bccomp($discount, '0', $scale) <= 0) {
            return null;
        }

        $discount = $this->clampAgainstRemaining($base, $alreadyDiscounted, $discount, $scale);

        return [
            'layer' => 'account_grade',
            'rule_id' => 'account_grade',
            'type' => 'percentage',
            // Reported back in whole-percent units for display consistency.
            'value' => bcmul((string) $priced['rate'], '100', 2),
            'discount' => $discount,
            'rule_version' => (string) $this->config->get('account_grades.rule_version', '1'),
            'stops' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function discountFor(array $rule, string $base, int $scale): string
    {
        $type = (string) $rule['discount_type'];
        $value = (string) $rule['discount_value'];

        if ($type === 'percentage') {
            $rate = bcdiv($value, '100', self::RATE_SCALE);
            $discount = $this->round(bcmul($base, $rate, self::RATE_SCALE), $scale);
        } else {
            $discount = bcadd($value, '0', $scale);
        }

        $max = $rule['maximum_discount'] ?? null;

        if (is_string($max) && $max !== '' && bccomp($discount, $max, $scale) > 0) {
            $discount = bcadd($max, '0', $scale);
        }

        // A fixed discount may never exceed the base price.
        if (bccomp($discount, $base, $scale) > 0) {
            $discount = $base;
        }

        if (bccomp($discount, '0', $scale) < 0) {
            $discount = bcadd('0', '0', $scale);
        }

        return $discount;
    }

    private function clampAgainstRemaining(string $base, string $alreadyDiscounted, string $discount, int $scale): string
    {
        $remaining = bcsub($base, $alreadyDiscounted, $scale);

        if (bccomp($remaining, '0', $scale) <= 0) {
            return bcadd('0', '0', $scale);
        }

        return bccomp($discount, $remaining, $scale) > 0 ? $remaining : $discount;
    }

    private function capTotal(string $base, string $discountTotal, int $scale): string
    {
        $maxPercent = (string) $this->config->get('discounts.limits.max_total_percentage', '60');

        if (is_numeric($maxPercent) && bccomp($base, '0', $scale) > 0) {
            $cap = $this->round(bcmul($base, bcdiv($maxPercent, '100', self::RATE_SCALE), self::RATE_SCALE), $scale);

            if (bccomp($discountTotal, $cap, $scale) > 0) {
                $discountTotal = $cap;
            }
        }

        if (bccomp($discountTotal, $base, $scale) > 0) {
            $discountTotal = $base;
        }

        if (bccomp($discountTotal, '0', $scale) < 0) {
            $discountTotal = bcadd('0', '0', $scale);
        }

        return $discountTotal;
    }

    /**
     * Half-up rounding on a decimal string, without ever touching a float.
     */
    private function round(string $value, int $scale): string
    {
        $increment = '0.'.str_repeat('0', $scale).'5';

        if (bccomp($value, '0', self::RATE_SCALE) >= 0) {
            return bcadd($value, $increment, $scale);
        }

        return bcsub($value, $increment, $scale);
    }

    /**
     * @return array{currency: string, catalogue_version: string, generated_at: string, products: list<array<string, mixed>>, immutable_products: list<array<string, mixed>>}
     */
    private function buildPublicCatalogue(string $locale): array
    {
        $maxRows = (int) $this->config->get('discounts.page.max_rows', 200);
        $maxProducts = (int) $this->config->get('discounts.page.max_products_per_page', 25);

        $rowsRendered = 0;
        $products = [];

        foreach ((array) $this->config->get('discounts.products', []) as $productKey => $product) {
            if (count($products) >= $maxProducts) {
                break;
            }

            if (! is_array($product)
                || (bool) ($product['enabled'] ?? false) === false
                || (bool) ($product['public_visible'] ?? false) === false) {
                continue;
            }

            $productKey = (string) $productKey;
            $rules = [];

            foreach ((array) $this->config->get('discounts.rules', []) as $key => $rule) {
                if ($rowsRendered >= $maxRows) {
                    break;
                }

                if (! is_array($rule) || strtolower((string) ($rule['product'] ?? '')) !== $productKey) {
                    continue;
                }

                if ((bool) ($rule['enabled'] ?? false) === false
                    || (bool) ($rule['public_visible'] ?? false) === false) {
                    continue;
                }

                if (! $this->isEffective($rule) || ! $this->isRuleValid($rule)) {
                    continue;
                }

                $rowsRendered++;

                $rules[] = [
                    // rule_id is a public, stable key with no internal meaning;
                    // no database id and no internal priority is published.
                    'rule_id' => (string) ($rule['rule_id'] ?? $key),
                    'market' => (string) ($rule['market'] ?? '*'),
                    'discount_type' => (string) $rule['discount_type'],
                    'discount_value' => (string) $rule['discount_value'],
                    'minimum_amount' => isset($rule['minimum_amount']) && $rule['minimum_amount'] !== null
                        ? (string) $rule['minimum_amount']
                        : null,
                    'maximum_discount' => isset($rule['maximum_discount']) && $rule['maximum_discount'] !== null
                        ? (string) $rule['maximum_discount']
                        : null,
                    'effective_from' => isset($rule['effective_from']) ? (string) $rule['effective_from'] : null,
                    'effective_to' => isset($rule['effective_to']) && $rule['effective_to'] !== null
                        ? (string) $rule['effective_to']
                        : null,
                    'stackable' => (bool) ($rule['stackable'] ?? true),
                    'rule_version' => (string) ($rule['rule_version'] ?? '1'),
                    'description_key' => (string) ($rule['description_key'] ?? ''),
                ];
            }

            $products[] = [
                'product' => $productKey,
                'label_key' => (string) ($product['label_key'] ?? ''),
                'markets' => array_values(array_map('strval', (array) ($product['markets'] ?? []))),
                'supports_payout_display' => (bool) ($product['supports_payout_display'] ?? false),
                'immutable_price' => $this->isImmutableProduct($productKey),
                'rules' => $rules,
            ];
        }

        $immutable = [];

        foreach ($this->immutablePriceAudit() as $product => $audit) {
            $immutable[] = [
                'product' => $product,
                'price' => $audit['configured'] ?? $audit['expected'],
                'currency' => (string) $this->config->get('discounts.currency', 'THB'),
            ];
        }

        // The account-grade layer is DESCRIBED, never quantified, on a public
        // page: the actual rate belongs to the signed-in user's grade and is
        // owned by AccountDiscountService. Publishing the rate here would
        // leak one account's pricing to everyone.
        $gradeLayer = (array) $this->config->get('discounts.account_grade_layer', []);
        $gradeVisible = (bool) ($gradeLayer['enabled'] ?? false) && (bool) ($gradeLayer['public_visible'] ?? false);

        return [
            'currency' => (string) $this->config->get('discounts.currency', 'THB'),
            'catalogue_version' => (string) $this->config->get('discounts.catalogue_version', '1'),
            'generated_at' => now()->toIso8601String(),
            'products' => $products,
            'immutable_products' => $immutable,
            'account_grade_layer' => [
                'published' => $gradeVisible,
                'description_key' => $gradeVisible ? (string) ($gradeLayer['description_key'] ?? '') : '',
                'excluded_products' => $gradeVisible
                    ? array_values(array_map('strval', (array) ($gradeLayer['excluded_products'] ?? [])))
                    : [],
            ],
        ];
    }
}
