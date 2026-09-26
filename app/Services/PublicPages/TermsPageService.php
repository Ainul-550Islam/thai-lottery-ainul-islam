<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

/**
 * Terms page data assembly.
 *
 * Version metadata comes from config/legal.php (fixed dates, never dynamic).
 * Prize economics interpolate config('glo.*') / config('lottery.*') — never
 * hard-coded literals, never the banned 40 THB / fixed-N3 payout tables.
 * Stamp duty copy mirrors GloStampDutyCalculator (1 THB per 200 THB or
 * fraction; income tax exempt; NO 0.5%/1% withholding regression).
 */
final class TermsPageService
{
    public function __construct(private readonly PublicPageTextBag $text)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function data(?string $locale = null): array
    {
        $locale = $locale !== null && $locale !== '' ? $locale : (string) app()->getLocale();
        $version = (string) config('legal.version', 'v1.0.0');
        $effectiveAt = (string) config('legal.effective_at', '');
        $updatedAt = (string) config('legal.updated_at', '');
        if ($effectiveAt === '') {
            $effectiveAt = (string) config('legal.updated_at', '');
        }
        $notConfigured = $this->text->line('terms_not_configured', $locale)
            ?: $this->text->line('not_configured', $locale);

        $l6Price = $this->formatBaht((string) config('glo.l6.ticket_price', config('lottery.price', '0.00')));
        $n3Price = $this->formatBaht((string) config('glo.n3.ticket_price', '0.00'));
        $l6Allocation = $this->formatBaht((string) config('glo.l6.full_allocation', '0.00'));
        $l6Units = (string) config('glo.l6.full_sale_units', '1000000');
        $l6PrizeCount = (string) config('glo.l6.total_prize_count', '0');
        $poolRate = (string) config('glo.n3.pool_rate', '0.60');
        $claimMinAge = (string) config('glo.claims.min_claimant_age', 20);
        $claimWindow = (string) config('glo.claims.window_years', 2);

        $replace = [
            '{l6_price}' => $l6Price,
            '{l6_allocation}' => $l6Allocation,
            '{l6_units}' => $this->groupDigits($l6Units),
            '{l6_prize_count}' => $this->groupDigits($l6PrizeCount),
            '{n3_price}' => $n3Price,
            '{n3_pool_rate}' => $this->formatPercent($poolRate),
            '{claim_min_age}' => $claimMinAge,
            '{claim_window_years}' => $claimWindow,
            '{stamp_divisor}' => (string) config('glo.stamp_duty.divisor', '200'),
            '{stamp_unit_baht}' => $this->formatBaht((string) config('glo.stamp_duty.per_unit_baht', '1.00')),
        ];

        $sections = [
            [
                'id' => 'scope',
                'title' => $this->text->line('terms_intro_title', $locale),
                'body' => $this->text->line('terms_intro_text', $locale),
            ],
            [
                'id' => 'products',
                'title' => $this->text->line('terms_products_title', $locale),
                'body' => strtr((string) $this->text->line('terms_products_l6_text', $locale), $replace),
                'extra' => [
                    strtr((string) $this->text->line('terms_products_n3_text', $locale), $replace),
                    (string) $this->text->line('terms_products_separation_text', $locale),
                ],
            ],
            [
                'id' => 'stamp-duty',
                'title' => $this->text->line('terms_stamp_title', $locale),
                'body' => strtr((string) $this->text->line('terms_stamp_text', $locale), $replace),
            ],
            [
                'id' => 'accounts',
                'title' => $this->text->line('terms_accounts_title', $locale),
                'body' => (string) $this->text->line('terms_accounts_one_text', $locale),
                'extra' => [
                    (string) $this->text->line('terms_accounts_verification_text', $locale),
                ],
            ],
            [
                'id' => 'age',
                'title' => $this->text->line('terms_age_title', $locale),
                'body' => strtr((string) $this->text->line('terms_age_registration_text', $locale), $replace),
                'extra' => [
                    strtr((string) $this->text->line('terms_age_purchase_text', $locale), $replace),
                    strtr((string) $this->text->line('terms_age_claim_text', $locale), $replace),
                ],
            ],
            [
                'id' => 'ticket-ownership',
                'title' => $this->text->line('terms_ticket_title', $locale),
                'body' => (string) $this->text->line('terms_ticket_text', $locale),
            ],
            [
                'id' => 'responsible-gaming',
                'title' => $this->text->line('terms_responsible_title', $locale),
                'body' => (string) $this->text->line('terms_responsible_text', $locale),
            ],
            [
                'id' => 'claims',
                'title' => $this->text->line('terms_claim_title', $locale),
                'body' => strtr((string) $this->text->line('terms_claim_text', $locale), $replace),
            ],
            [
                'id' => 'disclaimer',
                'title' => $this->text->line('terms_disclaimer_title', $locale),
                'body' => (string) $this->text->line('terms_disclaimer_text', $locale),
            ],
            [
                'id' => 'prohibited',
                'title' => $this->text->line('terms_prohibited_title', $locale),
                'body' => (string) $this->text->line('terms_prohibited_text', $locale),
            ],
            [
                'id' => 'changes',
                'title' => $this->text->line('terms_contact_title', $locale),
                'body' => (string) $this->text->line('terms_contact_text', $locale),
            ],
        ];

        $operator = (array) config('legal.operator', []);

        return [
            'status' => 'AVAILABLE',
            'locale' => $locale,
            'title' => $this->text->line('terms_title', $locale),
            'meta_title' => $this->text->line('terms_meta_title', $locale),
            'meta_description' => $this->text->line('terms_meta_description', $locale),
            'version' => $version,
            'version_label' => $this->text->line('terms_version_label', $locale),
            'effective_at' => $effectiveAt,
            'effective_label' => $this->text->line('terms_effective_label', $locale),
            'updated_at' => $updatedAt,
            'updated_label' => $this->text->line('terms_updated_label', $locale),
            'not_configured' => $notConfigured,
            'sections' => $sections,
            'facts' => [
                'l6_price' => $l6Price,
                'l6_allocation' => $l6Allocation,
                'l6_units' => $this->groupDigits($l6Units),
                'l6_prize_count' => $this->groupDigits($l6PrizeCount),
                'n3_price' => $n3Price,
                'n3_pool_rate' => $this->formatPercent($poolRate),
                'stamp_divisor' => (string) config('glo.stamp_duty.divisor', '200'),
                'stamp_unit_baht' => $this->formatBaht((string) config('glo.stamp_duty.per_unit_baht', '1.00')),
                'income_tax_exempt' => (bool) config('glo.stamp_duty.income_tax_exempt', true),
                'claim_min_age' => $claimMinAge,
                'claim_window_years' => $claimWindow,
                'operator_markets' => array_values(array_map(
                    static fn ($m): string => (string) $m,
                    (array) config('public_pages.operator_markets', []),
                )),
                'glo_products' => ['L6', 'N3'],
                'responsible_gaming_features' => array_values(array_map(
                    static fn ($f): string => (string) $f,
                    (array) config('public_pages.responsible_gaming_features', []),
                )),
            ],
            'operator' => [
                'legal_name' => is_string($operator['legal_name'] ?? null) && $operator['legal_name'] !== ''
                    ? $operator['legal_name'] : $notConfigured,
                'registration_number' => is_string($operator['registration_number'] ?? null) && $operator['registration_number'] !== ''
                    ? $operator['registration_number'] : $notConfigured,
                'support_email' => is_string($operator['support_email'] ?? null) && $operator['support_email'] !== ''
                    ? $operator['support_email'] : $notConfigured,
                'support_phone' => is_string($operator['support_phone'] ?? null) && $operator['support_phone'] !== ''
                    ? $operator['support_phone'] : $notConfigured,
                'address' => is_string($operator['address'] ?? null) && $operator['address'] !== ''
                    ? $operator['address'] : $notConfigured,
            ],
        ];
    }

