<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Structured data (JSON-LD) builders — D2.
 *
 * WHY A PHP CLASS AND NOT INLINE BLADE ARRAYS
 * ------------------------------------------
 * Every schema here is emitted into a <script> element. That makes it one of
 * the few places in a Blade view where a string from the database or a
 * translation file becomes executable-adjacent output, so it needs one place
 * that decides how text is cleaned and one place that decides when a schema is
 * too broken to publish at all. Doing this inline per view would mean twelve
 * slightly different ideas of "safe".
 *
 * THE CONTRACT
 * ------------
 * Every public method returns either a complete, valid schema node or an EMPTY
 * ARRAY. It never returns a half-built node. A caller renders an empty array as
 * nothing at all, which Google treats as "no structured data on this page" —
 * strictly better than publishing invalid markup, which can earn a manual
 * action against the whole site.
 *
 * ON GOOGLE'S 2023 DEPRECATIONS, AND WHY WE STILL EMIT THEM
 * ---------------------------------------------------------
 * - FAQ rich results are now restricted to authoritative government and health
 *   sites. HowTo rich results were withdrawn outright. The sitelinks searchbox
 *   was retired in November 2023.
 * - So none of these will earn a *Google rich result* for this site in 2026.
 *
 * We emit them anyway, because Google is no longer the only consumer:
 *   - Bing still expands FAQ and HowTo.
 *   - Crawlers that feed large-language-model answer engines read JSON-LD
 *     preferentially, because it is the unambiguous statement of what a page
 *     is. Being absent from it means being paraphrased badly.
 *   - BreadcrumbList and Organization are fully supported everywhere and are
 *     the two that carry real, current rich-result value here.
 *
 * The cost is a few hundred bytes per page. The benefit is being machine-
 * readable to every consumer, not just the one that deprecated the format.
 */
final class StructuredData
{
    /**
     * Organization node. The stable identity of the publisher.
     *
     * Returned from every page: it is what lets a crawler collapse
     * "ThaiLotto", "thailotto" and the site's URLs into one entity.
     */
    public static function organization(): array
    {
        $name = self::text(config('app.name', 'Thai Lottery'));
        $url = self::absolute('/');

        if ($name === '' || $url === '') {
            return [];
        }

        $node = [
            '@type' => 'Organization',
            '@id' => $url.'#organization',
            'name' => $name,
            'url' => $url,
        ];

        $logo = self::absolute('/images/og-default.png');
        if ($logo !== '') {
            $node['logo'] = [
                '@type' => 'ImageObject',
                'url' => $logo,
                'width' => 1200,
                'height' => 630,
            ];
        }

        return $node;
    }

