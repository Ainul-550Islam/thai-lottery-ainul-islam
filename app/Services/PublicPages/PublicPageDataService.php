<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

use App\Services\PublicPages\AboutPageService;
use App\Services\PublicPages\TermsPageService;
use App\Services\PublicPages\VisionMissionService;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Composition root for public informational pages (/about, /vision, /terms).
 *
 * Cache key always includes: language + legal version + content version.
 * A legal.version or config content_version bump therefore invalidates every
 * cached variant. Failures degrade to a safe UNAVAILABLE stub (same pattern
 * as HomePageDataService) so a public page never fatals into a stack trace.
 */
final class PublicPageDataService
{
    public function __construct(
        private readonly AboutPageService $about,
        private readonly VisionMissionService $vision,
        private readonly TermsPageService $terms,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function about(?string $locale = null): array
    {
        return $this->section('about', fn (): array => $this->about->data($locale), $locale);
    }

    /**
     * @return array<string, mixed>
     */
    public function vision(?string $locale = null): array
    {
        return $this->section('vision', fn (): array => $this->vision->data($locale), $locale);
    }

    /**
     * @return array<string, mixed>
     */
    public function terms(?string $locale = null): array
    {
        return $this->section('terms', fn (): array => $this->terms->data($locale), $locale);
    }

    /**
     * Composed SEO/meta block for a public page.
     *
     * @return array<string, string>
     */
    public function meta(string $page, ?string $locale = null): array
    {
        $locale = $this->locale($locale);
        $bag = app(PublicPageTextBag::class)->for($page, $locale);

        return [
            'title' => (string) ($bag['meta_title'] ?? $page),
            'description' => (string) ($bag['meta_description'] ?? ''),
            'canonical' => $this->canonical($page),
            'lang' => str_replace('_', '-', $locale),
            'og_title' => (string) ($bag['meta_title'] ?? $page),
            'og_description' => (string) ($bag['meta_description'] ?? ''),
            'og_type' => (string) ($bag['og_type'] ?? 'website'),
            'og_url' => $this->canonical($page),
        ];
    }

    private function canonical(string $page): string
    {
        $path = match ($page) {
            'about' => '/about',
            'vision' => '/vision',
            'terms' => '/terms',
            default => '/',
        };

        $base = rtrim((string) config('app.url'), '/');

        return $base.$path;
    }

    private function locale(?string $locale): string
    {
        return $locale !== null && $locale !== '' ? $locale : (string) app()->getLocale();
    }

    /**
     * @param  callable(): array<string, mixed>  $producer
     * @return array<string, mixed>
     */
    private function section(string $page, callable $producer, ?string $locale): array
    {
        $locale = $this->locale($locale);
        $legalVersion = (string) config('legal.content_version', '1').'|'.(string) config('legal.version', 'v0');
        $contentVersion = (string) config('public_pages.content_version', '1');
        $key = sprintf(
            'public_pages.%s.%s.legal_%s.content_%s',
            $page,
            $locale,
            md5($legalVersion),
            md5($contentVersion),
        );
        $ttl = max(0, (int) config('public_pages.cache_ttl_seconds', 300));

        try {
            if ($ttl === 0) {
                return $producer();
            }

            /** @var array<string, mixed> $data */
            $data = Cache::remember($key, $ttl, function () use ($producer): array {
                $fresh = $producer();
                $fresh['content_version'] = (string) config('public_pages.content_version', '1');
                $fresh['legal_version'] = (string) config('legal.version', 'v0');

                return $fresh;
            });

            return $data;
        } catch (Throwable $e) {
            report($e);

            return [
                'status' => 'UNAVAILABLE',
                'page' => $page,
                'locale' => $locale,
                'content_version' => (string) config('public_pages.content_version', '1'),
                'legal_version' => (string) config('legal.version', 'v0'),
            ];
        }
    }
}
