<?php

declare(strict_types=1);

namespace App\Services\Content;

/**
 * Allowlist HTML sanitiser for authored article bodies (D5).
 *
 * WHY NOT DOMDocument
 * The `dom` extension cannot be assumed present in every runtime this
 * application may be deployed to, and a sanitiser that silently does nothing
 * when its parser is missing is worse than no sanitiser at all — the body would
 * be stored raw and rendered raw. This is a self-contained tokenizer: it has no
 * extension dependency beyond `mbstring`, and it fails CLOSED, because
 * anything it does not explicitly recognise is dropped.
 *
 * WHY AN ALLOWLIST AND NOT A BLOCKLIST
 * Every blocklist sanitizer in the wild has been bypassed, because an author
 * only has to find one tag or one attribute the author of the list did not
 * think of. An allowlist inverts the burden: a new attack has to be
 * constructed entirely out of tags we decided were safe, which is a far
 * smaller surface.
 *
 * This is defence in depth, not the primary control. The primary control is
 * that only authenticated staff can author articles at all.
 */
final class HtmlSanitiser
{
    /**
     * Tags that are kept, with their permitted attributes.
     *
     * No `style` attribute anywhere: inline CSS is how clickjacking and
     * overlay-injection are done, and there is no editorial need for it.
     *
     * No `iframe`, `script`, `style`, `object`, `embed`, `form`, `input`:
     * an article has no business embedding executable or interactive content.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        'p' => [],
        'br' => [],
        'hr' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'sub' => [],
        'sup' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'blockquote' => [],
        'pre' => [],
        'code' => [],
        'table' => [],
        'thead' => [],
        'tbody' => [],
        'tr' => [],
        'th' => [],
        'td' => [],
        'figure' => [],
        'figcaption' => [],
        'a' => ['href', 'title'],
    ];

    /**
     * Tags whose CONTENT is discarded along with the tag.
     *
     * Dropping `<script>` but keeping its text would publish the JavaScript as
     * visible body copy — harmless but nonsensical, and it reveals that the
     * payload was accepted. These are removed whole.
     *
     * @var list<string>
     */
    private const DROP_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript'];

    /**
     * URI schemes permitted in `href`.
     *
     * `javascript:` and `data:` are the two that turn a link into code. This is
     * a scheme allowlist rather than a blocklist of known-bad schemes.
     *
     * @var list<string>
     */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto'];

    /** Tags that never carry a closing tag. */
    private const VOID = ['br', 'hr'];

    public static function clean(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') {
            return '';
        }

        // Conditional comments leak markup in ancient IE; strip them first.
        $html = (string) preg_replace('/<!--.*?-->/s', '', $html);

        // Remove whole dangerous elements and their content before tokenizing,
        // so their inner text can never reach the output as body copy.
        foreach (self::DROP_CONTENT as $tag) {
            $html = (string) preg_replace(
                '#<\s*'.$tag.'\b.*?<\s*/\s*'.$tag.'\s*>#is',
                '',
                $html
            );
            // A dangling open tag with no closer: drop the rest of the doc
            // rather than guess where the element ended.
            $html = (string) preg_replace('#<\s*'.$tag.'\b.*$#is', '', $html);
        }

