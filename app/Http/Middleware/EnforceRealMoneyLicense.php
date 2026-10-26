<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\License\LicenseService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Middleware: block real-money routes when the licence gate is not satisfied.
 *
 * Fail-closed: if the licence service throws, the request receives 403 with
 * a structured JSON error (API) or a redirect with flash error (browser).
 */
final class EnforceRealMoneyLicense
{
    public function __construct(
        private readonly LicenseService $license,
    ) {}

    public function handle(Request $request, Closure $next): Response|JsonResponse
    {
        try {
            $this->license->assertLicensed();
        } catch (\App\Exceptions\RealMoneyNotLicensedException $e) {
            return $this->refused($request, $e);
        }

        return $next($request);
    }

    private function refused(Request $request, \App\Exceptions\RealMoneyNotLicensedException $e): Response|JsonResponse
    {
        $payload = [
            'status'  => 'REAL_MONEY_NOT_LICENSED',
            'code'    => $e->errorCode(),
            'message' => $e->getMessage(),
        ];

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json($payload, 403);
        }

        // Browser request: flash and return to the page they were on.
        return back()
            ->with('error', __('real_money.not_licensed'))
            ->with('error_code', $e->errorCode())
            ->withInput();
    }
}