    private function formatBaht(string $raw): string
    {
        // Display-only grouping; never float-cast.
        $raw = trim($raw);
        if ($raw === '' || ! is_numeric($raw)) {
            return '0.00';
        }

        $parts = explode('.', $raw, 2);
        $whole = $this->groupDigits(ltrim($parts[0], '+') === '' ? '0' : ltrim($parts[0], '+'));
        $frac = $parts[1] ?? '';
        if (strlen($frac) < 2) {
            $frac = str_pad($frac, 2, '0');
        } else {
            $frac = substr($frac, 0, 2);
        }

        return $whole.'.'.$frac;
    }

    private function formatPercent(string $rate): string
    {
        // '0.60' → '60%' without float math (string shift only).
        $rate = trim($rate);
        if ($rate === '' || ! is_numeric($rate)) {
            return '0%';
        }
        $parts = explode('.', ltrim($rate, '+'), 2);
        $whole = (int) ($parts[0] === '' || $parts[0] === '-' ? '0' : $parts[0]);
        $frac = str_pad((string) ($parts[1] ?? ''), 2, '0');
        $frac = substr($frac, 0, 2);
        // hundredths digit as percent (0.60 → 60)
        $percent = ($whole * 100) + (int) $frac;

        return $percent.'%';
    }

    private function groupDigits(string $digits): string
    {
        $digits = preg_replace('/\D+/', '', $digits) ?? '';
        if ($digits === '') {
            return '0';
        }

        return number_format((int) $digits, 0, '.', ',');
    }
}
