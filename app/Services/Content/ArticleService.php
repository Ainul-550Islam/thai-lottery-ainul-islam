<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Models\Article;
use App\Support\SupportedLocale;
use App\Support\Text;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Content-marketing article service (D5).
 *
 * A 2026 audit found `blog: 0 · cms: 0 · testimonial: 0` on a product whose
 * category has very large organic search volume. This service is the engine
 * behind the blog the audit said did not exist.
 *
 * THE ONE RULE THAT SHAPES EVERY METHOD
 * A draft cannot go straight to the public site. `transition()` refuses
 * Draft -> Published, because article copy on a real-money gambling site is
 * regulated marketing: the things that attract search traffic — implying a
 * system, promising certainty, showing wins — are the same things that attract
 * a regulator. Someone other than the author has to look at it first, and that
 * is enforced here rather than trusted to the admin form.
 *
 * BODIES ARE SANITISED ON THE WAY IN, NEVER ON THE WAY OUT
 * `HtmlSanitiser::clean()` runs on write. The view renders `$article->body` as
 * HTML because it is already known clean. Sanitising at render time instead
 * would mean every cache miss pays for it, and any code path that forgot would
 * render raw HTML — so the safe thing is to make the stored value safe.
 */
final class ArticleService
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, ?int $actorId = null): Article
    {
        return DB::transaction(function () use ($input, $actorId): Article {
            $locale = $this->locale($input['locale'] ?? null);
            $title = trim((string) ($input['title'] ?? ''));

            if ($title === '') {
                throw new RuntimeException('An article needs a title.');
            }

            $article = new Article;
            $article->fill([
                'translation_group' => $this->translationGroup($input['translation_group'] ?? null),
                'locale' => $locale,
                'title' => $title,
                'slug' => $this->uniqueSlug($title, $locale),
                'excerpt' => $this->nullableString($input['excerpt'] ?? null),
                'body' => HtmlSanitiser::clean($input['body'] ?? ''),
                'category' => $this->category($input['category'] ?? null),
                'status' => ArticleStatus::Draft->value,
                'author_name' => $this->nullableString($input['author_name'] ?? null),
                'reading_minutes' => $this->readingMinutes($input),
                'featured' => (bool) ($input['featured'] ?? false),
                'meta_title' => $this->nullableString($input['meta_title'] ?? null),
                'meta_description' => $this->nullableString($input['meta_description'] ?? null),
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $article->save();

            return $article;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(Article $article, array $input, ?int $actorId = null): Article
    {
        if (! $article->status instanceof ArticleStatus || ! $article->status->isEditable()) {
            throw new RuntimeException('An archived article must be returned to draft before it is edited.');
        }

        return DB::transaction(function () use ($article, $input, $actorId): Article {
            if (array_key_exists('title', $input)) {
                $title = trim((string) $input['title']);
                if ($title === '') {
                    throw new RuntimeException('An article needs a title.');
                }
                $article->title = $title;

                // Re-slug only when the slug was never manually overridden —
                // otherwise editing a title silently breaks every published
                // link to the article.
                if (! $this->slugWasCustomised($article)) {
                    $article->slug = $this->uniqueSlug($title, (string) $article->locale, (int) $article->getKey());
                }
            }

            if (array_key_exists('body', $input)) {
                $article->body = HtmlSanitiser::clean($input['body']);
                $article->reading_minutes = Text::readingMinutes($article->body);
            }

            foreach (['excerpt', 'author_name', 'meta_title', 'meta_description'] as $field) {
                if (array_key_exists($field, $input)) {
                    $article->{$field} = $this->nullableString($input[$field]);
                }
            }

            if (array_key_exists('category', $input)) {
                $article->category = $this->category($input['category']);
            }

            if (array_key_exists('featured', $input)) {
                $article->featured = (bool) $input['featured'];
            }

            $article->updated_by = $actorId;
            $article->save();

            return $article;
        });
    }

    /**
     * Move an article between publication states.
     */
    public function transition(Article $article, ArticleStatus $target, ?int $actorId = null): Article
    {
        $current = $article->status;

        if (! $current instanceof ArticleStatus) {
            throw new RuntimeException('The article has no recognisable status.');
        }

        if (! $current->canTransitionTo($target)) {
            throw new RuntimeException(sprintf(
                'An article cannot move from %s to %s.',
                $current->label(),
                $target->label(),
            ));
        }

        $article->status = $target->value;

        if ($target === ArticleStatus::Published && $article->published_at === null) {
            $article->published_at = now();
        }

        // Returning to draft does NOT clear published_at: republishing should
        // restore the article to its original date rather than pretend it is
        // new, and a reader who bookmarked it should not see the date move.
        $article->updated_by = $actorId;
        $article->save();

        return $article;
    }

    // ---------------------------------------------------------------- reading

    /**
     * The public index: live articles in one locale, newest first.
     *
     * @return LengthAwarePaginator<int, Article>
     */
    public function publicIndex(string $locale, ?ArticleCategory $category = null, int $perPage = 12): LengthAwarePaginator
    {
        $query = Article::query()
            ->published()
            ->forLocale($this->locale($locale))
            ->when($category !== null, fn ($q) => $q->inCategory($category))
            ->orderByDesc('published_at');

        return $query->paginate(max(1, min($perPage, 48)))
            ->withQueryString();
    }

    /**
     * The single public article, or null when it is not publicly reachable.
     *
     * Returns null rather than throwing so the controller can answer 404 for
     * a draft, a scheduled article and a non-existent slug alike: the three
     * must be indistinguishable, or the 404 page becomes a way to enumerate
     * unpublished URLs.
     */
    public function findPublished(string $locale, string $slug): ?Article
    {
        /** @var Article|null $article */
        $article = Article::query()
            ->published()
            ->forLocale($this->locale($locale))
            ->where('slug', $slug)
            ->first();

        return $article;
    }

    /**
     * Other articles a reader of this one might want.
     *
     * Same category first, then anything else in the locale. Same-language
     * only: recommending a Thai article to an English reader is not a
     * recommendation.
     *
     * @return Collection<int, Article>
     */
    public function relatedTo(Article $article, int $limit = 3): Collection
    {
        $limit = max(0, min($limit, 12));

        if ($limit === 0) {
            return new Collection;
        }

        $query = Article::query()
            ->published()
            ->forLocale((string) $article->locale)
            ->whereKeyNot($article->getKey());

        if ($article->category instanceof ArticleCategory) {
            $query->orderByRaw(
                'CASE WHEN category = ? THEN 0 ELSE 1 END',
                [$article->category->value]
            );
        }

        return $query
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Locales this article has been translated into, for hreflang.
     *
     * @return array<string, string> locale => absolute url
     */
    public function translationsOf(Article $article): array
    {
        $out = [];

        foreach ($article->siblingTranslations() as $sibling) {
            if ($sibling->isPubliclyReachable()) {
                $out[(string) $sibling->locale] = $sibling->absoluteUrl();
            }
        }

        if ($article->isPubliclyReachable()) {
            $out[(string) $article->locale] = $article->absoluteUrl();
        }

        ksort($out);

        return $out;
    }

    // ---------------------------------------------------------------- helpers

    public function uniqueSlug(string $title, string $locale, ?int $ignoreId = null): string
    {
        $base = Text::slugify($title);

        if ($base === '') {
            // A title of only symbols or only emoji has no pronounceable slug.
            $base = 'article';
        }

        $base = mb_substr($base, 0, 180);
        $slug = $base;
        $n = 2;

        while ($this->slugTaken($slug, $locale, $ignoreId)) {
            $slug = $base.'-'.$n;
            $n++;
            if ($n > 500) {
                // Pathological only; keeps the loop provably bounded.
                $slug = $base.'-'.bin2hex(random_bytes(4));
                break;
            }
        }

        return $slug;
    }

    private function slugTaken(string $slug, string $locale, ?int $ignoreId): bool
    {
        return Article::query()
            ->where('locale', $locale)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * Whether the current slug still matches what the title would generate.
     *
     * Used to avoid clobbering a deliberately hand-written slug when the title
     * is edited.
     */
    private function slugWasCustomised(Article $article): bool
    {
        $generated = Text::slugify((string) $article->title);

        return $generated !== '' && ! str_starts_with((string) $article->slug, $generated);
    }

    private function locale(mixed $candidate): string
    {
        // An unsupported locale falls back to the default rather than 404-ing
        // the whole blog: content is optional, and a missing translation is not
        // a reason to take the page down.
        return SupportedLocale::normalise($candidate) ?? SupportedLocale::EN;
    }

    private function category(mixed $candidate): string
    {
        if ($candidate instanceof ArticleCategory) {
            return $candidate->value;
        }

        return (ArticleCategory::tryFrom((string) $candidate) ?? ArticleCategory::Guide)->value;
    }

    private function translationGroup(mixed $candidate): string
    {
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }

        return (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function readingMinutes(array $input): int
    {
        $explicit = (int) ($input['reading_minutes'] ?? 0);

        return $explicit > 0 ? $explicit : Text::readingMinutes(HtmlSanitiser::clean($input['body'] ?? ''));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
