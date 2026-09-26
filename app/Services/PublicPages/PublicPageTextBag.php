<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

/**
 * Localized text bag for public pages.
 *
 * Resolves trans('public_pages.*') for the requested locale with graceful
 * fallback to lang/en when a key is missing, and exposes a stable meta_* view
 * consumed by PublicPageDataService::meta(). Never contains secrets or PII.
 */
final class PublicPageTextBag
{
    /**
     * @return array<string, mixed>
     */
    public function for(string $page, string $locale): array
    {
        $titleKey = $page.'_meta_title';
        $descriptionKey = $page.'_meta_description';

        return [
            'meta_title' => $this->line($titleKey, $locale),
            'meta_description' => $this->line($descriptionKey, $locale),
            'og_type' => $this->line('og_type', $locale),
        ];
    }

    public function line(string $key, string $locale): string
    {
        $line = trans('public_pages.'.$key, [], $locale);
        if (! is_string($line) || $line === '' || $line === 'public_pages.'.$key) {
            $fallback = trans('public_pages.'.$key, [], 'en');
            if (is_string($fallback) && $fallback !== 'public_pages.'.$key) {
                return $fallback;
            }

            return '';
        }

        return $line;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(string $locale): array
    {
        $loaded = [];
        foreach (['en', 'th'] as $candidate) {
            $path = lang_path($candidate.'/public_pages.php');
            if (is_file($path)) {
                /** @var array<string, mixed> $rows */
                $rows = require $path;
                $loaded[$candidate] = $rows;
            }
        }

        return $loaded[$locale] ?? $loaded['en'] ?? [];
    }
}
