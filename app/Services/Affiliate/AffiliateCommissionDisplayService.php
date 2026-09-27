<?php

declare(strict_types=1);

namespace App\Services\Affiliate;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Public-safe affiliate commission presentation (PROMPT 4).
 *
 * WHAT THIS IS ALLOWED TO SHOW
 * The published headline bands a prospective affiliate may see, and only when
 * config('discounts.affiliate_display.publish') is true and the individual
 * band is enabled AND public_visible.
 *
 * WHAT IT MUST NEVER SHOW, AND CANNOT
 * Real commission accrual belongs to App\Services\Agent\
 * CommissionCalculationService and the agent_commissions table. This class
 * never touches either: it has no model, no query builder and no database
 * dependency at all, so there is no code path by which one agent's rate,
 * another affiliate's earnings, an internal margin or a settlement figure
 * could reach a public page from here.
 *
 * The rates below are this platform's own published bands from
 * config/discounts.php. They are not copied from any other operator.
 */
final class AffiliateCommissionDisplayService
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function published(): bool
    {
        return (bool) $this->config->get('discounts.affiliate_display.publish', false);
    }

    /**
     * The public commission catalogue.
     *
     * @return array{
     *     published: bool,
     *     currency: string,
     *     note_key: string,
     *     bands: list<array{band: string, label_key: string, rate_percentage: string, eligibility_key: string}>
     * }
     */
    public function publicCatalogue(): array
    {
        $currency = (string) $this->config->get('discounts.affiliate_display.currency', 'THB');
        $noteKey = (string) $this->config->get('discounts.affiliate_display.note_key', '');

        if (! $this->published()) {
            return [
                'published' => false,
                'currency' => $currency,
                'note_key' => $noteKey,
                'bands' => [],
            ];
        }

        $bands = [];

        foreach ((array) $this->config->get('discounts.affiliate_display.bands', []) as $key => $band) {
            if (! is_array($band)) {
                continue;
            }

            if ((bool) ($band['enabled'] ?? false) === false) {
                continue;
            }

            // public_visible is the gate that keeps internal pilot bands off
            // the page even while they are enabled for internal use.
            if ((bool) ($band['public_visible'] ?? false) === false) {
                continue;
            }

            $rate = (string) ($band['rate_percentage'] ?? '');

            if (! is_numeric($rate) || ! $this->rateWithinBounds($rate)) {
                continue;
            }

            $bands[] = [
                'band' => (string) $key,
                'label_key' => (string) ($band['label_key'] ?? ''),
                'rate_percentage' => $rate,
                'eligibility_key' => (string) ($band['eligibility_key'] ?? ''),
            ];
        }

        return [
            'published' => true,
            'currency' => $currency,
            'note_key' => $noteKey,
            'bands' => $bands,
        ];
    }

    /**
     * A published band must obey the same 0..100 percentage bounds every
     * other rate in this system obeys.
     */
    private function rateWithinBounds(string $rate): bool
    {
        $min = (string) $this->config->get('discounts.limits.min_percentage', '0');
        $max = (string) $this->config->get('discounts.limits.max_percentage', '100');

        return bccomp($rate, $min, 4) >= 0 && bccomp($rate, $max, 4) <= 0;
    }
}
