<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Lottery\PrizeVerificationService;
use App\Services\Lottery\TicketAuthenticityService;
use App\Services\Lottery\TicketBarcodeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public Prize Verification page (PROMPT 4).
 *
 * THIN BY DESIGN. The controller validates shape and size, then hands the
 * request to App\Services\Lottery\PrizeVerificationService. It contains no
 * lookup, no prize logic, no claim logic and no authenticity reasoning, so
 * there is no second implementation of any of those to drift.
 *
 * ANONYMOUS. Both routes are public. The POST carries the
 * 'throttle:ticket-verification' limiter (registered in AppServiceProvider,
 * bounded by config('ticket_verification.rate_limit')) because a public
 * checker over a 1,000,000-value space is an enumeration oracle otherwise.
 *
 * NOTHING THE CLIENT SENDS IS AUTHORITATIVE. The request may only say WHICH
 * mode and WHAT value to check. Fields such as verified, authentic,
 * is_winner, prize or status are never read from input - they are answers,
 * not questions.
 */
final class PrizeVerificationController
{
    public function __construct(
        private readonly PrizeVerificationService $verification,
        private readonly TicketBarcodeService $barcode,
        private readonly TicketAuthenticityService $authenticity,
    ) {}

    /**
     * GET /prize-verification — the empty form.
     */
    public function show(Request $request): View
    {
        unset($request);

        return view('prize-verification.index', $this->pageData(null));
    }

    /**
     * POST /prize-verification — verify one input.
     */
    public function verify(Request $request): View|JsonResponse
    {
        $modes = $this->enabledModes();

        $maxLengths = [
            'number' => (int) config('ticket_verification.input.number.max_length', 6),
            'reference' => (int) config('ticket_verification.input.reference.max_length', 64),
            'barcode' => (int) config('ticket_verification.input.barcode.max_length', 512),
        ];

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:'.implode(',', $modes)],
            // One bound for the request layer; the service re-checks the
            // precise per-mode bound against the NORMALISED value.
            'value' => ['required', 'string', 'min:1', 'max:'.max($maxLengths)],
            'draw' => ['nullable', 'string', 'max:64'],
        ]);

        $mode = (string) $validated['mode'];
        $value = (string) $validated['value'];
        $draw = isset($validated['draw']) && $validated['draw'] !== '' ? (string) $validated['draw'] : null;

        $result = $this->verification->verify(
            kind: $mode,
            value: $value,
            product: null,
            drawRef: $draw,
            correlationId: $this->correlationId($request),
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        }

        return view('prize-verification.index', $this->pageData($result, $mode));
    }

    /**
     * @param  array<string, mixed>|null  $result
     * @return array<string, mixed>
     */
    private function pageData(?array $result, string $mode = 'number'): array
    {
        $locale = (string) app()->getLocale();
        $canonical = rtrim((string) config('app.url'), '/').'/prize-verification';

        return [
            'meta' => [
                'title' => (string) trans('prize_discount.verify_meta_title', [], $locale),
                'description' => (string) trans('prize_discount.verify_meta_description', [], $locale),
                'canonical' => $canonical,
                'lang' => str_replace('_', '-', $locale),
                'og_title' => (string) trans('prize_discount.verify_meta_title', [], $locale),
                'og_description' => (string) trans('prize_discount.verify_meta_description', [], $locale),
                'og_type' => 'website',
                'og_url' => $canonical,
            ],
            'modes' => $this->enabledModes(),
            'mode' => $mode,
            'providers' => $this->barcode->providerStates(),
            'guidance' => $this->authenticity->physicalInspectionGuidance(),
            'result' => $result,
            'limits' => [
                'number_max' => (int) config('ticket_verification.input.number.max_length', 6),
                'reference_max' => (int) config('ticket_verification.input.reference.max_length', 64),
                'barcode_max' => (int) config('ticket_verification.input.barcode.max_length', 512),
                'per_minute' => (int) config('ticket_verification.rate_limit.per_minute', 12),
            ],
            'policy_version' => (string) config('ticket_verification.policy_version', '1'),
        ];
    }

    /**
     * @return list<string>
     */
    private function enabledModes(): array
    {
        $modes = [];

        foreach ((array) config('ticket_verification.modes', []) as $key => $mode) {
            if (is_array($mode) && (bool) ($mode['enabled'] ?? false) === true) {
                $modes[] = (string) $key;
            }
        }

        return $modes === [] ? ['number'] : $modes;
    }

    private function correlationId(Request $request): ?string
    {
        $header = $request->headers->get('X-Correlation-Id');

        if (is_string($header) && $header !== '' && strlen($header) <= 64) {
            return $header;
        }

        $attribute = $request->attributes->get('correlation_id');

        return is_string($attribute) && $attribute !== '' ? substr($attribute, 0, 64) : null;
    }
}