    /**
     * WebSite node, carrying the search action.
     *
     * The SearchAction points at the ONE public result search that is not
     * disallowed in robots.txt. The per-lane search endpoints
     * (/national-lottery/search and siblings) are deliberately NOT used: they
     * are Disallowed, and pointing a crawler at a URL we have just told it not
     * to fetch is self-contradictory.
     */
    public static function website(): array
    {
        $url = self::absolute('/');
        $name = self::text(config('app.name', 'Thai Lottery'));

        if ($url === '' || $name === '') {
            return [];
        }

        $search = self::absolute('/results/search');

        $node = [
            '@type' => 'WebSite',
            '@id' => $url.'#website',
            'name' => $name,
            'url' => $url,
            'publisher' => ['@id' => $url.'#organization'],
            'inLanguage' => self::languageTag(),
        ];

        if ($search !== '') {
            $node['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $search.'?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return $node;
    }

    /**
     * BreadcrumbList from an ordered trail.
     *
     * @param  list<array{name: string, url: string}>  $trail
     *
     * Google requires the trail to be the REAL path a visitor walked, not a
     * keyword-stuffed one, and requires every position to be filled in
     * sequence. Entries with a blank name or URL are dropped rather than
     * emitted as empty nodes, and the list is renumbered afterwards so the
     * `position` values stay contiguous — a gap invalidates the whole block.
     */
    public static function breadcrumbs(array $trail): array
    {
        $items = [];
        $position = 0;

        foreach ($trail as $crumb) {
            if (! is_array($crumb)) {
                continue;
            }

            $name = self::text((string) ($crumb['name'] ?? ''));
            $url = self::absoluteUrl((string) ($crumb['url'] ?? ''));

            if ($name === '' || $url === '') {
                continue;
            }

            $position++;
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $name,
                'item' => $url,
            ];
        }

        // A one-entry breadcrumb is not a trail, and Google ignores it.
        if (count($items) < 2) {
            return [];
        }

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * FAQPage from the public-page service's question list.
     *
     * Only question/answer pairs that BOTH resolve to real text are published.
     * A question with no answer, or an answer with no question, is invalid
     * FAQPage markup and is dropped rather than padded with a placeholder.
     *
     * @param  iterable<mixed>  $questions
     */
    public static function faqPage(iterable $questions): array
    {
        $entries = [];

        foreach ($questions as $question) {
            if (! is_array($question)) {
                continue;
            }

            $q = self::text((string) ($question['question'] ?? ''));
            $a = self::text((string) ($question['answer'] ?? ''));

            if ($q === '' || $a === '') {
                continue;
            }

            $entries[] = [
                '@type' => 'Question',
                'name' => $q,
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $a,
                ],
            ];
        }

        if ($entries === []) {
            return [];
        }

        return [
            '@type' => 'FAQPage',
            'mainEntity' => $entries,
        ];
    }

    /**
     * HowTo from the public-page service's step list.
     *
     * @param  iterable<mixed>  $steps
     */
    public static function howTo(iterable $steps, string $name, string $description = ''): array
    {
        $name = self::text($name);
        if ($name === '') {
            return [];
        }

        $entries = [];

        foreach ($steps as $index => $step) {
            if (! is_array($step)) {
                continue;
            }

            $title = self::text((string) ($step['title'] ?? ''));
            $body = self::text((string) ($step['text'] ?? ''));

            if ($title === '' && $body === '') {
                continue;
            }

            // HowToStep requires a `name` or a `text`; either alone is valid,
            // so a step that only has one still ships.
            $node = [
                '@type' => 'HowToStep',
                'position' => $index + 1,
            ];

            if ($title !== '') {
                $node['name'] = $title;
            }
            if ($body !== '') {
                $node['text'] = $body;
            }

            $entries[] = $node;
        }

        if ($entries === []) {
            return [];
        }

        $node = [
            '@type' => 'HowTo',
            'name' => $name,
            'step' => $entries,
        ];

        $description = self::text($description);
        if ($description !== '') {
            $node['description'] = $description;
        }

        return $node;
    }

    /**
     * One published draw, as an Event.
     *
     * There is no schema.org "Lottery" type. The two honest options are
     * `Event` (a dated occurrence at a place) and `Lottery` as a
     * `Game`/`Product`. We use `Event` because a draw genuinely has a date and
     * a venue, and because `Event` is a type Google actively supports — a
     * `Product`-shaped lottery node would imply a purchasable good and invite
     * merchant-policy scrutiny on a gambling-adjacent page.
     *
     * The projection is the SAME array the page renders from, so the structured
     * data can never disagree with what a visitor sees. Critically: an
     * unpublished or number-less draw returns []. Publishing a draw event with
     * no numbers would be stating that a result exists when it does not.
     *
     * @param  array<string, mixed>  $projection
     */
    public static function drawEvent(array $projection, string $laneName, string $url): array
    {
        if (($projection['available'] ?? false) !== true
            || ($projection['has_numbers'] ?? false) !== true) {
            return [];
        }

        $draw = $projection['draw'] ?? null;
        if (! is_array($draw)) {
            return [];
        }

        $date = is_array($draw['date'] ?? null) ? (string) ($draw['date']['iso'] ?? '') : '';
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return [];
        }

        $reference = self::text((string) ($draw['reference'] ?? ''));
        if ($reference === '') {
            return [];
        }

        $absoluteUrl = self::absoluteUrl($url);
        if ($absoluteUrl === '') {
            return [];
        }

        $laneName = self::text($laneName);

        $node = [
            '@type' => 'Event',
            'name' => $laneName !== '' ? $laneName.' draw '.$reference : 'Lottery draw '.$reference,
            'url' => $absoluteUrl,
            'startDate' => $date,
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'identifier' => $reference,
        ];

        // A draw with numbers already published has happened; say so.
        $publishedAt = (string) ($draw['published_at'] ?? '');
        if ($publishedAt !== '') {
            $node['eventStatus'] = 'https://schema.org/EventCompleted';
        }

        $timezone = self::text((string) ($draw['timezone'] ?? ''));
        if ($timezone !== '') {
            // Event wants the date as an ISO-8601 string; without a zone a
            // crawler assumes UTC and can place the draw a day off.
            $node['startDate'] = $date.'T00:00:00'.self::offsetForTimezone($timezone);
        }

        return $node;
    }

