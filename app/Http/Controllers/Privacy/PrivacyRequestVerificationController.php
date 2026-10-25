<?php

declare(strict_types=1);

namespace App\Http\Controllers\Privacy;

use App\Http\Controllers\Controller;
use App\Services\Privacy\PrivacyRequestService;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * GET /privacy/request/verify/{token} — the link in the verification mail.
 *
 * =========================================================================
 * THIS PAGE IS REACHABLE BY ANYONE WHO HOLDS THE LINK
 * =========================================================================
 * So it renders ONE of four outcomes and, at most, the reference the token
 * names. It never says whether an account exists, never shows an address, never
 * lists anything the request contains, and never accepts input. The token itself
 * is not echoed back into the page.
 *
 * CACHE HEADERS ARE NOT OPTIONAL. The page is served with no-store and
 * no-referrer so a shared device or a proxy cannot keep a verified-references
 * page around, and so the token in the URL cannot leak to a third party through
 * a Referer header.
 *
 * The route carries the `signed` middleware: a link whose signature has been
 * tampered with never reaches this method, and a link whose signature has aged
 * out is refused by the framework's 403 rather than being treated as an expired
 * token. Both are correct: an unsigned URL is not evidence of anything.
 */
final class PrivacyRequestVerificationController extends Controller
{
    public function __construct(
        private readonly PrivacyRequestService $requests,
    ) {}

    public function __invoke(Request $request, string $token): Response
    {
        $result = $this->requests->verify($token);

        Log::info('privacy_request_verified_visit', [
            'outcome' => $result['state'],
        ]);

        return response()
            ->view('privacy.request-verified', [
                'outcome' => $result['state'],
                'reference' => $result['reference'],
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
