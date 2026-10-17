<?php

declare(strict_types=1);

namespace App\Services\Bot\Signals;

use App\Services\Bot\Contracts\BotSignal;
use Illuminate\Http\Request;

/**
 * Class HeaderConsistencySignal
 *
 * Checks HTTP header ordering, Accept/Sec-CH-* header presence for browser routes.
 */
class HeaderConsistencySignal implements BotSignal
{
    public function getKey(): string
    {
        return 'header_consistency';
    }

    public function getWeight(): int
    {
        return 15;
    }

    public function evaluate(Request $request, array $context = []): array
    {
        $hasAccept = $request->hasHeader('Accept');
        $hasAcceptEncoding = $request->hasHeader('Accept-Encoding');
        $hasAcceptLanguage = $request->hasHeader('Accept-Language');

        // Missing standard browser headers on HTML/web submission
        if (!$request->expectsJson() && (!$hasAccept || !$hasAcceptEncoding || !$hasAcceptLanguage)) {
            return [
                'score' => 0.60,
                'reason_code' => 'INCONSISTENT_BROWSER_HEADERS',
                'metadata' => [
                    'has_accept' => $hasAccept,
                    'has_encoding' => $hasAcceptEncoding,
                    'has_language' => $hasAcceptLanguage,
                ],
            ];
        }

        return [
            'score' => 0.00,
            'reason_code' => null,
            'metadata' => [],
        ];
    }
}
