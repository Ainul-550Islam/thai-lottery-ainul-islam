<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Lang;

/**
 * Public "Our Services" landing page (/services).
 *
 * WHY THIS CLASS EXISTS
 * -----------------------------------------------------------------------------
 * `routes/web.php` declared `GET /services` against
 * `PublicPagesController::services()`, and that method does not exist — a
 * refactor dropped it while the route and the view (resources/views/services/
 * index.blade.php) stayed behind. The route therefore resolved to a method the
 * controller never had, so the page answered 500 while the route was still
 * listed as if it were healthy.
 *
 * The page is a frontend surface with no business logic, so the repair is a
 * page controller that owns exactly one view. It does NOT reach into the
 * wallet, ledger, transfer or payment layer, and it does not swallow anything:
 * if the view or its translations are broken the exception surfaces exactly as
 * a missing-method fatal did.
 *
 * WHY THE PAGE COMPOSES ITS OWN META BLOCK
 * -----------------------------------------------------------------------------
 * The shared PublicPageDataService::meta() derives the canonical URL from a
 * hard-coded per-page route map that has no `services` entry, so asking it for
 * this page would have published a canonical of the site root — an SEO defect
 * dressed up as a success. The title and description come from the page's own
 * lang/<locale>/services.php catalogue, which is the same file the body copy is
 * read from, so the head and the body can never disagree.
 *
 * An invokable controller, because there is one action: render the page.
 */
final class PublicServicesPageController extends Controller
{
    /**
     * The canonical path of this page. Path only; the host comes from
     * config('app.url') through url() so a deployment behind a different host
     * publishes the host it is actually served from.
     */
    private const PATH = '/services';

    public function __invoke(): View
    {
        $locale = (string) app()->getLocale();

        return view('services.index', [
            'meta' => $this->meta($locale),
        ]);
    }

    /**
     * SEO head block for the services page.
     *
     * Every value is a translated string or a URL built from configuration —
     * nothing is taken from the request, so the page cannot be used to inject
     * markup into its own head.
     *
     * @return array<string, string>
     */
    private function meta(string $locale): array
    {
        $canonical = url(self::PATH);
        $title = $this->line('meta_title', $locale);
        $description = $this->line('meta_description', $locale);

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            // A public informational page: indexable, unlike the player area.
            'robots' => 'index,follow',
            'og_title' => $title,
            'og_description' => $description,
            'og_type' => 'website',
            'og_url' => $canonical,
        ];
    }

    /**
     * One translated line, or an empty string when the locale has no sentence
     * for it.
     *
     * Returning the raw key here would put `services.meta_title` in the browser
     * tab, which is worse than an empty title: a missing translation must read
     * as missing, not as a debug artefact shipped to players.
     */
    private function line(string $key, string $locale): string
    {
        $full = 'services.'.$key;

        if (! Lang::has($full, $locale)) {
            return '';
        }

        $line = trans($full, [], $locale);

        return is_string($line) ? $line : '';
    }
}
