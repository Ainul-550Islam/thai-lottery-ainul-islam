<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PublicPages\FeesPageService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Public Fees page + safe public fee information.
 *
 * Anonymous GET /fees only exposes enabled public_visible rows from
 * config/fees.php. No user-specific fees, no internal margins, no
 * competitor values.
 */
final class PublicServicePagesController
{
    public function __construct(
        private readonly FeesPageService $fees,
    ) {
    }

    public function fees(Request $request): View
    {
        unset($request);
        $locale = (string) app()->getLocale();
        $rows = $this->fees->publicFees($locale);

        $meta = [
            'title' => (string) trans('account_services.fees_meta_title', [], $locale),
            'description' => (string) trans('account_services.fees_meta_description', [], $locale),
            'canonical' => rtrim((string) config('app.url'), '/').'/fees',
            'lang' => str_replace('_', '-', $locale),
            'og_title' => (string) trans('account_services.fees_meta_title', [], $locale),
            'og_description' => (string) trans('account_services.fees_meta_description', [], $locale),
            'og_type' => 'website',
            'og_url' => rtrim((string) config('app.url'), '/').'/fees',
        ];

        return view('fees.index', [
            'meta' => $meta,
            'fees' => [
                'status' => 'AVAILABLE',
                'locale' => $locale,
                'title' => (string) trans('account_services.fees_title', [], $locale),
                'lead' => (string) trans('account_services.fees_lead', [], $locale),
                'rows' => $rows,
                'rule_version' => (string) config('fees.rule_version', '1'),
                'currency' => (string) config('fees.currency', 'THB'),
                'footer_note' => (string) trans('account_services.fees_footer_note', [], $locale),
                'not_configured' => FeesPageService::NOT_CONFIGURED,
            ],
        ]);
    }

    /**
     * JSON fee schedule for API consumers (same public rows as GET /fees).
     */
    public function feesApi(Request $request): \Illuminate\Http\JsonResponse
    {
        $locale = (string) app()->getLocale();

        return response()->json([
            'success' => true,
            'data' => [
                'currency' => (string) config('fees.currency', 'THB'),
                'rule_version' => (string) config('fees.rule_version', '1'),
                'categories' => $this->fees->publicFees($locale),
            ],
        ]);
    }
}
