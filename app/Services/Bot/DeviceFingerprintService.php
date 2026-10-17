<?php

declare(strict_types=1);

namespace App\Services\Bot;

use Illuminate\Http\Request;

/**
 * Class DeviceFingerprintService
 *
 * Generates privacy-minimized, server-keyed HMAC device correlation tokens.
 * Never logs or exposes raw biometric or invasive hardware identifiers.
 */
class DeviceFingerprintService
{
    /**
     * Compute a salted, privacy-preserving device correlation hash.
     */
    public function generateToken(Request $request): string
    {
        $hmacKey = (string) config('bot.fingerprint_hmac_key', config('app.key'));

        $components = [
            $request->header('User-Agent', ''),
            $request->header('Accept-Language', ''),
            $request->header('Sec-CH-UA', ''),
            $request->header('Sec-CH-UA-Platform', ''),
        ];

        return hash_hmac('sha256', implode('|', $components), $hmacKey);
    }
}
