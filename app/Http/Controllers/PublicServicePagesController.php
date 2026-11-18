<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\Finance\FeeCalculationResult;
use App\Http\Requests\Api\V1\FeePreviewRequest;
use App\Services\PublicPages\FeesPageService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Public Fees page + safe public fee surfaces (fees parity batch).
 *
 * Anonymous GET /fees only exposes enabled public_visible rows from
 * config/fees.php. No user-specific fees, no internal margins, no
 * competitor values.
 *
 * THREE SURFACES, ONE AUTHORITY. The HTML page, the JSON schedule and
 * the fee-preview calculator all project through FeesPageService, so
 * the three can never disagree about a fee.
 *
 * THE PREVIEW NEVER TRUSTS THE CLIENT. The POST accepts ONLY a
 * category key, an optional provider key and a base amount; any
 * client-supplied fee amount is ignored before the service is ever
 * reached, and the returned figure is exclusively the server's own
 * bcmath result. Anonymous by design — it discloses nothing beyond the
 * already-public schedule.
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
        $groups = $this->fees->publicFeeGroups($locale);

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
                'status' => $groups === [] ? 'NOT_CONFIGURED' : 'AVAILABLE',
                'locale' => $locale,
                'title' => (string) trans('account_services.fees_title', [], $locale),
                'lead' => (string) trans('account_services.fees_lead', [], $locale),
                'groups' => $groups,
                'rule_version' => (string) config('fees.rule_version', '1'),
                'currency' => (string) config('fees.currency', 'THB'),
                'footer_note' => (string) trans('account_services.fees_footer_note', [], $locale),
                'not_configured' => FeesPageService::NOT_CONFIGURED,
            ],
            'preview' => $this->previewOptions($locale),
        ]);
    }

    /**
     * JSON fee schedule for API consumers (same public rows and the
     * same grouped sections as GET /fees).
     */
    public function feesApi(Request $request): JsonResponse
    {
        unset($request);

        $locale = (string) app()->getLocale();

        return response()->json([
            'success' => true,
            'data' => [
                'currency' => (string) config('fees.currency', 'THB'),
                'rule_version' => (string) config('fees.rule_version', '1'),
                'categories' => $this->fees->publicFees($locale),
                'groups' => $this->fees->publicFeeGroups($locale),
            ],
        ]);
    }

    /**
     * POST /api/v1/fees/preview — the server-authoritative calculation
     * of ONE public fee against a base amount.
     *
     * SECURITY: only category / provider / base_amount are read from
     * the request. Any client-supplied fee figure is ignored wholesale;
     * the response carries exclusively the server's bcmath result.
     */
    public function feesPreview(FeePreviewRequest $request): JsonResponse
    {
        /*
         * The category and provider whitelists and the decimal-only shape of
         * base_amount are enforced by FeePreviewRequest, which answers 422 with
         * this endpoint's own {success, error, message} body. What is left here is
         * the calculation and its two failure modes, both of which are decisions
         * about the fee schedule rather than about the shape of the request.
         */
        try {
            $result = $this->fees->calculateResult(
                $request->feeCategory(),
                $request->baseAmount(),
                $request->feeProvider(),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'error' => 'INVALID_REQUEST',
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result->toArray(),
        ]);
    }

    /**
     * The preview form's <option> lists: the public categories and the
     * whitelisted providers, both stable keys with translated labels.
     *
     * @return array{categories: list<array{value: string, label: string}>, providers: list<array{value: string, label: string}>}
     */
    private function previewOptions(string $locale): array
    {
        $labels = [];

        foreach ($this->fees->publicFees($locale) as $row) {
            $labels[(string) $row['key']] = (string) ($row['label'] ?? $row['key']);
        }

        $categories = [];

        foreach ($this->fees->previewCategories() as $key) {
            $categories[] = [
                'value' => $key,
                'label' => $labels[$key] ?? ucfirst(str_replace('_', ' ', $key)),
            ];
        }

        $providers = [];

        foreach ($this->fees->providers() as $provider) {
            $providers[] = [
                'value' => $provider,
                'label' => $this->fees->providerLabel($provider, $locale),
            ];
        }

        return [
            'categories' => $categories,
            'providers' => $providers,
        ];
    }
}
