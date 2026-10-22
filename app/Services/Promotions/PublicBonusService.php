<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use Illuminate\Support\Carbon;

/**
 * Public-safe active bonuses / campaigns for the Home page.
 *
 * Reads config('home.campaigns') only. Filters out expired or not-yet-valid
 * windows. Never invents referral percentages, cash amounts or affiliate
 * offers. Empty catalogue → status UNAVAILABLE with an honest empty message.
 */
class PublicBonusService
{
    /**
     * @return array{
     *     status: string,
     *     campaigns: list<array{
     *         id: string,
     *         title: string,
     *         description: string,
     *         valid_from: string|null,
     *         valid_until: string|null,
     *         status: string,
     *         cta_label: string|null,
     *         cta_url: string|null,
     *     }>,
     *     message: string,
     * }
     */
    public function activeCampaigns(): array
    {
        $configured = (array) config('home.campaigns', []);
        $max = max(0, (int) config('home.campaigns_max', 6));

        if ($configured === []) {
            return [
                'status' => 'UNAVAILABLE',
                'campaigns' => [],
                'message' => 'No active promotions',
            ];
        }

        $now = Carbon::now();
        $active = [];

        foreach ($configured as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $from = $this->parseDate($raw['valid_from'] ?? null);
            $until = $this->parseDate($raw['valid_until'] ?? null);

            if ($from !== null && $from->greaterThan($now)) {
                continue;
            }
            if ($until !== null && $until->lessThan($now)) {
                continue;
            }

            $title = trim((string) ($raw['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $active[] = [
                'id' => (string) ($raw['id'] ?? sha1($title)),
                'title' => $title,
                'description' => trim((string) ($raw['description'] ?? '')),
                'valid_from' => $from?->toIso8601String(),
                'valid_until' => $until?->toIso8601String(),
                'status' => 'active',
                'cta_label' => isset($raw['cta_label']) && is_string($raw['cta_label']) && $raw['cta_label'] !== ''
                    ? $raw['cta_label']
                    : null,
                'cta_url' => isset($raw['cta_url']) && is_string($raw['cta_url'])
                    ? $this->safeCtaUrl($raw['cta_url'])
                    : null,
            ];

            if (count($active) >= $max) {
                break;
            }
        }

        if ($active === []) {
            return [
                'status' => 'UNAVAILABLE',
                'campaigns' => [],
                'message' => 'No active promotions',
            ];
        }

        return [
            'status' => 'VERIFIED',
            'campaigns' => $active,
            'message' => '',
        ];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Only two destinations are admitted for a promotion call-to-action: an absolute
     * https URL with a host, or a single-slash site-relative path. Protocol-relative
     * and backslash forms, other schemes, control characters and anything unparseable
     * are dropped to null. The campaign still shows; only the link is withheld.
     */
    private function safeCtaUrl(string $value): ?string
    {
        $url = trim($value);

        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        if (str_starts_with($url, '/')) {
            if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
                return null;
            }

            return $url;
        }

        $parts = parse_url($url);

        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        return $url;
    }
}
