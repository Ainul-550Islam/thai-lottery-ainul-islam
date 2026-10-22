<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ArticleCategory;
use App\Enums\ArticleStatus;
use App\Support\Text;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A content-marketing article (D5).
 *
 * @property int $id
 * @property string $translation_group
 * @property string $locale
 * @property string $slug
 * @property string $title
 * @property string|null $excerpt
 * @property string $body
 * @property ArticleCategory $category
 * @property ArticleStatus $status
 * @property string|null $author_name
 * @property int $reading_minutes
 * @property bool $featured
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property Carbon|null $published_at
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Article extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'translation_group',
        'locale',
        'slug',
        'title',
        'excerpt',
        'body',
        'category',
        'status',
        'author_name',
        'reading_minutes',
        'featured',
        'meta_title',
        'meta_description',
        'published_at',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ArticleCategory::class,
            'status' => ArticleStatus::class,
            'featured' => 'boolean',
            'reading_minutes' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', ArticleStatus::Published->value)
            // An article scheduled for later is not live yet, even though its
            // status says published. Both conditions are required: the status
            // is the editorial decision, the timestamp is the timing of it.
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForLocale(Builder $query, string $locale): Builder
    {
        return $query->where('locale', $locale);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInCategory(Builder $query, ArticleCategory $category): Builder
    {
        return $query->where('category', $category->value);
    }

    // ---------------------------------------------------------------- relations

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The other language versions of this same article.
     *
     * @return Collection<int, self>
     */
    public function siblingTranslations(): Collection
    {
        return static::query()
            ->where('translation_group', $this->translation_group)
            ->whereKeyNot($this->getKey())
            ->get();
    }

    // ---------------------------------------------------------------- accessors

    public function isPubliclyReachable(): bool
    {
        if (! $this->status instanceof ArticleStatus || ! $this->status->isPublic()) {
            return false;
        }

        return $this->published_at !== null && $this->published_at->lte(now());
    }

    /**
     * Whether search engines should be offered this page.
     *
     * Combines the editorial state with the category rule: a per-draw result
     * article is reachable but not evergreen, and indexing thousands of pages
     * that expire by definition is how a domain loses trust rather than gains
     * traffic.
     */
    public function shouldIndex(): bool
    {
        if (! $this->isPubliclyReachable()) {
            return false;
        }

        return $this->category instanceof ArticleCategory
            ? $this->category->shouldIndex()
            : false;
    }

    public function effectiveTitle(): string
    {
        $meta = trim((string) $this->meta_title);

        return $meta !== '' ? $meta : trim((string) $this->title);
    }

    /**
     * The description to publish, falling back to the excerpt and then to a
     * trimmed plain-text rendering of the body.
     */
    public function effectiveDescription(): string
    {
        foreach ([(string) $this->meta_description, (string) $this->excerpt] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return Text::truncate($candidate, 320);
            }
        }

        return Text::truncate(Text::plainFromHtml((string) $this->body), 320);
    }

    public function byline(): string
    {
        $name = trim((string) $this->author_name);

        return $name !== '' ? $name : 'ThaiLotto Editorial';
    }

    /**
     * Estimate reading time from the body when it has not been set explicitly.
     */
    public function estimatedReadingMinutes(): int
    {
        $set = (int) $this->reading_minutes;
        if ($set > 0) {
            return $set;
        }

        return max(1, (int) ceil(str_word_count(Text::plainFromHtml((string) $this->body)) / 200));
    }

    public function url(): string
    {
        return route('blog.show', ['locale' => $this->locale, 'slug' => $this->slug], false);
    }

    public function absoluteUrl(): string
    {
        return route('blog.show', ['locale' => $this->locale, 'slug' => $this->slug]);
    }
}
