<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Contracts\Wallet\WalletConversionServiceContract;
use App\DTOs\Wallet\ConversionAuditContextData;
use App\Enums\WalletConversionChannel;
use App\Exceptions\Wallet\ConversionException;
use App\Http\Requests\Wallet\ConversionExecuteRequest;
use App\Http\Requests\Wallet\ConversionQuoteRequest;
use App\Http\Requests\Wallet\ConversionReversalRequest;
use App\Http\Resources\Wallet\ConversionQuoteResource;
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
final class WalletConversionApiController
{
    public function __construct(
        private readonly WalletConversionServiceContract $conversions,
    ) {
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
            // Authorisation refusals are 403: the request was well formed and the money
            // is in a reversible state, so re-sending it cannot help.
            'reversal_forbidden', 'conversion_reversal_forbidden' => 403,
            'rate_limited' => 429,
            'idempotency_conflict' => 409,
            'feature_disabled', 'direction_disabled', 'currency_not_configured' => 503,
            default => 422,
        };

        return ApiResponse::error(
            code: $code,
            message: $exception->getMessage(),
            status: $status,
        );
    }
}
