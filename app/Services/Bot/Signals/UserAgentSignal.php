<?php

declare(strict_types=1);

namespace App\Services\Bot\Signals;

use App\Services\Bot\Contracts\BotSignal;
use Illuminate\Http\Request;

/**
 * Class UserAgentSignal
 *
 * Evaluates User-Agent string anomalies (headless drivers, automated scrapers, empty headers).
 * Weak indicator alone; never blocks purely on missing UA for approved API clients.
 */
class UserAgentSignal implements BotSignal
{
    private const KNOWN_SCRAPERS = [
        'curl/', 'wget/', 'python-requests', 'scrapy', 'postmanruntime',
        'headlesschrome', 'phantomjs', 'selenium', 'puppeteer', 'playwright',
        'aiohttp', 'okhttp', 'go-http-client', 'libwww-perl',
    ];

    public function getKey(): string
    {
        return 'user_agent';
    }

    public function getWeight(): int
    {
        return 20;
    }

    public function evaluate(Request $request, array $context = []): array
    {
        $userAgent = strtolower((string) $request->header('User-Agent', ''));

        if ($userAgent === '') {
            return [
                'score' => 0.70,
                'reason_code' => 'EMPTY_USER_AGENT',
                'metadata' => ['ua' => ''],
            ];
        }

        foreach (self::KNOWN_SCRAPERS as $scraperPattern) {
            if (str_contains($userAgent, $scraperPattern)) {
                return [
                    'score' => 0.85,
                    'reason_code' => 'AUTOMATED_CLIENT_SIGNATURE',
                    'metadata' => ['pattern' => $scraperPattern, 'ua' => substr($userAgent, 0, 100)],
                ];
            }
        }

        return [
            'score' => 0.00,
            'reason_code' => null,
            'metadata' => [],
        ];
    }
}
