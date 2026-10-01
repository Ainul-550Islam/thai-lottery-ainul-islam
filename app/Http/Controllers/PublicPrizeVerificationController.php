<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Lottery\GloPrizeCatalogue;
use App\Services\Lottery\PrizeVerificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Public prize-verification controller.
 *
 * All result answers come from PrizeVerificationService. This controller does
 * not fabricate a draw, prize, authenticity result, or physical-ticket claim.
 */
final class PublicPrizeVerificationController
{
    public function __construct(
        private readonly PrizeVerificationService $verification,
        private readonly GloPrizeCatalogue $catalogue,
    ) {
    }

    public function index(Request $request): View
    {
        unset($request);
        $locale = (string) app()->getLocale();
        $title = (string) trans('prize_discount.verify_meta_title', [], $locale);
        $description = (string) trans('prize_discount.verify_meta_description', [], $locale);

        return view('prize-verification.index', [
            'meta' => [
                'title' => $title,
                'description' => $description,
                'canonical' => url('/prize-verification'),
                'og_title' => $title,
                'og_description' => $description,
                'og_type' => 'website',
                'og_url' => url('/prize-verification'),
            ],
            'prizes' => $this->catalogue->prizes(),
            'claim_rules' => $this->catalogue->claimRules(),
        ]);
    }

    public function verifyApi(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'mode' => ['required', 'string', 'in:number,reference,barcode'],
            'value' => ['required', 'string', 'min:1', 'max:512'],
            'product' => ['nullable', 'string', 'max:30'],
            'draw_ref' => ['nullable', 'string', 'max:80'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Provide a valid verification mode and value.',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $this->verification->verify(
                kind: (string) $request->input('mode'),
                value: (string) $request->input('value'),
                product: is_string($request->input('product')) ? $request->input('product') : null,
                drawRef: is_string($request->input('draw_ref')) ? $request->input('draw_ref') : null,
                correlationId: (string) $request->header('X-Correlation-Id', ''),
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'state' => 'UNAVAILABLE',
                'message' => 'Verification is temporarily unavailable.',
            ], 503);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function getPrizeStructureApi(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'prizes' => $this->catalogue->prizes(),
                'claim' => $this->catalogue->claimRules(),
                'ticket' => $this->catalogue->ticketRules(),
                'draw_calendar' => $this->catalogue->drawCalendar(),
            ],
            'notice' => 'Reference catalogue only. This platform is not the Government Lottery Office and cannot certify a physical ticket.',
        ]);
    }
}
