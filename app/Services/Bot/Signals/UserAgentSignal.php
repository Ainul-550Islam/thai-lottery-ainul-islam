<?php

declare(strict_types=1);

namespace App\Services\Bot\Signals;

use App\Services\Bot\Contracts\BotSignal;
use App\Services\Bot\DTOs\SignalResult;
use Illuminate\Http\Request;

/** Weak evidence only: command-line clients can be legitimate and UAs are spoofable. */
final class UserAgentSignal implements BotSignal
{
    private const AUTOMATION_MARKERS = ['curl/', 'wget/', 'python-requests', 'python-urllib', 'headlesschrome', 'phantomjs', 'selenium', 'playwright'];

    public function score(Request $request): SignalResult
    {
        $ua = trim((string) $request->userAgent());
        if ($ua === '') {
            return new SignalResult('user_agent', 100, ['UA_MISSING']);
        }
        if (strlen($ua) > 2048) {
            return new SignalResult('user_agent', 100, ['UA_OVERSIZED']);
        }
        foreach (self::AUTOMATION_MARKERS as $marker) {
            if (stripos($ua, $marker) !== false) {
                return new SignalResult('user_agent', 100, ['UA_AUTOMATION_MARKER']);
            }
        }

        // A Googlebot/bingbot string does not grant any exemption.
        return new SignalResult('user_agent', 0);
    }
}
