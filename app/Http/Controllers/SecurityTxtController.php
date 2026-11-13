<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

final class SecurityTxtController extends Controller
{
    public function __invoke(): Response
    {
        $contact = trim((string) config('security.security_txt.contact', ''));

        // RFC 9116 makes Contact mandatory. A guessed or placeholder address would send real
        // vulnerability reports into a void, so with no valid configured contact nothing is
        // published (404). Only mailto:, https: and tel: URIs without whitespace are accepted,
        // which also rules out header/line injection through the config value.
        if (preg_match('#^(?:mailto:[^\s@]+@[^\s@]+|https://[^\s]+|tel:\+?[0-9\-]+)$#Di', $contact) !== 1) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $canonical = rtrim((string) config('app.url', 'https://localhost'), '/').'/.well-known/security.txt';
        $expires = Carbon::now('UTC')->addDays(180)->format('Y-m-d\TH:i:s\Z');

        $lines = [
            'Contact: '.$contact,
            'Expires: '.$expires,
            'Preferred-Languages: en, th',
            'Canonical: '.$canonical,
        ];

        $policyUrl = config('security.security_txt.policy');
        if (is_string($policyUrl) && preg_match('#^https://[^\s]+$#Di', trim($policyUrl)) === 1) {
            $lines[] = 'Policy: '.trim($policyUrl);
        }

        return new Response(
            implode("\n", $lines)."\n",
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'public, max-age=3600',
            ],
        );
    }
}
