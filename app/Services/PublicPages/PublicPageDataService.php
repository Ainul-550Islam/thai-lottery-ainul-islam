<?php

declare(strict_types=1);

namespace App\Services\PublicPages;

use App\Services\Account\PublicAccountInfoService;
use App\Services\Media\PublicAppLinkService;
use App\Services\Pricing\LottoDiscountService;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Composition root for public informational pages (/about, /vision, /terms, /privacy).
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
        private readonly PrivacyPageService $privacy,
        private readonly FeesPageService $fees,
        private readonly PublicAccountInfoService $accountInfo,
        private readonly LottoDiscountService $discounts,
        private readonly PublicAppLinkService $appLinks,
    ) {}

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
     * @return array<string, mixed>
     */
    public function privacy(?string $locale = null): array
    {
        return $this->section('privacy', fn (): array => $this->privacy->data($locale), $locale);
    }

    /**
     * @return array<string, mixed>
     */
    public function fees(?string $locale = null): array
    {
        $locale = $this->locale($locale);

        return $this->section('fees', function () use ($locale): array {
            return [
                'status' => 'AVAILABLE',
                'locale' => $locale,
                'title' => $this->text()->line('fees_title', $locale),
                'meta_title' => $this->text()->line('fees_meta_title', $locale),
                'meta_description' => $this->text()->line('fees_meta_description', $locale),
                'version' => (string) config('fees.rule_version', '1'),
                'currency' => (string) config('fees.currency', 'THB'),
                'groups' => $this->fees->publicFeeGroups($locale),
                'rows' => $this->fees->publicFees($locale),
            ];
        }, $locale);
    }

    /**
     * @return array<string, mixed>
     */
    public function verification(?string $locale = null): array
    {
        $locale = $this->locale($locale);

        return $this->section('verification', function () use ($locale): array {
            return [
                'status' => 'AVAILABLE',
                'locale' => $locale,
                'title' => $this->text()->line('verification_title', $locale),
                'meta_title' => $this->text()->line('verification_meta_title', $locale),
                'meta_description' => $this->text()->line('verification_meta_description', $locale),
                'steps' => $this->accountInfo->verificationSteps(),
                'documents' => array_values(array_map('strval', (array) config('account_verification.document_types', []))),
                'max_upload_mb' => (int) config('account_verification.upload.max_mb', 0),
                'private_route' => route('account.verification'),
            ];
        }, $locale);
    }

    /**
     * @return array<string, mixed>
     */
    public function grades(?string $locale = null): array
    {
        $locale = $this->locale($locale);

        return $this->section('grades', function () use ($locale): array {
            return [
                'status' => 'AVAILABLE',
                'locale' => $locale,
                'title' => $this->text()->line('grade_title', $locale),
                'meta_title' => $this->text()->line('grade_meta_title', $locale),
                'meta_description' => $this->text()->line('grade_meta_description', $locale),
                'period_days' => (int) config('account_grades.grade_period_days', 30),
                'currency' => (string) config('account_grades.currency', 'THB'),
                'rule_version' => (string) config('account_grades.rule_version', '1'),
                'tiers' => $this->accountInfo->gradeLadder()['tiers'] ?? [],
                'private_route' => route('account.grade'),
            ];
        }, $locale);
    }

    /**
     * @return array<string, mixed>
     */
    public function discounts(?string $locale = null): array
    {
        $locale = $this->locale($locale);

        return $this->section('discounts', function () use ($locale): array {
            return [
                'status' => 'AVAILABLE',
                'locale' => $locale,
                'title' => $this->text()->line('discount_heading', $locale),
                'meta_title' => $this->text()->line('discount_meta_title', $locale),
                'meta_description' => $this->text()->line('discount_meta_description', $locale),
                'catalogue' => $this->discounts->publicCatalogue($locale),
            ];
        }, $locale);
    }

    /**
     * @return array<string, mixed>
     */
    public function download(?string $locale = null): array
    {
        $locale = $this->locale($locale);

        return $this->section('download', function () use ($locale): array {
            return [
                'status' => 'AVAILABLE',
                'locale' => $locale,
                'title' => $this->text()->line('download_title', $locale),
                'meta_title' => $this->text()->line('download_meta_title', $locale),
                'meta_description' => $this->text()->line('download_meta_description', $locale),
                'links' => $this->appLinks->links(),
            ];
        }, $locale);
    }

    /**
     * @return array<string, mixed>
     */
    public function howToPlay(?string $locale = null): array
    {
        $locale = $this->locale($locale);

        return $this->section('how-to-play', function () use ($locale): array {
            $steps = [];
            for ($number = 1; $number <= 5; $number++) {
                $steps[] = [
                    'number' => str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                    'title' => $this->text()->line('how_step_'.$number.'_title', $locale),
                    'text' => $this->text()->line('how_step_'.$number.'_text', $locale),
                ];
            }

            return [
                'status' => 'AVAILABLE',
                'locale' => $locale,
                'title' => $this->text()->line('how_title', $locale),
                'meta_title' => $this->text()->line('how_meta_title', $locale),
                'meta_description' => $this->text()->line('how_meta_description', $locale),
                'disclaimer' => $this->text()->line('how_disclaimer', $locale),
                'steps' => $steps,
            ];
        }, $locale);
    }

    /**
     * @return array<string, mixed>
     */
    public function faq(?string $locale = null): array
    {
        $locale = $this->locale($locale);

        return $this->section('faq', function () use ($locale): array {
            $questions = [];
            for ($number = 1; $number <= 5; $number++) {
                $questions[] = [
                    'id' => 'faq-'.$number,
                    'number' => str_pad((string) $number, 2, '0', STR_PAD_LEFT),
                    'question' => $this->text()->line('faq_q_'.$number, $locale),
                    'answer' => $this->text()->line('faq_a_'.$number, $locale),
                ];
            }

            return [
                'status' => 'AVAILABLE',
                'locale' => $locale,
                'title' => $this->text()->line('faq_title', $locale),
                'meta_title' => $this->text()->line('faq_meta_title', $locale),
                'meta_description' => $this->text()->line('faq_meta_description', $locale),
                'questions' => $questions,
            ];
        }, $locale);
    }

    /**
     * Localized public-page text resolver.
     */
    private function text(): PublicPageTextBag
    {
        return app(PublicPageTextBag::class);
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
            'privacy' => '/privacy',
            'fees' => '/fees',
            'verification' => '/account-verification',
            'grades' => '/account-grades',
            'discounts' => '/discounts',
            'download' => '/download',
            'how-to-play' => '/how-to-play',
            'faq' => '/faq',
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
        $sourceVersion = match ($page) {
            'fees' => (string) config('fees.rule_version', '1').'|'.hash('sha256', json_encode(config('fees.categories', []), JSON_THROW_ON_ERROR)),
            'discounts' => (string) config('discounts.catalogue_version', '1'),
            'grades' => (string) config('account_grades.rule_version', '1'),
            'verification' => (string) config('account_verification.content_version', '1'),
            'download' => (string) config('home.app_links.version', '1'),
            default => '1',
        };
        $key = sprintf(
            'public_pages.%s.%s.legal_%s.content_%s.source_%s',
            $page,
            $locale,
            md5($legalVersion),
            md5($contentVersion),
            md5($sourceVersion),
        );
        $ttl = max(0, (int) config('public_pages.cache_ttl_seconds', 300));

        try {
            if ($ttl === 0) {
                $data = $producer();
                $data['content_version'] = (string) config('public_pages.content_version', '1');
                $data['legal_version'] = (string) config('legal.version', 'v0');

                return $data;
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