    /**
     * Wrap one or more nodes in a @graph envelope.
     *
     * A single JSON-LD block describing the whole page is easier for a crawler
     * than several competing blocks, and @graph lets the nodes cross-reference
     * each other by @id (WebSite -> Organization).
     *
     * Empty nodes are filtered out before wrapping, so a page whose every
     * schema failed to build emits nothing rather than an empty graph.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    /**
     * A single content-marketing article, as `BlogPosting` (D5).
     *
     * WHY BlogPosting AND NOT Article
     * `BlogPosting` is a subtype of `Article` and is the more specific truth
     * about the page: it is a post on a blog. Search engines reward the most
     * specific type that is accurate, and a gambling site's content pages
     * being precisely described matters more here than elsewhere.
     *
     * WHY THE WHOLE NODE IS DROPPED WHEN ANY REQUIRED PART IS MISSING
     * `headline`, `url`, `datePublished` and `author` are required. Emitting a
     * BlogPosting without them is not a partial win — it is an invalid node,
     * and an invalid node is worse than no node, because it trains a crawler
     * that this site publishes malformed data.
     *
     * The body is NOT included. Schema.org has no "article body" property worth
     * shipping the whole HTML in: `articleBody` is plain text, so sending it
     * would mean publishing a second full copy of every article in the page
     * head, doubling page weight for a field no consumer needs. `wordCount` and
     * `timeRequired` carry the useful signal instead.
     *
     * @param  array<string, mixed>  $article
     * @return array<string, mixed>
     */
    public static function article(array $article): array
    {
        $headline = self::text($article['headline'] ?? '');
        $url = self::absoluteUrl(self::text($article['url'] ?? ''));

        if ($headline === '' || $url === '') {
            return [];
        }

        // Google truncates `headline` at roughly 110 characters, and a headline
        // cut mid-word by the consumer looks worse than one cut here.
        $headline = mb_substr($headline, 0, 110);

        $published = self::isoDate($article['date_published'] ?? null);
        if ($published === null) {
            return [];
        }

        $author = self::text($article['author_name'] ?? '');
        if ($author === '') {
            return [];
        }

        $node = [
            '@type' => 'BlogPosting',
            '@id' => $url.'#article',
            'headline' => $headline,
            'url' => $url,
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $url,
            ],
            'datePublished' => $published,
            'author' => [
                '@type' => 'Organization',
                'name' => $author,
            ],
            'inLanguage' => self::text($article['language'] ?? '') ?: self::languageTag(),
        ];

        // dateModified is optional but valuable: it tells a crawler the page is
        // maintained. It is only ever sent when genuinely later than publication.
        $modified = self::isoDate($article['date_modified'] ?? null);
        if ($modified !== null && $modified >= $published) {
            $node['dateModified'] = $modified;
        }

        $description = self::text($article['description'] ?? '');
        if ($description !== '') {
            $node['description'] = mb_substr($description, 0, 320);
        }

        $section = self::text($article['section'] ?? '');
        if ($section !== '') {
            $node['articleSection'] = $section;
        }

        $words = (int) ($article['word_count'] ?? 0);
        if ($words > 0) {
            $node['wordCount'] = $words;
        }

        // ISO 8601 duration. Reading time is the one number a reader actually
        // wants from structured data, and it costs three bytes.
        $minutes = (int) ($article['reading_minutes'] ?? 0);
        if ($minutes > 0) {
            $node['timeRequired'] = 'PT'.$minutes.'M';
        }

        $image = self::absoluteUrl(self::text($article['image_url'] ?? ''));
        if ($image !== '') {
            $node['image'] = $image;
        }

        $publisher = self::text($article['publisher_name'] ?? '');
        if ($publisher !== '') {
            $node['publisher'] = self::organization();
        }

        $keywords = self::text($article['keywords'] ?? '');
        if ($keywords !== '') {
            $node['keywords'] = $keywords;
        }

