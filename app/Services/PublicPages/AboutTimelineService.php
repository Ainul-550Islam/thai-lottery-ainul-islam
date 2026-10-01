<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

use Illuminate\Support\Facades\Cache;

/**
 * Source-labelled historical timeline for the public About page.
 *
 * The records describe Thailand lottery history reported by the observed
 * public reference page. They do not describe the founding, ownership,
 * licensing, or legal identity of this application.
 */
final class AboutTimelineService
{
    /**
     * @return array<int, array{year: string, era: string, title: string, description: string, display_order: int, icon: string}>
     */
    public function milestones(?string $locale = null): array
    {
        $locale = $locale !== null && $locale !== '' ? $locale : (string) app()->getLocale();
        $cacheKey = 'public_pages.about.timeline.'.str_replace('_', '-', $locale).'.'.(string) config('public_pages.content_version', '1');
        $ttl = max(0, (int) config('public_pages.cache_ttl_seconds', 300));

        $producer = function () use ($locale): array {
            $records = [
                ['year' => '1874', 'era' => 'CE', 'key' => '1874', 'display_order' => 1, 'icon' => '01'],
                ['year' => '1917', 'era' => 'CE', 'key' => '1917', 'display_order' => 2, 'icon' => '02'],
                ['year' => '1923', 'era' => 'CE', 'key' => '1923', 'display_order' => 3, 'icon' => '03'],
                ['year' => '1933', 'era' => 'CE', 'key' => '1933', 'display_order' => 4, 'icon' => '04'],
                ['year' => '1935', 'era' => 'CE', 'key' => '1935', 'display_order' => 5, 'icon' => '05'],
                ['year' => '1939', 'era' => 'CE', 'key' => '1939', 'display_order' => 6, 'icon' => '06'],
                ['year' => '1974', 'era' => 'CE', 'key' => '1974', 'display_order' => 7, 'icon' => '07'],
                ['year' => '2000', 'era' => 'CE', 'key' => '2000', 'display_order' => 8, 'icon' => '08'],
            ];

            $valid = [];
            foreach ($records as $record) {
                $title = trim((string) trans('public_pages.about_timeline.'.$record['key'].'.title', [], $locale));
                $description = trim((string) trans('public_pages.about_timeline.'.$record['key'].'.description', [], $locale));
                $year = trim((string) $record['year']);
                $order = (int) $record['display_order'];

                if ($title === '' || str_starts_with($title, 'public_pages.') || $description === '' || str_starts_with($description, 'public_pages.')) {
                    continue;
                }

                if ($year === '' || ! preg_match('/^[0-9]{4}$/', $year) || $order < 1) {
                    continue;
                }

                $valid[] = [
                    'year' => $year,
                    'era' => (string) $record['era'],
                    'title' => $title,
                    'description' => $description,
                    'display_order' => $order,
                    'icon' => (string) $record['icon'],
                ];
            }

            usort($valid, static fn (array $left, array $right): int => $left['display_order'] <=> $right['display_order']);

            return $valid;
        };

        if ($ttl === 0) {
            return $producer();
        }

        /** @var array<int, array{year: string, era: string, title: string, description: string, display_order: int, icon: string}> $data */
        $data = Cache::remember($cacheKey, $ttl, $producer);

        return $data;
    }
}
