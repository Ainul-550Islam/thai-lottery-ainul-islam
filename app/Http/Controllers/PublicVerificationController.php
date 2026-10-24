<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public account-verification guide.
 *
 * This controller never accepts or exposes identity documents. The private
 * submission workflow remains owned by the authenticated account controller.
 */
final class PublicVerificationController
{
    public function __construct(private readonly PublicPageDataService $pages)
    {
    }

    public function index(Request $request): View
    {
        $locale = $this->locale($request);
        $data = $this->pages->verification($locale);
        $meta = $this->pages->meta('verification', $locale);

        return view('account-verification.index', [
            'verification' => $data + $meta,
            'meta' => $meta,
        ]);
    }

    public function getGuideApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        return response()->json([
            'success' => true,
            'data' => $this->pages->verification($locale),
            'meta' => $this->pages->meta('verification', $locale),
        ]);
    }

    public function checkStatusApi(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'state' => 'AUTHENTICATION_REQUIRED',
            'message' => 'Verification status is available only inside the authenticated account area.',
        ], 401);
    }

    public function submitKycApi(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'state' => 'AUTHENTICATION_REQUIRED',
            'message' => 'Identity documents must be submitted through the authenticated account verification workflow.',
        ], 401);
    }

    private function locale(Request $request): string
    {
        $candidate = (string) $request->query('locale', app()->getLocale());

        return in_array($candidate, ['en', 'th'], true)
            ? $candidate
            : (string) app()->getLocale();
    }
}
