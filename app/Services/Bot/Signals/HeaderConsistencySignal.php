<?php

declare(strict_types=1);

namespace App\Services\Bot\Signals;

use App\Services\Bot\Contracts\BotSignal;
use App\Services\Bot\DTOs\SignalResult;
use Illuminate\Http\Request;

/** Header absence is weak evidence, not proof of automation. No header grants trust. */
final class HeaderConsistencySignal implements BotSignal
{
    public function score(Request $request): SignalResult
    {
        $strength = 0;
        $reasons = [];
        $accept = strtolower(trim((string) $request->header('Accept', '')));
        $language = trim((string) $request->header('Accept-Language', ''));
        $mode = strtolower(trim((string) $request->header('Sec-Fetch-Mode', '')));
        $site = strtolower(trim((string) $request->header('Sec-Fetch-Site', '')));
        $dest = strtolower(trim((string) $request->header('Sec-Fetch-Dest', '')));

        if ($accept === '') {
            $strength += 30;
            $reasons[] = 'HEADER_ACCEPT_MISSING';
        }
        if ($language === '') {
            $strength += 20;
            $reasons[] = 'HEADER_LANGUAGE_MISSING';
        }

        // Set by trusted route middleware only, never by submitted form/JSON data.
        if ($request->attributes->get('bot.browser_navigation') === true && ($mode === '' || $site === '' || $dest === '')) {
            $strength += 20;
            $reasons[] = 'HEADER_FETCH_METADATA_MISSING';
        }
        if ($mode !== '' && ! in_array($mode, ['cors', 'navigate', 'no-cors', 'same-origin', 'websocket'], true)) {
            $strength += 40;
            $reasons[] = 'HEADER_FETCH_MODE_INVALID';
        }
        if ($site !== '' && ! in_array($site, ['same-origin', 'same-site', 'cross-site', 'none'], true)) {
            $strength += 40;
            $reasons[] = 'HEADER_FETCH_SITE_INVALID';
        }
        if ($dest === 'document' && $mode !== '' && $mode !== 'navigate') {
            $strength += 40;
            $reasons[] = 'HEADER_FETCH_MODE_DEST_MISMATCH';
        }
        if ($mode === 'navigate' && $dest === 'document' && $accept !== '' && ! str_contains($accept, 'text/html') && ! str_contains($accept, 'application/xhtml+xml') && ! str_contains($accept, '*/*')) {
            $strength += 30;
            $reasons[] = 'HEADER_NAVIGATION_ACCEPT_MISMATCH';
        }

        return new SignalResult('header_consistency', min(100, $strength), $reasons);
    }
}
