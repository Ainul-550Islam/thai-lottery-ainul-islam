<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of article this is (D5).
 *
 * The categories are a closed list rather than a free-text tag, because each
 * one carries a different editorial obligation. A `Result` article goes stale
 * by definition and must not be indexed the way a `Guide` should be. A
 * `ResponsibleGaming` article is not marketing at all and must never sit
 * behind a call to action.
 */
enum ArticleCategory: string
{
    /** How something works: evergreen, the backbone of long-tail search. */
    case Guide = 'guide';

    /** Time-sensitive product or draw news. */
    case News = 'news';

    /**
     * Coverage of a specific draw or result.
     *
     * Deliberately noindex by default: a page about one draw is worthless
     * after that draw, and thousands of thin, expired pages are exactly what
     * search engines penalise a site for. See `shouldIndex()`.
     */
    case Result = 'result';

    /**
     * Strategy, tips, "how to pick numbers".
     *
     * The category that most needs a review step. Most number-picking advice
     * is either mathematically meaningless or, worse, implies a system where
     * there is none.
     */
    case Tips = 'tips';

    /** Player-protection content. Never carries a call to action. */
    case ResponsibleGaming = 'responsible_gaming';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Guide => 'Guide',
            self::News => 'News',
            self::Result => 'Result',
            self::Tips => 'Tips',
            self::ResponsibleGaming => 'Responsible gaming',
        };
    }

    /**
     * Whether search engines should index articles in this category.
     *
     * Result coverage says no: it expires by definition, and a site that
     * accumulates thousands of stale per-draw pages is building a liability,
     * not an asset. Those pages are still reachable and still useful to a
     * reader who arrives with a link — they are simply not offered as
     * evergreen content.
     */
    public function shouldIndex(): bool
    {
        return $this !== self::Result;
    }

    /**
     * Whether this article may show a call to action.
     *
     * Player-protection content must never be doing two jobs. An article
     * titled "how to set a spending limit" that ends with "deposit now" is not
     * player protection, and a regulator will read it that way.
     */
    public function allowsCallToAction(): bool
    {
        return $this !== self::ResponsibleGaming;
    }
}
