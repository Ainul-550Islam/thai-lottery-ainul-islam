<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Wallet\WalletConversionServiceContract;
use App\DTOs\Wallet\ConversionAuditContextData;
use App\DTOs\Wallet\ConversionHistoryFilterData;
use App\Enums\WalletConversionChannel;
use App\Exceptions\Wallet\ConversionException;
use App\Http\Requests\Wallet\ConversionExecuteRequest;
use App\Http\Requests\Wallet\ConversionQuoteRequest;
use App\Http\Requests\Wallet\ConversionReversalRequest;
use App\Http\Resources\Wallet\ConversionQuoteResource;
use App\Http\Resources\Wallet\WalletConversionCollection;
use App\Http\Resources\Wallet\WalletConversionResource;
use App\Http\Responses\ApiResponse;
use App\Models\WalletConversion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The member API for the Cash <-> Win conversion lane.
 *
 * WHY THE SAME ENGINE AS THE WEB FORM
 * The API is a transport, not a behaviour. Everything that decides whether
 * money moves — policy, quote arithmetic, idempotency, ledger postings — lives
 * in the engine behind one contract, so a mobile client and a browser cannot
 * end up with two different prices or two different meanings of "exactly once".
 *
 * THE MONEY-MOVING CALLS CARRY AN IDEMPOTENCY KEY
 * `Idempotency-Key` (header) or `idempotency_key` (body); the middleware
 * normalises whichever arrives and refuses the request before a controller runs
 * when neither did. A retry with the same key and the same request returns the
 * FIRST conversion with `replayed: true` and HTTP 200; the first execution
 * answers 201.
 */
final class WalletConversionController
{
    public function __construct(
        private readonly WalletConversionServiceContract $conversions,
    ) {}

    /**
     * GET /api/v1/wallet/conversions/summary — the member's own totals.
     *
     * B1 FIX. WalletConversionPageController::index() has always called
     * `summaryForUser()`, and the method existed in no interface, in no
     * implementation and in no repository — so the member-facing conversion
     * page died with a BadMethodCallException the moment anything rendered it.
     * The API route existed for the same method and pointed at a controller
     * class that was never written.
     *
     * The method is now declared on the service (as a refusal, while the
     * orchestrator is a stub) and this endpoint is its transport. It is scoped
     * to the authenticated member by construction: the user id comes from the
     * token, never from the query string, so there is no id to tamper with.
     */
    public function summary(Request $request): JsonResponse
    {
        $summary = $this->conversions->summaryForUser((int) $request->user()->getKey());

        return response()->json([
            'data' => [
                'summary' => $summary,
            ],
        ]);
    }

    /**
     * GET /api/v1/wallet/conversions — a page of the member's own history.
     *
     * Scoping is enforced by the filter itself: ConversionHistoryFilterData is
     * built with the authenticated member's id and the repository clauses on
     * it, so there is no unscoped read to leak and no ownership check to forget
     * later. Per-page is clamped inside fromArray() against
     * conversions.history.max_per_page, so a caller cannot ask for a million
     * rows in one page.
     */
    public function index(Request $request): JsonResponse
    {
        $history = $this->conversions->history(
            ConversionHistoryFilterData::fromArray(
                $request->query(),
                (int) $request->user()->getKey(),
            ),
        );

        return response()->json([
            'data' => (new WalletConversionCollection($history))->resolve($request),
        ]);
    }

    /**
     * POST /api/v1/wallet/conversions/quote — price a conversion, move nothing.
     */
    public function quote(ConversionQuoteRequest $request): JsonResponse
    {
        try {
            $quote = $this->conversions->quote(
                $request->toEngineRequest((int) $request->user()->getKey(), WalletConversionChannel::Api),
            );
        } catch (ConversionException $exception) {
            return $this->refusal($exception);
        }

        return response()->json([
            'data' => [
                'quote' => (new ConversionQuoteResource($quote))->resolve($request),
            ],
        ], 201);
    }

    /**
     * POST /api/v1/wallet/conversions — execute against a quoted price.
     */
    public function store(ConversionExecuteRequest $request): JsonResponse
    {
        try {
            $result = $this->conversions->convert(
                $request->toEngineRequest((int) $request->user()->getKey(), WalletConversionChannel::Api),
            );
        } catch (ConversionException $exception) {
            return $this->refusal($exception);
        }

        $conversion = WalletConversion::query()->findOrFail($result->conversionId);

        return response()->json([
            'data' => [
                'conversion' => array_merge(
                    (new WalletConversionResource($conversion))->resolve($request),
                    ['replayed' => $result->replayed],
                ),
            ],
        ], $result->replayed ? 200 : 201);
    }

    /**
     * GET /api/v1/wallet/conversions/{reference} — one conversion, its owner only.
     */
    public function show(Request $request, string $reference): JsonResponse
    {
        $conversion = $this->conversions->findForUser($reference, (int) $request->user()->getKey());

        if (! $conversion instanceof WalletConversion) {
            // 404, not 403: whether a reference exists is itself information.
            return ApiResponse::error(
                code: 'not_found',
                message: 'That conversion could not be found.',
                status: 404,
            );
        }

        return response()->json([
            'data' => [
                'conversion' => (new WalletConversionResource($conversion))->resolve($request),
            ],
        ]);
    }

    /**
     * POST /api/v1/wallet/conversions/{reference}/reverse — operator only.
     *
     * The FormRequest asks the wallet policy (`reverse` on the row) before this
     * method runs, so a member is refused with 403 and no money moves.
     */
    public function reverse(ConversionReversalRequest $request, string $reference): JsonResponse
    {
        $conversion = WalletConversion::query()->where('reference', '=', $reference)->first();

        if (! $conversion instanceof WalletConversion) {
            return ApiResponse::error(
                code: 'not_found',
                message: 'That conversion could not be found.',
                status: 404,
            );
        }

        $context = ConversionAuditContextData::fromRequest($request, WalletConversionChannel::Api);

        try {
            $result = $this->conversions->reverse(
                (int) $conversion->getKey(),
                $context,
                (string) $request->validated('reason'),
                (string) $request->input('idempotency_key', ''),
            );
        } catch (ConversionException $exception) {
            return $this->refusal($exception);
        }

        $reversal = WalletConversion::query()->findOrFail($result->conversionId);

        return response()->json([
            'data' => [
                'reversal' => (new WalletConversionResource($reversal))->resolve($request),
            ],
        ]);
    }

    /**
     * A refusal keeps the engine's own code and message; only the status is the
     * transport's business. A reused key is a conflict (409), a closed lane is
     * temporarily unavailable (503), and everything else is a refusal to act on
     * this request (422).
     */
    private function refusal(ConversionException $exception): JsonResponse
    {
        $code = (string) $exception->errorCode();

        $status = match ($code) {
            'idempotency_conflict' => 409,
            'feature_disabled', 'direction_disabled' => 503,
            default => 422,
        };

        return ApiResponse::error(
            code: $code,
            message: $exception->getMessage(),
            status: $status,
        );
    }
}