        return $node;
    }

    /**
     * The blog index, as a `Blog` with its posts listed (D5).
     *
     * The posts are listed as URL references rather than inlined BlogPosting
     * nodes: an index page of twelve articles should describe twelve articles,
     * not embed twelve complete article graphs in the head.
     *
     * @param  iterable<mixed>  $articles  each: array<string, mixed>
     * @return array<string, mixed>
     */
    public static function blogList(iterable $articles, string $url, string $name = '', string $description = ''): array
    {
        $url = self::absoluteUrl(self::text($url));

        if ($url === '') {
            return [];
        }

        $items = [];
        $position = 0;

        foreach ($articles as $article) {
            if (! is_array($article)) {
                continue;
            }

            $postUrl = self::absoluteUrl(self::text($article['url'] ?? ''));
            $title = self::text($article['title'] ?? '');

            if ($postUrl === '' || $title === '') {
                continue;
            }

            $position++;
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'url' => $postUrl,
                'name' => mb_substr($title, 0, 110),
            ];
        }

        // An empty blog is not a broken blog, but publishing an ItemList with
        // zero items asserts the list is empty when it may simply not have been
        // built. Emit the Blog node only, and let the index page speak for it.
        $node = [
            '@type' => 'Blog',
            '@id' => $url.'#blog',
            'url' => $url,
        ];

        $name = self::text($name);
        if ($name !== '') {
            $node['name'] = $name;
        }

        $node['description'] = self::text($description);

        if ($items !== []) {
            $node['blogPost'] = array_map(static fn (array $item): array => [
                '@type' => 'BlogPosting',
                'url' => $item['url'],
                'headline' => $item['name'],
            ], $items);
        }

        return $node;
    }

    /**
     * Normalise a date to ISO 8601, or null when it cannot be trusted.
     *
     * Returns null rather than guessing: a wrong publication date in structured
     * data is a factual claim the site is making about itself, and "unknown" is
     * the only safe answer when the value is absent or malformed.
     */
    private static function isoDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable(trim($value)))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function graph(array $nodes): array
    {
        $nodes = array_values(array_filter($nodes));

        if ($nodes === []) {
            return [];
        }

        return [
            '@context' => 'https://schema.org',
            '@graph' => $nodes,
        ];
    }

    /**
     * Flatten a value to schema-safe plain text.
     *
     * Translation strings and CMS text may legitimately contain markup, but
     * schema.org `text` is plain text: an embedded <b> would be published
     * literally, and an embedded <script> would be far worse. Tags are stripped
     * and whitespace collapsed so a multi-line answer becomes one clean string.
     */
    private static function text(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return '';
        }
        if (is_array($value) || is_object($value)) {
            return '';
        }

        $value = (string) $value;
        $value = strip_tags($value);
        // Decode entities produced by the view layer (&amp; -> &), then
        // collapse runs of whitespace, including the newlines strip_tags leaves.
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = trim($value);

        // Reject the 4-byte-invalid strings that PHP will happily produce but
        // json_encode will refuse, which would emit "false" instead of a node.
        return mb_check_encoding($value, 'UTF-8') ? $value : '';
    }

    /**
     * Absolute URL for a site-relative path such as '/results'.
     */
    private static function absolute(string $path): string
    {
        return self::absoluteUrl($path);
    }

    /**
     * Absolute URL, refusing anything that is not a usable http(s) URL or a
     * root-relative path. Returns '' when it cannot be made absolute, so the
     * caller drops the node instead of publishing a broken link.
     */
    private static function absoluteUrl(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Already absolute: only http(s) is acceptable. javascript: and data:
        // must never reach structured data.
        if (preg_match('#^https?://#i', $value) === 1) {
            return filter_var($value, FILTER_VALIDATE_URL) === false ? '' : $value;
        }

        if (str_starts_with($value, '/')) {
            return rtrim((string) url('/'), '/').$value;
        }

        return '';
    }

    /**
     * BCP-47-ish language tag for inLanguage.
     */
    private static function languageTag(): string
    {
        return match (app()->getLocale()) {
            'th' => 'th-TH',
            'bn' => 'bn-BD',
            default => 'en-US',
        };
    }

    /**
     * UTC offset suffix for a PHP timezone identifier, e.g. '+07:00'.
     *
     * A draw's stored timezone is a label ("Asia/Bangkok"), but Event.startDate
     * wants a numeric offset. An unknown zone is not an error — the date is
     * still correct — so we fall back to the app's own offset rather than
     * dropping the node.
     */
    private static function offsetForTimezone(string $timezone): string
    {
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            return date('P');
        }

        $offset = $tz->getOffset(new \DateTimeImmutable('now', $tz));
        $sign = $offset < 0 ? '-' : '+';
        $minutes = abs(intdiv($offset, 60));

        return sprintf('%s%02d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
    }
}
