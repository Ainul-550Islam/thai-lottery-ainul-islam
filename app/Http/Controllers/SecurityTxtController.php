<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

final class SecurityTxtController extends Controller
{
    public function __invoke(): Response
    {
        $contact = (string) config('security.security_txt.contact', 'mailto:security@thailottery.example');
        $canonical = rtrim((string) config('app.url', 'https://localhost'), '/').'/.well-known/security.txt';
        $expires = Carbon::now('UTC')->addDays(180)->format('Y-m-d\TH:i:s\Z');

        $lines = [
            'Contact: '.$contact,
            'Expires: '.$expires,
            'Preferred-Languages: en, th',
            'Canonical: '.$canonical,
        ];

        $policyUrl = config('security.security_txt.policy');
        if (is_string($policyUrl) && trim($policyUrl) !== '') {
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
