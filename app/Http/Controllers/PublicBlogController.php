<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ArticleCategory;
use App\Models\Article;
use App\Services\Content\ArticleService;
use App\Support\StructuredData;
use App\Support\SupportedLocale;
use App\Support\Text;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public blog (D5).
 *
 * A 2026 audit recorded `blog: 0 · cms: 0 · testimonial: 0` — no way at all to
 * publish long-tail content on a product whose category has very large organic
 * search volume. This controller is the public face of the fix.
 *
 * WHY THE LOCALE IS IN THE PATH AND NOT ONLY A QUERY PARAMETER
 * `/blog/en/thai-lottery-results` and `/blog/th/ตรวจหวย` are different URLs with
 * different content. Putting the locale in the path makes each one a real,
 * crawlable, linkable page. A `?locale=` switch leaves one canonical URL
 * serving three languages, which search engines resolve by picking one — so
 * the Thai and Bengali pages would effectively not exist.
 *
 * WHY A MISSING ARTICLE IS A 404 AND NOT A REDIRECT
 * A draft, a scheduled article and a slug that never existed must be
 * indistinguishable from outside. Returning a redirect for one and a 404 for
 * another turns the error page into an oracle that enumerates unpublished
 * URLs and confirms what is coming.
 */
final class PublicBlogController
{
    public function __construct(private readonly ArticleService $articles) {}

    public function index(Request $request): View|Response
    {
        if (! $this->enabled()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $locale = $this->locale($request);

        $category = null;
        $requested = (string) $request->query('category', '');
        if ($requested !== '') {
            $category = ArticleCategory::tryFrom($requested);
            if ($category === null) {
                // An unknown category is a 404 rather than an empty list: an
                // empty list invites a crawler to keep guessing valid values.
                abort(Response::HTTP_NOT_FOUND);
            }
        }

        $paginator = $this->articles->publicIndex($locale, $category, (int) config('content.per_page', 12));

        $structuredData = [];
        if ((bool) config('content.structured_data', true)) {
            $structuredData = StructuredData::graph([
                StructuredData::organization(),
                StructuredData::website(),
                StructuredData::blogList(
                    $paginator->getCollection()->all(),
                    route('blog.index', ['locale' => $locale], false),
                    __('blog.title'),
                    __('blog.subtitle'),
                ),
                StructuredData::breadcrumbs([
                    ['name' => __('blog.home'), 'url' => '/'],
                    ['name' => __('blog.title'), 'url' => route('blog.index', ['locale' => $locale], false)],
                ]),
            ]);
        }

        return view('blog.index', [
            'locale' => $locale,
            'articles' => $paginator,
            'category' => $category,
            'categories' => ArticleCategory::cases(),
            'notice' => $this->notice($locale),
            'structuredData' => $structuredData,
            'alternates' => $this->alternates('blog.index', ['locale' => $locale]),
        ]);
    }

    public function show(Request $request, string $locale, string $slug): View|Response
    {
        if (! $this->enabled()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $resolvedLocale = $this->locale($request, $locale);

        // A request for /blog/th/<slug> when the app is in English still serves
        // the Thai article: the path is the contract, the session is not.
        if ($resolvedLocale !== $locale) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $article = $this->articles->findPublished($resolvedLocale, $slug);

        if (! $article instanceof Article || ! $article->isPubliclyReachable()) {
            // One 404 for "not found", "not published yet" and "not in this
            // language" alike. See the class docblock.
            abort(Response::HTTP_NOT_FOUND);
        }

        $related = $this->articles->relatedTo($article, 3);

        $structuredData = [];
        if ((bool) config('content.structured_data', true)) {
            $structuredData = StructuredData::graph([
                StructuredData::organization(),
                StructuredData::article([
                    'headline' => $article->effectiveTitle(),
                    'description' => $article->effectiveDescription(),
                    'url' => route('blog.show', ['locale' => $resolvedLocale, 'slug' => $article->slug], false),
                    'date_published' => $article->published_at,
                    'date_modified' => $article->updated_at,
                    'author_name' => $article->byline(),
                    'publisher_name' => (string) config('app.name', 'ThaiLotto'),
                    'language' => $this->languageTag($resolvedLocale),
                    'section' => $article->category?->label(),
                    'word_count' => str_word_count(Text::plainFromHtml($article->body)),
                    'reading_minutes' => $article->estimatedReadingMinutes(),
                ]),
                StructuredData::breadcrumbs([
                    ['name' => __('blog.home'), 'url' => '/'],
                    ['name' => __('blog.title'), 'url' => route('blog.index', ['locale' => $resolvedLocale], false)],
                    ['name' => $article->title, 'url' => route('blog.show', ['locale' => $resolvedLocale, 'slug' => $article->slug], false)],
                ]),
            ]);
        }

        return view('blog.show', [
            'locale' => $resolvedLocale,
            'article' => $article,
            'related' => $related,
            'notice' => $this->notice($resolvedLocale),
            'structuredData' => $structuredData,
            'indexable' => $article->shouldIndex(),
            'translations' => $this->articles->translationsOf($article),
            'showCallToAction' => $article->category?->allowsCallToAction() ?? false,
        ]);
    }

    /**
     * hreflang alternates for the same page in every supported language.
     *
     * Without these, three language versions of the site look like three sites
     * duplicating each other, and a crawler picks one — usually not the one the
     * reader wanted.
     *
     * @param  array<string, mixed>  $parameters
     * @return array<string, string>
     */
    private function alternates(string $route, array $parameters): array
    {
        $out = [];

        foreach (SupportedLocale::ALL as $code) {
            $parameters['locale'] = $code;
            $out[$code] = route($route, $parameters, false);
        }

        return $out;
    }

    private function notice(string $locale): string
    {
        $notices = (array) config('content.responsible_gambling_notice', []);

        // English is the fallback, never an empty string: a missing warning is
        // the one outcome that must not be possible.
        return (string) ($notices[$locale] ?? $notices[SupportedLocale::EN] ?? '');
    }

    private function languageTag(string $locale): string
    {
        return match ($locale) {
            SupportedLocale::TH => 'th-TH',
            SupportedLocale::BN => 'bn-BD',
            default => 'en-US',
        };
    }

    private function locale(Request $request, ?string $fromPath = null): string
    {
        $candidate = $fromPath ?? (string) $request->query('locale', App::getLocale());

        // Unsupported locales fall back to the default rather than 404: a
        // blog with no translation is not a broken blog.
        return SupportedLocale::normalise($candidate) ?? SupportedLocale::EN;
    }

    private function enabled(): bool
    {
        return (bool) config('content.enabled', true);
    }
}