        return self::tokenize($html);
    }

    /**
     * Walk the string, emitting text and only the tags that are allowed.
     */
    private static function tokenize(string $html): string
    {
        $out = '';
        $offset = 0;
        $length = strlen($html);
        $open = [];

        while ($offset < $length) {
            $lt = strpos($html, '<', $offset);

            if ($lt === false) {
                $out .= self::text($html, $offset, $length - $offset);
                break;
            }

            if ($lt > $offset) {
                $out .= self::text($html, $offset, $lt - $offset);
            }

            $gt = strpos($html, '>', $lt);
            if ($gt === false) {
                // Unterminated tag at end of input: treat it as text so it is
                // escaped and visible rather than silently swallowing content.
                $out .= self::text($html, $lt, $length - $lt);
                break;
            }

            $raw = substr($html, $lt + 1, $gt - $lt - 1);
            $offset = $gt + 1;

            $out .= self::tag($raw, $open);
        }

        // Close anything still open, so a truncated document cannot leak its
        // formatting into whatever renders after it.
        while ($open !== []) {
            $out .= '</'.array_pop($open).'>';
        }

        return $out;
    }

    /**
     * @param  list<string>  $open
     */
    private static function tag(string $raw, array &$open): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        $isClosing = str_starts_with($raw, '/');
        $isSelfClosing = str_ends_with(rtrim($raw), '/');

        $body = trim($isClosing ? substr($raw, 1) : $raw);
        $body = rtrim($body, '/');

        // Tag name up to the first whitespace or slash.
        if (! preg_match('/^([a-zA-Z][a-zA-Z0-9]*)/', $body, $m)) {
            // Not a recognisable tag (e.g. "<3 things"): emit as escaped text.
            return '&lt;'.htmlspecialchars($raw, ENT_QUOTES, 'UTF-8').'&gt;';
        }

        $name = strtolower($m[1]);

        if (! isset(self::ALLOWED[$name])) {
            // Disallowed tag: drop the tag, keep the content. The content has
            // already been through dangling-script removal above.
            return '';
        }

        if ($isClosing) {
            // Ignore a closer for something we never opened: it could only
            // unbalance the output.
            if (! in_array($name, $open, true)) {
                return '';
            }

            // Close anything opened inside it first, so a document like
            // "<p><strong></p>" cannot leave <strong> dangling open.
            $out = '';
            while ($open !== []) {
                $closing = array_pop($open);
                $out .= '</'.$closing.'>';
                if ($closing === $name) {
                    break;
                }
            }

            return $out;
        }

        if (in_array($name, self::VOID, true) || $isSelfClosing) {
            return '<'.$name.' />';
        }

        $open[] = $name;

        return '<'.$name.self::attributes($name, substr($body, strlen($m[1]))).'>';
    }

    private static function attributes(string $tag, string $rest): string
    {
        $allowed = self::ALLOWED[$tag];
        if ($allowed === [] || trim($rest) === '') {
            return '';
        }

        $out = '';

        // Match name="value", name='value', name=value and bare name.
        if (preg_match_all(
            '/([a-zA-Z][a-zA-Z0-9_:.-]*)\s*(?:=\s*("[^"]*"|\'[^\']*\'|[^\s"\'>]*))?/',
            $rest,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $attr = strtolower($m[1]);

                // Anything event-like or script-like is refused regardless of
                // whether it made it into the per-tag allowlist.
                if (str_starts_with($attr, 'on') || in_array($attr, ['style', 'srcdoc', 'formaction'], true)) {
                    continue;
                }

                if (! in_array($attr, $allowed, true)) {
                    continue;
                }

                $value = trim($m[2] ?? '', "\"'");

                if ($attr === 'href') {
                    $value = self::safeUrl($value);
                    if ($value === null) {
                        continue;
                    }
                    // External authorship means no control over the target;
                    // noopener closes the tabnabbing hole, and nofollow keeps a
                    // link in editorial copy from passing ranking signal.
                    $out .= ' href="'.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'"';
                    $out .= ' rel="noopener nofollow"';

                    continue;
                }

                if ($value === '') {
                    continue;
                }

                $out .= ' '.$attr.'="'.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').'"';
            }
        }

        return $out;
    }

    /**
     * Return the URL only when its scheme is acceptable.
     */
    private static function safeUrl(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Strip control characters and whitespace, which is how
        // "java\tscript:alert(1)" defeats a naive scheme check.
        $value = (string) preg_replace('/[\x00-\x20\x7F]/u', '', $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('#^([a-zA-Z][a-zA-Z0-9+.-]*):#', $value, $m)) {
            if (! in_array(strtolower($m[1]), self::ALLOWED_SCHEMES, true)) {
                return null;
            }

            return $value;
        }

        // Protocol-relative "//evil.example" inherits the current scheme on
        // some parsers; force it to https so it cannot be used for a downgrade.
        if (str_starts_with($value, '//')) {
            return 'https:'.$value;
        }

        // Relative link: fine, and the common case for internal references.
        return $value;
    }

    private static function text(string $html, int $offset, int $length): string
    {
        return htmlspecialchars(substr($html, $offset, $length), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
