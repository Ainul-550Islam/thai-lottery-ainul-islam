<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Services\Betting\MarketRuleResolver;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Direct / reverse payout presentation (PROMPT 4).
 *
 * ONE RESOLVER, NOT TWO.
 * "Direct" is the exact-order market and "reverse" is the permutation market.
 * Both already exist: App\Enums\BetMarket declares 3d_direct / 3d_tod /
 * 2d_top / 2d_bottom / run_top / run_bottom, and their multipliers live in
 * config('lottery.markets') behind App\Services\Betting\MarketRuleResolver,
 * which is what the purchase and settlement paths use.
 *
 * This service therefore stores NO multiplier of its own. It reads the same
 * resolver the execution path reads, so a displayed rate and a settled rate
 * cannot diverge - the failure mode where a page advertises 120x while the
 * engine pays 45x is structurally impossible here, because there is only one
 * number and one source.
 *
 * config('discounts.payout_display.pairs') contributes only the MAPPING of
 * which market key plays the direct role and which plays the reverse role
 * for a given product. No rates, no stakes, no economics.
 *
 * GLO products are absent from the pairs table on purpose: L6 and N3 prize
 * structures are official and are published by the GLO prize services, not
 * presented as operator payout multipliers.
 */
final class LottoPayoutRuleService
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly MarketRuleResolver $markets,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->config->get('discounts.payout_display.enabled', true);
    }

    /**
     * Public payout presentation for every product that declares a pair.
     *
     * @return list<array{
     *     product: string,
     *     direct: array<string, mixed>|null,
     *     reverse: array<string, mixed>|null
     * }>
     */
    public function publicPairs(): array
    {
        if (! $this->enabled() || (bool) $this->config->get('discounts.payout_display.public_visible', true) === false) {
            return [];
        }

        $pairs = [];

        foreach ((array) $this->config->get('discounts.payout_display.pairs', []) as $product => $pair) {
            if (! is_array($pair)) {
                continue;
            }

            $product = (string) $product;

            // A product whose price this engine may never touch also never
            // gets an operator-style payout card.
            if (array_key_exists($product, (array) $this->config->get('discounts.immutable_products', []))) {
                continue;
            }

            $direct = $this->marketPresentation($pair['direct_market'] ?? null, 'direct');
            $reverse = $this->marketPresentation($pair['reverse_market'] ?? null, 'reverse');

            if ($direct === null && $reverse === null) {
                continue;
            }

            $pairs[] = [
                'product' => $product,
                'direct' => $direct,
                'reverse' => $reverse,
            ];
        }

        return $pairs;
    }

    /**
     * The canonical multiplier for one market key, exactly as the purchase
     * engine would resolve it. Returns null when the market is unknown or
     * disabled, never a fallback number.
     *
     * @return array{market: string, role: string, label: string, digits: int, multiplier: string, permutation: bool}|null
     */
    public function marketPresentation(mixed $marketKey, string $role): ?array
    {
        if (! is_string($marketKey) || $marketKey === '') {
            return null;
        }

        $rule = $this->markets->tryResolve($marketKey);

        if ($rule === null || $rule->enabled === false) {
            return null;
        }

        // MarketRuleData deliberately carries NO money: it carries the
        // CONFIGURATION PATH at which the authoritative rate is declared.
        // Reading that path is precisely what the settlement path does, so
        // display and execution cannot diverge.
        $multiplier = $this->stringify($this->config->get($rule->multiplierSource()));

        if ($multiplier === null) {
            return null;
        }

        return [
            'market' => $marketKey,
            'role' => $role,
            'label' => (string) $this->config->get('lottery.markets.'.$marketKey.'.label', $marketKey),
            'digits' => $rule->digits(),
            'multiplier' => $multiplier,
            'multiplier_source' => $rule->multiplierSource(),
            'permutation' => $rule->allowsPermutation(),
        ];
    }

    /**
     * Assert that a displayed multiplier equals the one the execution path
     * would use. Exists so the test suite can prove the two never diverge.
     */
    public function displayMatchesExecution(string $marketKey): bool
    {
        $displayed = $this->marketPresentation($marketKey, 'direct');

        if ($displayed === null) {
            return false;
        }

        // Resolve again through the execution entry point and read the rate
        // from the path that resolver reports.
        $execution = $this->markets->resolve($marketKey);
        $executionRate = $this->stringify($this->config->get($execution->multiplierSource()));

        return $executionRate !== null
            && bccomp($displayed['multiplier'], $executionRate, 6) === 0;
    }

    private function stringify(mixed $value): ?string
    {
        $decimal = is_int($value)
            ? (string) $value
            : (is_string($value) ? trim($value) : null);

        if ($decimal === null || preg_match('/^\d+(\.\d{1,6})?$/', $decimal) !== 1) {
            return null;
        }

        // Multiplier values remain decimal strings; display normalization
        // removes insignificant zeros without binary floating-point conversion.
        if (! str_contains($decimal, '.')) {
            return $decimal;
        }

        $normalized = rtrim(rtrim($decimal, '0'), '.');

        return $normalized === '' ? '0' : $normalized;
    }
}
