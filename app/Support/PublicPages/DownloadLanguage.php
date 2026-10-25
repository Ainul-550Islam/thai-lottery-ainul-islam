<?php

declare(strict_types=1);

namespace App\Support\PublicPages;

use Illuminate\Http\Request;

/**
 * Which language a *downloaded file* is in.
 *
 * THIS IS NOT LOCALE NEGOTIATION. What a page renders is decided once, by the
 * SetLocale middleware, from ?lang=, the session, the cookie and Accept-Language.
 * A PDF has none of those: it is produced from a stored revision, has no session,
 * and is re-opened later by somebody who may not be the person who clicked. So a
 * download names its language explicitly, and the name travels in the URL that
 * the page itself generates.
 *
 * TWO RULES:
 *  1. An explicitly requested language wins - but only if it is one this build
 *     can actually render. An unsupported value is refused (null), never quietly
 *     rendered as English, because a person who asked for Thai and got English
 *     has been given the wrong legal text without being told.
 *  2. With nothing requested, the negotiated locale is used when it is supported,
 *     otherwise the configured fallback.
 *
 * Living here rather than inside each controller matters: three public
 * controllers serve downloads from the same export service, and a fourth will.
 * The rule belongs to the file, not to whoever routes to it.
 */
final class DownloadLanguage
{
    /**
     * @param  list<string>  $supported
     */
    public function resolve(Request $request, array $supported, string $fallback = 'en'): ?string
    {
        if ($supported === []) {
            return null;
        }

        $requested = strtolower(trim((string) $request->query->get('locale', '')));

        if ($requested !== '') {
            return in_array($requested, $supported, true) ? $requested : null;
        }

        $negotiated = strtolower((string) app()->getLocale());

        if (in_array($negotiated, $supported, true)) {
            return $negotiated;
        }

        return in_array($fallback, $supported, true) ? $fallback : $supported[0];
    }
}
