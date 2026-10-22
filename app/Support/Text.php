<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Plain-text helpers shared by content and SEO surfaces (D5).
 *
 * WHY THIS EXISTS AS ITS OWN CLASS
 * Flattening HTML to plain text and truncating it sounds trivial, and the
 * trivial version is wrong in three separate ways that all show up in public
 * metadata:
 *
 *   1. `strip_tags()` on `<p>one</p><p>two</p>` yields "onetwo" — two words
 *      fused into one, which is what a search result snippet would show.
 *   2. Truncating on `strlen()` counts bytes, so a Thai or Bengali string is
 *      cut mid-character and the snippet ends in a replacement glyph.
 *   3. Cutting at an arbitrary byte leaves a dangling word ("how to claim
 *      your pri…") that reads as a rendering fault.
 *
 * One implementation, used by the article model, the meta tags and the
 * structured data, so a reader sees the same sentence in all three places.
 */
final class Text
{
    private function __construct() {}

    /**
     * Flatten HTML to readable plain text.
     *
     * Block-level tags become whitespace rather than vanishing, so adjacent
     * paragraphs do not fuse. Entities are decoded after stripping, not
     * before, so `&lt;b&gt;` in the source does not become a real tag.
     */
    public static function plainFromHtml(?string $html): string
    {
        $value = (string) $html;
        if ($value === '') {
            return '';
        }

        // Turn closing block tags into a space BEFORE stripping, so that
        // "<p>a</p><p>b</p>" separates rather than becoming "ab".
        $value = (string) preg_replace('/<\/(p|div|br|li|h[1-6]|tr|blockquote|section|article)>/i', ' ', $value);
        $value = (string) preg_replace('/<(br|\/p|\/div|\/li|\/h[1-6])\s*\/?>/i', ' ', $value);

        $value = strip_tags($value);

        // Decode entities last: decoding first would let an escaped tag
        // survive strip_tags and re-enter the output as markup.
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse every run of whitespace, including the non-breaking spaces
        // that rich-text editors emit in place of ordinary ones.
        $value = (string) preg_replace('/[\p{Z}\s]+/u', ' ', $value);

        return trim($value);
    }

    /**
     * Truncate to a maximum number of CHARACTERS (not bytes), on a word
     * boundary, with an ellipsis only when something was actually removed.
     *
     * Character-based because the site publishes Thai and Bengali: a byte
     * limit at 320 would cut a Thai sentence at roughly a third of its length
     * and frequently mid-character.
     */
    public static function truncate(?string $value, int $limit, string $ellipsis = '…'): string
    {
        $value = trim((string) $value);

        if ($limit <= 0) {
            return '';
        }

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        // Leave room for the ellipsis so the result never exceeds the limit.
        $cut = mb_substr($value, 0, max(0, $limit - mb_strlen($ellipsis)));

        // Prefer the last space in the window so a word is not cut in half.
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > (int) ($limit * 0.6)) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " \t\n\r\0\x0B,.;:").$ellipsis;
    }

    /**
     * Derive a URL-safe slug from a title.
     *
     * Transliteration of non-Latin scripts is deliberately NOT attempted:
     * turning ตรวจหวย into "trawchwy" produces a slug no Thai reader would
     * guess and no Thai search engine benefits from. Thai and Bengali titles
     * keep their own characters, which are valid in a percent-encoded path,
     * and the caller falls back to a stable suffix when the result is empty.
     */
    public static function slugify(?string $value): string
    {
        $value = self::plainFromHtml($value);
        $value = mb_strtolower($value);

        // Strip characters that are meaningless in a path.
        //
        // \p{M} MUST be kept alongside \p{L}: Thai and Bengali combining marks
        // are categorised as marks, not letters, so a pattern allowing only
        // letters silently ate them — ตรวจหวย ออนไลน์ became ตรวจหวย-ออนไลน,
        // dropping the ์ . Keeping the marks keeps the word intact.
        $value = (string) preg_replace('/[^\p{L}\p{M}\p{N}\s-]+/u', '', $value);
        $value = (string) preg_replace('/[\s_]+/u', '-', $value);
        $value = (string) preg_replace('/-{2,}/', '-', $value);

        return trim($value, '-');
    }

    /**
     * Reading time in whole minutes, from plain or HTML text.
     */
    public static function readingMinutes(?string $body, int $wordsPerMinute = 200): int
    {
        $words = str_word_count(self::plainFromHtml($body));

        return $words === 0 ? 1 : max(1, (int) ceil($words / max(1, $wordsPerMinute)));
    }
}
