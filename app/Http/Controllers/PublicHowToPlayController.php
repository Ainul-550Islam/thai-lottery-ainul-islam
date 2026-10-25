<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Public\PayoutIllustrationRequest;
use App\Services\Lottery\PayoutIllustrationService;
use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

/**
 * Public educational how-to-play page.
 *
 * The page describes configured platform steps without inventing payout
 * multipliers, draw dates, payment guarantees, or betting simulations.
 *
 * The one arithmetic surface is a PAYOUT ILLUSTRATION: stake × the published
 * rate for a bet type, or NOT_CONFIGURED for that bet type where no single
 * published rate exists. It draws no number, predicts nothing, states no
 * probability and promises no winnings. While
 * PAYOUT_ILLUSTRATION_ENABLED is false the endpoint answers the exact body it
 * always answered (HTTP 501 + state NOT_CONFIGURED).
 */
final class PublicHowToPlayController
{
    public function __construct(
        private readonly PublicPageDataService $pages,
        private readonly PayoutIllustrationService $illustrations,
    ) {
    }

    public function index(Request $request): View
    {
        $locale = $this->locale($request);
        $data = $this->pages->howToPlay($locale);
        $meta = $this->pages->meta('how-to-play', $locale);

        return view('how-to-play.index', [
            'howToPlay' => $data + $meta,
            'meta' => $meta,
        ]);
    }

    public function getRulesApi(Request $request): JsonResponse
    {
        $locale = $this->locale($request);

        return response()->json([
            'success' => true,
            'data' => $this->pages->howToPlay($locale),
            'meta' => $this->pages->meta('how-to-play', $locale),
        ]);
    }

    public function simulateBetTypeApi(Request $request): JsonResponse
    {
        if (! (bool) config('payout_illustration.enabled', false)) {
            return response()->json([
                'success' => false,
                'state' => 'NOT_CONFIGURED',
                'message' => 'A public bet simulator is not configured. Educational content does not calculate or promise an outcome.',
            ], 501);
        }

        // Normalised before validation so that "3D" and a JSON number are the
        // same request as "3d" and its decimal string.
        $request->merge([
            'bet_type' => is_string($request->input('bet_type'))
                ? strtolower(trim((string) $request->input('bet_type')))
                : $request->input('bet_type'),
            'stake' => is_int($request->input('stake')) || is_float($request->input('stake'))
                ? (string) $request->input('stake')
                : $request->input('stake'),
        ]);

        // Validated only once the lane is on: while it is off the endpoint must
        // keep answering the single 501 above for any input at all, which is
        // what a client written against the old contract expects.
        $validation = app(PayoutIllustrationRequest::class);
        $request->validate($validation->rules(), $validation->messages());

        $locale = $this->illustrationLocale($request);

        try {
            $illustration = $this->illustrations->illustrate(
                betType: (string) $request->input('bet_type', ''),
                stake: (string) $request->input('stake', ''),
                locale: $locale,
            );
        } catch (InvalidArgumentException $exception) {
            $key = $exception->getMessage();

            return response()->json([
                'success' => false,
                'state' => 'INVALID_INPUT',
                'message' => str_starts_with($key, 'payout_illustration.')
                    ? (string) trans($key, $this->illustrationParameters(), $locale)
                    : (string) trans('payout_illustration.invalid_amount', [], $locale),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'state' => 'NOT_CONFIGURED',
                'message' => (string) trans('payout_illustration.not_configured_multiplier', [], $locale),
            ], 503);
        }

        $state = (string) ($illustration['state'] ?? PayoutIllustrationService::STATE_NOT_CONFIGURED);

        return response()->json([
            'success' => true,
            'state' => $state,
            'message' => $state === PayoutIllustrationService::STATE_READY
                ? (string) trans('payout_illustration.state_ready', [], $locale)
                : (string) trans('payout_illustration.state_not_configured', [], $locale),
            'data' => $illustration,
        ]);
    }

    /**
     * The locale this request is being served in.
     *
     * Read from the container, never from the query string. App\Http\Middleware\
     * SetLocale already negotiated ?lang=/?locale=, the session and the cookie and
     * called app()->setLocale(); a controller that re-read ?locale= could only
     * disagree with the language the middleware chose, and it accepted only 'en'
     * and 'th', so a Bangla request would have been rendered as English content
     * inside a Bangla document.
     */
    private function locale(Request $request): string
    {
        return (string) app()->getLocale();
    }

    /**
     * The language the illustration's own copy is written in.
     *
     * ?locale= may name it (a client embedding the answer elsewhere needs to
     * know which language it got); otherwise the negotiated locale is used and
     * anything unsupported falls back to English inside the service.
     */
    private function illustrationLocale(Request $request): string
    {
        $requested = strtolower(trim((string) $request->input('locale', '')));

        return in_array($requested, ['en', 'th', 'bn'], true)
            ? $requested
            : (string) app()->getLocale();
    }

    /**
     * Interpolation values for the illustration's validation sentences.
     *
     * @return array<string, string>
     */
    private function illustrationParameters(): array
    {
        return [
            'min' => (string) config('payout_illustration.min_stake', ''),
            'max' => (string) config('payout_illustration.max_stake', ''),
            'currency' => (string) config('payout_illustration.currency', 'THB'),
            'scale' => (string) config('payout_illustration.rounding.scale', '2'),
            'source' => (string) config('lottery.payouts.multiplier_source', 'lottery.markets'),
        ];
    }
}
