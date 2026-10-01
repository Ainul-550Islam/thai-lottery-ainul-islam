# Pages 35–44 Implementation Report

## Scope

Pages 35–36 remain on the previously implemented Mega architecture and canonical `/bingo-lottery/year/{year}` route. This task adds and hardens Pages 37–44: the PCSO public result lane, PCSO buy entry, independently addressable PCSO latest/history/year/detail surfaces, and the dedicated GLO L6 home.

The Mega lane was not mixed with PCSO or GLO L6 data. PCSO reads the existing `pcso_lottery_*` services and models, preserves 6D/4D/3D/2D strings and exact states, and keeps `/pcso-lottery/year/{year}` canonical. `/pcso-lottery/archive/{year}` remains a compatibility alias. GLO L6 uses the canonical public GLO services and does not use `GloResultsPageController` hardcoded page data.

## Page map

| Page | Surface | Route | Backend and safety state |
|---:|---|---|---|
| 35 | Mega Lottery year archive | `/bingo-lottery/year/{year}` | Existing Mega history/date/result services |
| 36 | Mega Lottery result detail | Existing canonical Mega detail routes | Existing Mega result/provenance projection |
| 37 | PCSO Lottery | `/pcso-lottery` | PCSO result, history, source and date services |
| 38 | PCSO buy / ticket selection | `/pcso-lottery/buy` | `NOT_CONFIGURED`; no form, price, wallet or reservation action |
| 39 | PCSO draw detail | `/pcso-lottery/draw/{draw}` | PCSO result-by-reference projection |
| 40 | PCSO latest result | `/pcso-lottery/latest` | PCSO current-result service |
| 41 | PCSO historical results | `/pcso-lottery/history` | PCSO history service and bounded pagination |
| 42 | PCSO year archive | `/pcso-lottery/year/{year}` | PCSO date normalization and history service |
| 43 | PCSO result detail | `/pcso-lottery/result/{draw}` | PCSO result-by-reference projection |
| 44 | GLO L6 home | `/glo-l6` | Canonical GLO public services; purchase `NOT_CONFIGURED` |

## Backend, provenance and purchase decisions

- Mega remains isolated in its existing Bingo/Mega lane. No Mega purchase behavior was added.
- PCSO preserves leading-zero strings, four independent result categories, draw date plus local draw time, source/provenance status, integrity hash-only wording, and `Off` for a null category.
- PCSO purchase is fail-closed because no complete verified product → draw → selection → validation → price → responsible-gaming → wallet → reservation → ticket → ledger → idempotency contract is exposed for this lane.
- GLO L6 has an authoritative engine, sales seating, ticket checking, claim, KYC, wallet and ledger infrastructure in the repository, but no verified public purchase endpoint that this page can safely invoke. The dedicated page therefore shows the configured product parameter only when provided by the canonical product service and disables purchase with `NOT_CONFIGURED`.
- The GLO L6 page reuses canonical public result, next-draw, prize-summary, live/replay and catalogue services. It introduces no `/api/v1/glo-l6/home` route.
- No result number, draw date, prize, inventory, provider, official status, government affiliation or purchase value is fabricated in the new page surfaces.

## Validation actually executed

- `node --check resources/js/pcso-lottery.js` completed successfully.
- A key-parity script compared English and Thai PCSO localization and found 139 matching key paths with no missing or extra keys.
- The same key-parity script compared English and Thai GLO L6 localization and found 52 matching key paths with no missing or extra keys.
- A trailing-whitespace scan over all Pages 35–44 files found no active-file issues.
- A forbidden-placeholder scan over the Pages 35–44 implementation files found no TODO, FIXME or shortened-code marker.
- PHP, Laravel route compilation, Blade compilation, PHPUnit, database-backed feature tests, browser validation and Vite build were not claimed: the workspace does not have an executable PHP binary, PHPUnit runtime or installed Vite executable available for those commands.

## Complete changed and created file contents

Every file below is included in full. No file content is abbreviated.

### FILE: `app/Http/Controllers/PcsoLotteryController.php`
# TYPE: PHP controller
# PURPOSE: Thin PCSO page controller for Pages 37–43, including canonical year, latest, history, draw, result, search, compatibility and fail-closed purchase entry points.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Lottery\PcsoLotteryDateService;
use App\Services\Lottery\PcsoLotteryHistoryService;
use App\Services\Lottery\PcsoLotteryPurchaseCapabilityService;
use App\Services\Lottery\PcsoLotteryResultService;
use App\Services\Lottery\PcsoLotterySearchService;
use App\Services\Lottery\PcsoLotterySourceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Public PCSO Lottery result pages.
 *
 * This controller is deliberately thin. The PCSO lane has its own draw,
 * result, history, search, date and source services. It does not read GLO,
 * Mega, Weekly, National or operator-market data, and it never fabricates a
 * number, date, source, price or ticket.
 *
 * Route order is load-bearing in routes/web.php: search, buy, latest, history,
 * year, archive, draw and result are declared before the compatibility
 * wildcard. The wildcard remains available for the original /{draw} route.
 */
final class PcsoLotteryController
{
    public function __construct(
        private readonly PcsoLotteryResultService $results,
        private readonly PcsoLotteryHistoryService $history,
        private readonly PcsoLotterySearchService $search,
        private readonly PcsoLotterySourceService $sources,
        private readonly PcsoLotteryDateService $dates,
        private readonly PcsoLotteryPurchaseCapabilityService $purchaseCapability,
    ) {}

    /**
     * Page 37: PCSO public result landing page.
     */
    public function index(Request $request): View
    {
        unset($request);

        $current = $this->results->currentResult();
        $reference = $this->referenceFrom($current);
        $latestYear = $this->history->latestYear();

        return view('pcso-lottery.index', $this->pageData([
            'current' => $current,
            'recent' => $this->history->recentDraws($reference),
            'years' => $this->history->availableYears(),
            'active_year' => $latestYear,
            'history' => $latestYear === null ? null : $this->history->historyForYear($latestYear, 1),
            'search' => null,
            'page_variant' => 'home',
            'meta' => $this->meta(
                (string) trans('pcso_lottery.meta_title'),
                (string) trans('pcso_lottery.meta_description'),
                '/pcso-lottery',
                true,
            ),
        ]));
    }

    /**
     * Page 38: PCSO buy / ticket selection entry point.
     *
     * No purchase form is rendered until a complete product, draw, selection,
     * validation, price, responsible-gaming, wallet, reservation, issuance,
     * ledger and idempotency contract is verified.
     */
    public function buy(Request $request): View
    {
        unset($request);

        return view('pcso-lottery.buy', [
            'capability' => $this->purchaseCapability->capability(),
            'canonical' => rtrim((string) config('app.url'), '/').route('pcso-lottery.buy', [], false),
            'back_url' => route('pcso-lottery.index'),
            'meta' => $this->meta(
                (string) trans('pcso_lottery.meta_title').' — '.trans('pcso_lottery.buy_title'),
                (string) trans('pcso_lottery.meta_description'),
                '/pcso-lottery/buy',
                false,
            ),
        ]);
    }

    /**
     * Page 40: latest PCSO result.
     */
    public function latestResult(Request $request): View
    {
        unset($request);

        $current = $this->results->currentResult();
        $reference = $this->referenceFrom($current);

        return view('pcso-lottery.latest-result', $this->pageData([
            'current' => $current,
            'recent' => $this->history->recentDraws($reference),
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'history' => null,
            'search' => null,
            'page_variant' => 'latest',
            'meta' => $this->meta(
                (string) trans('pcso_lottery.meta_title').' — '.trans('pcso_lottery.current_result_heading'),
                (string) trans('pcso_lottery.meta_description'),
                '/pcso-lottery/latest',
                (bool) ($current['available'] ?? false),
            ),
        ]));
    }

    /**
     * Page 41: historical PCSO results, starting with the latest available
     * year and preserving the service's pagination and source projection.
     */
    public function historicalResults(Request $request): View
    {
        $latestYear = $this->history->latestYear();
        $history = $latestYear === null
            ? null
            : $this->history->historyForYear($latestYear, $this->boundedPage($request));

        return view('pcso-lottery.history', $this->pageData([
            'current' => null,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => $latestYear,
            'history' => $history,
            'search' => null,
            'page_variant' => 'history',
            'meta' => $this->meta(
                (string) trans('pcso_lottery.meta_title').' — '.trans('pcso_lottery.history_heading'),
                (string) trans('pcso_lottery.meta_description'),
                '/pcso-lottery/history',
                $history !== null && ($history['status'] ?? '') === 'RESULT_FOUND',
            ),
        ]));
    }

    /**
     * Page 42: canonical PCSO year archive.
     */
    public function year(Request $request, string $year): View
    {
        if (preg_match('/^[0-9]{1,4}$/', $year) !== 1) {
            return $this->renderEmptyYear($year, 'INVALID_QUERY');
        }

        $gregorian = $this->dates->normaliseYearInput((int) $year);

        if ($gregorian === null) {
            return $this->renderEmptyYear($year, 'NO_PUBLIC_DATA');
        }

        $history = $this->history->historyForYear($gregorian, $this->boundedPage($request));
        $label = $this->dates->yearLabel($gregorian, $this->locale());

        return view('pcso-lottery.year', $this->pageData([
            'current' => null,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => $gregorian,
            'history' => $history,
            'search' => null,
            'page_variant' => 'year',
            'meta' => $this->meta(
                trans('pcso_lottery.meta_title').' — '.$label,
                (string) trans('pcso_lottery.meta_description_year', ['year' => $label]),
                '/pcso-lottery/year/'.$gregorian,
                (bool) config('pcso_lottery.seo.index_year_pages', true)
                    && ($history['status'] ?? '') === 'RESULT_FOUND',
            ),
        ]));
    }

    /**
     * Compatibility alias for the canonical /pcso-lottery/year/{year} route.
     */
    public function yearArchive(Request $request, string $year): View
    {
        return $this->year($request, $year);
    }

    /**
     * Page 39 and the original /pcso-lottery/{draw} detail route.
     */
    public function show(Request $request, string $draw): View
    {
        return $this->detail($request, $draw, '/pcso-lottery/'.$draw);
    }

    /**
     * Page 39 canonical draw-detail route.
     */
    public function drawDetail(Request $request, string $draw): View
    {
        return $this->detail($request, $draw, '/pcso-lottery/draw/'.$draw);
    }

    /**
     * Page 43 canonical result-detail route.
     */
    public function resultDetail(Request $request, string $draw): View
    {
        return $this->detail($request, $draw, '/pcso-lottery/result/'.$draw);
    }

    /**
     * GET /pcso-lottery/search — typed number or date lookup.
     */
    public function search(Request $request): View
    {
        $validated = $request->validate([
            'type' => ['nullable', 'string', 'in:'.implode(',', $this->search->searchTypes())],
            'term' => ['nullable', 'string', 'max:'.$this->search->maxTermLength()],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);

        $type = isset($validated['type']) && $validated['type'] !== '' ? (string) $validated['type'] : null;
        $term = isset($validated['term']) ? trim((string) $validated['term']) : '';
        $page = isset($validated['page']) ? (int) $validated['page'] : 1;
        $outcome = null;

        if ($term !== '') {
            $outcome = $type === null
                ? $this->search->search('', $term, $page)
                : $this->search->search($type, $term, $page);
        }

        return view('pcso-lottery.index', $this->pageData([
            'current' => null,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'history' => null,
            'search' => $outcome,
            'page_variant' => 'search',
            'meta' => $this->meta(
                (string) trans('pcso_lottery.meta_title_search'),
                (string) trans('pcso_lottery.meta_description'),
                '/pcso-lottery/search',
                false,
            ),
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function pageData(array $data): array
    {
        return array_merge($data, [
            'locale' => $this->locale(),
            'is_thai' => $this->isThai(),
            'source_priority' => $this->sources->priority(),
            'official_configured' => $this->sources->officialConfigured(),
            'search_types' => $this->search->searchTypes(),
            'max_search_length' => $this->search->maxTermLength(),
            'routes' => [
                'index' => route('pcso-lottery.index'),
                'search' => route('pcso-lottery.search'),
                'buy' => route('pcso-lottery.buy'),
                'latest' => route('pcso-lottery.latest'),
                'history' => route('pcso-lottery.history'),
            ],
        ]);
    }

    private function detail(Request $request, string $draw, string $canonicalPath): View
    {
        unset($request);

        $projection = $this->results->resultForReference($draw);
        $title = (string) trans('pcso_lottery.detail_heading').' — '.trans('pcso_lottery.meta_title');

        if (($projection['available'] ?? false) === true && is_array($projection['draw'] ?? null)) {
            $date = is_array($projection['draw']['date'] ?? null) ? $projection['draw']['date'] : [];
            $label = $this->isThai() ? (string) ($date['display_th'] ?? '') : (string) ($date['display_en'] ?? '');
            if ($label !== '') {
                $title .= ' — '.$label;
            }
        }

        return view('pcso-lottery.show', $this->pageData([
            'current' => $projection,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'history' => null,
            'search' => null,
            'page_variant' => 'detail',
            'meta' => $this->meta(
                $title,
                (string) trans('pcso_lottery.meta_description'),
                $canonicalPath,
                (bool) ($projection['available'] ?? false),
            ),
        ]));
    }

    private function renderEmptyYear(string $requested, string $status): View
    {
        $safeLabel = preg_match('/^[0-9]{1,4}$/', $requested) === 1 ? $requested : '';

        return view('pcso-lottery.year', $this->pageData([
            'current' => null,
            'recent' => [],
            'years' => $this->history->availableYears(),
            'active_year' => null,
            'history' => [
                'status' => $status,
                'year' => null,
                'rows' => [],
                'pagination' => [
                    'page' => 1,
                    'per_page' => 0,
                    'total' => 0,
                    'last_page' => 1,
                    'has_previous' => false,
                    'has_next' => false,
                ],
            ],
            'search' => null,
            'page_variant' => 'year',
            'meta' => $this->meta(
                trim(trans('pcso_lottery.meta_title').($safeLabel !== '' ? ' — '.$safeLabel : '')),
                (string) trans('pcso_lottery.meta_description'),
                '/pcso-lottery/year'.($safeLabel !== '' ? '/'.$safeLabel : ''),
                false,
            ),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(string $title, string $description, string $path, bool $indexable): array
    {
        $canonical = rtrim((string) config('app.url'), '/').$path;

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'lang' => str_replace('_', '-', $this->locale()),
            'robots' => $indexable ? 'index,follow' : 'noindex,follow',
            'og_title' => $title,
            'og_description' => $description,
            'og_type' => 'website',
            'og_url' => $canonical,
        ];
    }

    private function boundedPage(Request $request): int
    {
        $page = $request->query('page');

        if (! is_string($page) && ! is_int($page)) {
            return 1;
        }

        $page = (string) $page;
        if (preg_match('/^[0-9]{1,5}$/', $page) !== 1) {
            return 1;
        }

        return max(1, min((int) $page, 10000));
    }

    /**
     * @param  array<string, mixed>  $projection
     */
    private function referenceFrom(array $projection): ?string
    {
        return is_array($projection['draw'] ?? null) && isset($projection['draw']['reference'])
            ? (string) $projection['draw']['reference']
            : null;
    }

    private function locale(): string
    {
        return (string) app()->getLocale();
    }

    private function isThai(): bool
    {
        return str_starts_with(strtolower($this->locale()), 'th');
    }
}
```

### FILE: `app/Http/Controllers/GloL6Controller.php`
# TYPE: PHP controller
# PURPOSE: Dedicated Page 44 GLO L6 home controller using the verified service composition and not the legacy hardcoded results controller.

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Lottery\GloL6HomeService;
use Illuminate\Contracts\View\View;

/**
 * Dedicated public GLO L6 home controller.
 *
 * The page is a composition boundary only. Canonical GLO services provide the
 * result, next draw, prize, live/replay and product data. No legacy hardcoded
 * result controller is used, and no new home API endpoint is introduced.
 */
final class GloL6Controller
{
    public function __construct(private readonly GloL6HomeService $home)
    {
    }

    public function index(): View
    {
        $locale = (string) app()->getLocale();
        $title = (string) trans('glo_l6.meta_title', [], $locale);
        $description = (string) trans('glo_l6.meta_description', [], $locale);
        $canonical = rtrim((string) config('app.url'), '/').'/glo-l6';

        return view('glo-l6.index', [
            'home' => $this->home->pageData(),
            'meta' => [
                'title' => $title,
                'description' => $description,
                'canonical' => $canonical,
                'robots' => 'index,follow',
                'og_title' => $title,
                'og_description' => $description,
                'og_type' => 'website',
                'og_url' => $canonical,
            ],
        ]);
    }
}
```

### FILE: `app/Services/Lottery/PcsoLotteryPurchaseCapabilityService.php`
# TYPE: PHP service
# PURPOSE: Fail-closed PCSO purchase capability projection used by Page 38 when the complete product-to-ledger contract is not verified.

```php
<?php

declare(strict_types=1);

namespace App\Services\Lottery;

/**
 * Fail-closed capability report for the PCSO purchase page.
 *
 * The repository contains PCSO result infrastructure, but it does not expose
 * a verified PCSO product-to-draw-to-selection-to-wallet purchase contract.
 * This service therefore publishes a disabled capability instead of inventing
 * a price, balance rule, reservation, ticket issuer or checkout endpoint.
 */
final class PcsoLotteryPurchaseCapabilityService
{
    /**
     * @return array{status: string, enabled: bool, reason: string, missing: list<string>}
     */
    public function capability(): array
    {
        return [
            'status' => 'NOT_CONFIGURED',
            'enabled' => false,
            'reason' => 'No complete PCSO purchase contract is verified for this application.',
            'missing' => [
                'product definition',
                'draw inventory',
                'selection validation',
                'authoritative price',
                'responsible gaming gate',
                'wallet debit',
                'reservation and ticket issuance',
                'purchase ledger and idempotency record',
            ],
        ];
    }
}
```

### FILE: `app/Services/Lottery/GloL6HomeService.php`
# TYPE: PHP service
# PURPOSE: Dedicated GLO L6 home composition over canonical public GLO result, next-draw, prize, live and product services.

```php
<?php

declare(strict_types=1);

namespace App\Services\Lottery;

/**
 * Dedicated GLO L6 home composition.
 *
 * This service reuses the canonical public GLO home, result, next-draw, prize
 * and live-draw services. It deliberately does not call the legacy
 * GloResultsPageController or copy its hardcoded payload. L6 purchase remains
 * fail-closed because the public purchase contract is not verified.
 */
final class GloL6HomeService
{
    public function __construct(
        private readonly GloPublicHomeService $publicHome,
        private readonly GloL6PurchaseCapabilityService $purchaseCapability,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function pageData(): array
    {
        $product = $this->product();

        return [
            'product' => $product,
            'current_result' => $this->safe(
                fn (): array => $this->publicHome->currentResultCard(),
                $this->unavailableResult(),
            ),
            'next_draw' => $this->safe(
                fn (): array => $this->publicHome->nextDrawCard(),
                [
                    'status' => 'UNAVAILABLE',
                    'draw_number' => null,
                    'scheduled_at_display' => null,
                    'scheduled_at_iso' => null,
                    'timezone' => null,
                    'source' => 'none',
                    'message' => 'Next draw unavailable.',
                ],
            ),
            'live_draw' => $this->safe(
                fn (): array => $this->publicHome->liveCard(),
                [
                    'status' => 'UNAVAILABLE',
                    'live_status' => 'unavailable',
                    'replay_status' => 'unavailable',
                    'source_state' => 'UNAVAILABLE',
                    'provider' => null,
                    'embed_url' => null,
                    'message' => 'Live draw unavailable.',
                ],
            ),
            'prize_highlight' => $this->safe(
                fn (): array => $this->publicHome->prizeCard(),
                [
                    'status' => 'UNAVAILABLE',
                    'tier' => null,
                    'label' => null,
                    'amount' => null,
                    'draw_number' => null,
                    'draw_date' => null,
                    'source_state' => 'UNAVAILABLE',
                    'fixture_sample' => false,
                    'message' => 'Prize summary unavailable.',
                ],
            ),
            'purchase' => $this->purchaseCapability->capability(),
            'checker_url' => route('ticket-check'),
            'results_api_url' => route('api.v1.glo.results.current'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function product(): array
    {
        try {
            $summary = $this->publicHome->productSummary();
            $products = is_array($summary['products'] ?? null) ? $summary['products'] : [];

            foreach ($products as $candidate) {
                if (is_array($candidate) && strtoupper((string) ($candidate['code'] ?? '')) === 'L6') {
                    return [
                        'status' => 'CONFIGURED',
                        'code' => 'L6',
                        'name' => (string) ($candidate['name'] ?? ''),
                        'ticket_price' => (string) ($candidate['ticket_price'] ?? ''),
                        'currency' => (string) ($candidate['currency'] ?? ''),
                        'digits' => (int) ($candidate['digits'] ?? 0),
                        'headline_prize' => $candidate['headline_prize'] ?? null,
                        'notes' => (string) ($candidate['notes'] ?? ''),
                    ];
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return [
            'status' => 'NOT_CONFIGURED',
            'code' => 'L6',
            'name' => null,
            'ticket_price' => null,
            'currency' => null,
            'digits' => null,
            'headline_prize' => null,
            'notes' => null,
        ];
    }

    /**
     * @param  callable(): array<string, mixed>  $builder
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    private function safe(callable $builder, array $fallback): array
    {
        try {
            $payload = $builder();

            return is_array($payload) ? $payload : $fallback;
        } catch (\Throwable $e) {
            report($e);

            return $fallback;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailableResult(): array
    {
        return [
            'status' => 'UNAVAILABLE',
            'has_result' => false,
            'draw_number' => null,
            'draw_date' => null,
            'first_prize' => null,
            'second_prize' => [],
            'third_prize' => [],
            'front_three' => [],
            'last_three' => [],
            'last_two' => [],
            'adjacent_first' => [],
            'source_state' => 'UNAVAILABLE',
            'result_version' => null,
            'message' => 'Result unavailable.',
        ];
    }
}
```

### FILE: `app/Services/Lottery/GloL6PurchaseCapabilityService.php`
# TYPE: PHP service
# PURPOSE: Fail-closed GLO L6 public purchase capability projection; no public purchase mutation is exposed.

```php
<?php

declare(strict_types=1);

namespace App\Services\Lottery;

/**
 * Public purchase capability for the dedicated GLO L6 home.
 *
 * The repository has an authoritative engine with wallet and ledger methods,
 * but no verified public product-to-draw-to-selection web/API contract that
 * this page may invoke. The public surface therefore fails closed and does not
 * expose a selection form, price button or checkout mutation.
 */
final class GloL6PurchaseCapabilityService
{
    /**
     * @return array{status: string, enabled: bool, reason: string, missing: list<string>}
     */
    public function capability(): array
    {
        return [
            'status' => 'NOT_CONFIGURED',
            'enabled' => false,
            'reason' => 'No verified public GLO L6 purchase endpoint is registered for this page.',
            'missing' => [
                'public product and draw contract',
                'selection inventory and validation contract',
                'responsible-gaming admission gate',
                'wallet reservation and debit endpoint',
                'ticket issuance, ledger and idempotency endpoint',
            ],
        ];
    }
}
```

### FILE: `config/pcso_lottery.php`
# TYPE: PHP configuration
# PURPOSE: PCSO lane configuration; the documented field description now explicitly covers 6D, 4D, 3D and 2D.

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PCSO Lottery result lane (PROMPT 9)
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. It is not GLO L6, not GLO N3, not the National
| Lottery lane, not the Weekly Lottery lane, and not an operator market. It
| publishes results and states where they came from. There is no stake, no
| payout, no jackpot and no commission anywhere in this file, because there is
| none anywhere in this lane.
|
| MULTIPLE DRAWS PER DAY. This is the structural difference from every other
| lane in the repository. PCSO publishes several draws on one calendar date -
| for example 14:00, 17:00 and 21:00 - so a date does NOT identify a draw. The
| 'draw_times' block below turns that on, and the shared engine then keys draw
| identity on date AND time. Without it the 17:00 result would be imported as
| a correction of the 14:00 one.
|
| WHAT THIS LANE CANNOT DO. It has no integrity verifier, so this file has no
| 'integrity' block and no PCSO_LOTTERY_INTEGRITY_* key. The Weekly lane runs
| an independent Rust verifier and can therefore say something stronger about
| its fingerprints; this lane hashes in PHP and says exactly that -
| INTEGRITY_HASH_ONLY, independently_verified = false. Wiring the Weekly
| verifier in here and labelling PCSO results "signed verified" would be a
| false claim: that crate canonicalises the Weekly schema, and a hash without
| a trusted authorisation is an integrity signal, not a signature.
|
| NOTHING HERE WAS COPIED. The reference surface that motivated the lane was
| read for its INFORMATION ARCHITECTURE - that a Mega result has a date and
| three numbers, and that history is browsed by year. No text, markup, value,
| prize, fee, contact detail or claim from it appears in this repository.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Identity
    |--------------------------------------------------------------------------
    |
    | product_key is what a log line, a cache key and an audit row call this
    | lane. It never changes; a rename would orphan every cached projection and
    | make historical audit rows unreadable.
    |
    | projection_version is the manual cache-busting lever. Every cache key in
    | the lane embeds it, so changing the SHAPE of a public projection - a new
    | field, a renamed key - is a one-character config change rather than a
    | deploy that serves stale structures to live visitors.
    |
    */

    'product_key' => 'pcso_lottery',

    'projection_version' => (string) env('PCSO_LOTTERY_PROJECTION_VERSION', '1'),

    'enabled' => (bool) env('PCSO_LOTTERY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Calendar
    |--------------------------------------------------------------------------
    |
    | Draw dates are Thai calendar dates. A server in another timezone must not
    | shift them, so the lane pins its own zone rather than inheriting whatever
    | the host happens to be set to.
    |
    | The Buddhist offset is 543 and lives here as data, not as arithmetic
    | scattered through templates. Year pages are addressed by GREGORIAN year in
    | the URL and DISPLAYED in whichever calendar the locale wants; keeping the
    | offset in one place is what makes that safe.
    |
    */

    'timezone' => (string) env('PCSO_LOTTERY_TIMEZONE', env('APP_TIMEZONE', 'Asia/Bangkok')),

    'calendar' => [
        'buddhist_offset' => 543,

        // Bounds exist so a crafted /year/{year} cannot ask the database to
        // scan for a year that could never hold a draw.
        'min_gregorian_year' => (int) env('PCSO_LOTTERY_MIN_YEAR', 1990),
        'max_future_years' => 1,

        'accepted_date_formats' => ['Y-m-d', 'd/m/Y', 'd-m-Y'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cadence
    |--------------------------------------------------------------------------
    |
    | This lane does NOT generate future draws and assumes NO weekday. A draw
    | exists because a result was imported for it. Manufacturing an empty row
    | for a date nobody has published would put a draw on the public page that
    | may never have happened.
    |
    */

    'cadence' => [
        'generates_future_draws' => false,
        'assumed_weekday' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Result fields
    |--------------------------------------------------------------------------
    |
    | Four categories, each an exact width, each a STRING: 6D, 4D, 3D and 2D.
    |
    | The patterns are anchored and length-exact on purpose. A six-character
    | field that accepts five characters would let '04615' be stored where
    | '004615' was meant, and the two are different results. The importer
    | REJECTS a wrong length; it never pads or truncates to make a payload fit,
    | because a repaired result is a fabricated one.
    |
    | Every field is nullable. A draw that published nothing stores NULL in all
    | three and a result_status of 'unavailable' - never '000000', '000' or
    | '00', which are legitimate results a real draw could produce.
    |
    */

    'fields' => [

        'six_digit' => [
            'length' => 6,
            'pattern' => '/^[0-9]{6}$/',
            'label_key' => 'pcso_lottery.field_six_digit',
        ],

        'four_digit' => [
            'length' => 4,
            'pattern' => '/^[0-9]{4}$/',
            'label_key' => 'pcso_lottery.field_four_digit',
        ],

        'three_digit' => [
            'length' => 3,
            'pattern' => '/^[0-9]{3}$/',
            'label_key' => 'pcso_lottery.field_three_digit',
        ],

        'two_digit' => [
            'length' => 2,
            'pattern' => '/^[0-9]{2}$/',
            'label_key' => 'pcso_lottery.field_two_digit',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Draw times
    |--------------------------------------------------------------------------
    |
    | THE ONE SETTING THAT CHANGES WHAT A DRAW IS.
    |
    | With this enabled the shared engine keys draw identity on date AND local
    | draw time, orders history by date then time, and includes the time in the
    | public draw reference. Weekly, Mega and National leave it off and keep
    | one draw per date.
    |
    | The times are NOT an allow-list of when draws may happen - inventing that
    | list would mean rejecting a real draw the operator scheduled differently.
    | They are only what the public page offers as a filter hint.
    |
    */

    'draw_times' => [
        'enabled' => true,
        'display_format' => 'h:i A',
    ],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | TYPE IS EXPLICIT, NEVER GUESSED FROM LENGTH. '09' could be a 2D or the
    | first two characters of something else; guessing would let the page claim
    | a match in a field the visitor never asked about. The type arrives as one
    | of this closed list or the request is refused.
    |
    | FOUR SEARCHABLE WIDTHS HERE: 6, 4, 3 and 2. A five-digit or one-digit
    | term matches no declared width and is refused before a query exists.
    |
    | The type map is also the only place a column name can enter a query. A
    | type outside it cannot reach the database at all, which is what keeps the
    | search free of user-supplied column names.
    |
    */

    'search' => [
        'types' => ['6d', '4d', '3d', '2d', 'date'],
        'default_type' => '6d',

        'type_map' => [
            '6d' => ['column' => 'six_digit', 'length' => 6, 'pattern' => '/^[0-9]{6}$/'],
            '4d' => ['column' => 'four_digit', 'length' => 4, 'pattern' => '/^[0-9]{4}$/'],
            '3d' => ['column' => 'three_digit', 'length' => 3, 'pattern' => '/^[0-9]{3}$/'],
            '2d' => ['column' => 'two_digit', 'length' => 2, 'pattern' => '/^[0-9]{2}$/'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | Priority is the order in which a provider is ASKED. It is NOT a fallback
    | chain: 'fall_through_to_fixture' is false, so a failed official fetch
    | surfaces as unavailable rather than quietly becoming development data
    | wearing an official label.
    |
    | THE OFFICIAL ENDPOINT IS BLANK AND STAYS BLANK. This repository holds no
    | authorised PCSO Lottery data agreement. While the endpoint is empty the
    | lane can reach INTERNAL_RECONCILED or FIXTURE_ONLY and can never reach
    | OFFICIAL_SOURCE_VERIFIED. Configuring a URL does not upgrade data that
    | did not come from it.
    |
    */

    'sources' => [

        'priority' => ['official', 'internal', 'replay', 'fixture'],

        'fall_through_to_fixture' => false,

        'official' => [
            'endpoint' => env('PCSO_LOTTERY_OFFICIAL_ENDPOINT'),
            'token' => env('PCSO_LOTTERY_OFFICIAL_TOKEN'),
            'timeout_seconds' => (int) env('PCSO_LOTTERY_OFFICIAL_TIMEOUT', 8),
            'provider_label' => 'official',
        ],

        'internal' => [
            'enabled' => true,
            'provider_label' => 'internal',
        ],

        // A replay re-reads a payload this platform already holds. It can never
        // outrank an internal reconciliation, because no new external evidence
        // arrived.
        'replay' => [
            'enabled' => true,
            'provider_label' => 'replay',
        ],

        'fixture' => [
            'enabled' => (bool) env('PCSO_LOTTERY_FIXTURE_ENABLED', false),
            'provider_label' => 'fixture',
            'schema_version' => 'PCSO_FIXTURE_V1',
        ],

        'parser_version' => (string) env('PCSO_LOTTERY_PARSER_VERSION', '1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fingerprint domain
    |--------------------------------------------------------------------------
    |
    | The canonical label stored beside every fingerprint. It records WHICH
    | canonicalisation produced the hash, so a later change to the byte layout
    | is distinguishable from a tampered row rather than looking identical to
    | one.
    |
    | There is no verifier binary in this lane, so a fingerprint here means
    | "PHP hashed these canonical bytes" and nothing more. That is exactly what
    | INTEGRITY_HASH_ONLY says, and it is the strongest claim this lane is
    | entitled to make.
    |
    */

    'canonical_version' => 'PCSO1',

    /*
    |--------------------------------------------------------------------------
    | Publication policy
    |--------------------------------------------------------------------------
    */

    'publication' => [
        // PARTIAL RESULTS ARE LEGITIMATE IN THIS LANE.
        //
        // PCSO runs its 6D/4D/3D/2D categories independently, and the public
        // surface shows "Off" for one that did not run on a given draw. So a
        // payload carrying three of the four categories is a real result, not
        // a broken delivery, and the absent category is stored as NULL.
        //
        // Weekly, Mega and National leave this at its default of true, where
        // a half-filled payload really does mean something went wrong.
        'require_complete_result_set' => false,

        // A version in CONFLICT is never publishable. Resolution is an
        // explicit, recorded act by an authorised operator.
        'block_publication_on_conflict' => true,

        'publishable_source_states' => [
            'OFFICIAL_SOURCE_VERIFIED',
            'INTERNAL_RECONCILED',
            'FIXTURE_ONLY',
        ],

        // A published version's values are frozen. A correction is a NEW
        // version; the old one is retained and marked superseded.
        'immutable_after_publication' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Public page bounds
    |--------------------------------------------------------------------------
    |
    | Hard ceilings applied BEFORE a query is built, so a crafted URL cannot ask
    | the database for an unbounded scan.
    |
    */

    'page' => [
        'per_page' => (int) env('PCSO_LOTTERY_PER_PAGE', 20),
        'max_per_page' => 50,
        'recent_rows' => 12,
        'max_years_listed' => 40,
        'max_search_length' => 32,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | SEARCH IS NEVER CACHED. 'cache_search' is false and the search service
    | has no cache call in it. Caching a query-dependent page over an
    | enumerable number space would turn the cache into a free enumeration
    | oracle and would let one visitor's query answer another's.
    |
    */

    'cache' => [
        'enabled' => (bool) env('PCSO_LOTTERY_CACHE_ENABLED', true),
        'ttl_seconds' => (int) env('PCSO_LOTTERY_CACHE_TTL', 300),
        'key_prefix' => 'pcso-lottery',
        'cache_search' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Consumed by the 'pcso-result-search' limiter registered in
    | AppServiceProvider beside the existing limiters. A six-digit space is
    | 1,000,000 values and therefore enumerable, so the ceilings mirror the
    | shape already used by 'national-result-search' and
    | 'weekly-result-search': IP per minute, IP per hour, and a HASHED query
    | fingerprint per minute.
    |
    | robots.txt asks crawlers to stay out of the same route. That is a
    | request; these numbers are the control.
    |
    */

    'rate_limit' => [
        'per_minute' => (int) env('PCSO_LOTTERY_SEARCH_PER_MINUTE', 20),
        'per_hour' => (int) env('PCSO_LOTTERY_SEARCH_PER_HOUR', 200),
        'fingerprint_per_minute' => (int) env('PCSO_LOTTERY_SEARCH_FINGERPRINT_PER_MINUTE', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public vocabularies
    |--------------------------------------------------------------------------
    |
    | Closed lists. A value not present here is downgraded before it reaches a
    | template, so a new internal state can never leak to the public by simply
    | existing.
    |
    */

    'public_source_states' => [
        'OFFICIAL_SOURCE_VERIFIED',
        'INTERNAL_RECONCILED',
        'FIXTURE_ONLY',
        'NOT_CONFIGURED',
        'UNAVAILABLE',
    ],

    'public_lookup_states' => [
        'RESULT_FOUND',
        'RESULT_UNAVAILABLE',
        'RESULT_NOT_FOUND',
        'NO_PUBLIC_DATA',
        'INVALID_QUERY',
        'UNAVAILABLE',
    ],

    /*
    |--------------------------------------------------------------------------
    | SEO
    |--------------------------------------------------------------------------
    |
    | Titles are built from translation keys plus real data. The phrase
    | "official GLO" is deliberately absent: this lane has no verified GLO
    | identity, so claiming one in a <title> would be a false statement served
    | to search engines.
    |
    */

    'seo' => [
        'path' => 'pcso-lottery',
        'index_year_pages' => true,
        // Search pages are never indexed: they are query dependent and would
        // otherwise invite crawlers to enumerate the number space.
        'index_search_pages' => false,
    ],
];
```

### FILE: `routes/web.php`
# TYPE: PHP routes
# PURPOSE: Canonical and compatibility web routes for PCSO Pages 37–43 and the dedicated GLO L6 Page 44, with collision-safe ordering.

```php
<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\LottoFinExecutiveDashboardController;
use App\Http\Controllers\GloL6Controller;
use App\Http\Controllers\GloResultsPageController;
use App\Http\Controllers\Player\PlayerSecuritySettingsController;
use App\Http\Controllers\Player\PlayerDashboardController;
use App\Http\Controllers\Player\PlayerProfilePortalController;
use App\Http\Controllers\Player\PlayerSettingsPortalController;
use App\Http\Controllers\Player\LotteryHistoryPortalController;
use App\Http\Controllers\Wallet\WalletManagementPageController;
use App\Http\Controllers\Payment\DepositMethodsPageController;
use App\Http\Controllers\Payment\WithdrawalMethodsPageController;
use App\Http\Controllers\Betting\ThaiLotteryBettingController;
use App\Http\Controllers\AccountGradeController;
use App\Http\Controllers\AccountVerificationController;
use App\Http\Controllers\BingoLotteryController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LottoDiscountController;
use App\Http\Controllers\LotteryHubController;
use App\Http\Controllers\LotteryPurchasePageController;
use App\Http\Controllers\LegacyRedirectController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\NationalLotteryController;
use App\Http\Controllers\PcsoLotteryController;
use App\Http\Controllers\PrizeVerificationController;
use App\Http\Controllers\PublicAccountInfoController;
use App\Http\Controllers\PublicPagesController;
use App\Http\Controllers\PublicServicePagesController;
use App\Http\Controllers\ResultsController;
use App\Http\Controllers\Auth\MemberAuthController;
use App\Http\Controllers\Verification\AccountVerificationController as MemberAccountVerificationController;
use App\Http\Controllers\Web\BetPurchaseController;
use App\Http\Controllers\Web\PaymentCallbackController;
use App\Http\Controllers\Web\PlayerWebController;
use App\Http\Controllers\WeeklyLotteryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The session-authenticated player web app. Laravel's default web middleware group is
| applied automatically (CSRF, session, cookies), plus the global security headers and
| correlation id middleware registered in bootstrap/app.php.
|
| Every route name here is what the Blade views and the player experience tests already
| reference, so the names are part of the contract:
|   login, login.attempt, register, register.attempt, logout,
|   player.dashboard, player.draws, player.draws.detail, player.bet, player.bets,
|   player.wallet, player.deposit, player.deposit.store, player.withdraw,
|   player.withdraw.store, player.profile, player.profile.update, player.profile.password,
|   player.profile.limits, player.bets.purchase, player.password.update, player.limits.update
|
*/

/*
| Operational endpoints. `/up` is the framework liveness probe registered in
| bootstrap/app.php; the structured health trio and the Prometheus metrics export live
| here against the same HealthController / MetricsController that the observability
| services back.
*/
// P0: /metrics is operator-only telemetry — never financial-public.
Route::middleware(['auth', 'can:access-metrics'])->group(function (): void {
    Route::get('/metrics', [MetricsController::class, 'metrics'])->name('metrics');
});
Route::get('/up/health', [HealthController::class, 'health'])->name('health');
Route::get('/up/ready', [HealthController::class, 'ready'])->name('health.ready');
Route::get('/up/live', [HealthController::class, 'live'])->name('health.live');
Route::get('/health', [HealthController::class, 'health'])->name('health.canonical');
Route::get('/ready', [HealthController::class, 'ready'])->name('health.ready.canonical');
Route::get('/live', [HealthController::class, 'live'])->name('health.live.canonical');

Route::middleware('guest')->group(function (): void {
    // PROMPT 3: the member auth surface (login / registration /
    // password recovery) is served by MemberAuthController — thin
    // orchestration over LoginService / RegistrationService /
    // PasswordResetService (+ the server-authoritative CaptchaService
    // gate). Same route names as before, so every existing link,
    // redirect and test keeps resolving.
    Route::get('/login', [MemberAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [MemberAuthController::class, 'login'])->name('login.attempt')->middleware('throttle:login');
    Route::get('/register', [MemberAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [MemberAuthController::class, 'register'])->name('register.attempt')->middleware('throttle:login');

    // Password recovery: account no./email + CAPTCHA request, then the
    // token-gated new-password form. Throttled on both POSTs.
    Route::get('/forgot-password', [MemberAuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [MemberAuthController::class, 'requestReset'])
        ->middleware('throttle:password-reset')
        ->name('password.request.attempt');
    Route::get('/reset-password/{token}', [MemberAuthController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [MemberAuthController::class, 'resetPassword'])
        ->middleware('throttle:password-reset')
        ->name('password.reset.attempt');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [MemberAuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', [PlayerWebController::class, 'dashboard'])->name('player.dashboard');
    Route::get('/draws', [PlayerWebController::class, 'draws'])->name('player.draws');
    Route::get('/draws/{id}', [PlayerWebController::class, 'drawDetail'])->name('player.draws.detail');

    Route::get('/bet', [PlayerWebController::class, 'betSlip'])->name('player.bet');
    Route::post('/bet/purchase', [BetPurchaseController::class, 'store'])
        ->middleware('throttle:bet')
        ->name('player.bets.purchase');
    Route::post('/player/bets/purchase', [BetPurchaseController::class, 'store'])
        ->middleware('throttle:bet')
        ->name('player.bets.purchase.alias');
    Route::get('/bets', [PlayerWebController::class, 'bets'])->name('player.bets');

    Route::get('/wallet', [PlayerWebController::class, 'wallet'])->name('player.wallet');

    Route::get('/deposit', [PlayerWebController::class, 'deposit'])->name('player.deposit');
    Route::post('/deposit', [PlayerWebController::class, 'storeDeposit'])
        ->middleware('throttle:deposit')
        ->name('player.deposit.store');

    Route::get('/withdraw', [PlayerWebController::class, 'withdraw'])->name('player.withdraw');
    Route::post('/withdraw', [PlayerWebController::class, 'storeWithdraw'])
        ->middleware('throttle:withdrawal')
        ->name('player.withdraw.store');

    Route::get('/profile', [PlayerWebController::class, 'profile'])->name('player.profile');
    Route::put('/profile', [PlayerWebController::class, 'updateProfile'])->name('player.profile.update');
    Route::put('/profile/password', [PlayerWebController::class, 'updatePassword'])->name('player.password.update');
    Route::put('/player/profile/password', [PlayerWebController::class, 'updatePassword'])->name('player.profile.password');
    Route::put('/profile/limits', [PlayerWebController::class, 'updateLimits'])->name('player.limits.update');
    Route::put('/player/profile/limits', [PlayerWebController::class, 'updateLimits'])->name('player.profile.limits');
});

/*
|---------------------------------------------------------------------------
| Account services (PROMPT 3): verification + grade — authenticated only
|---------------------------------------------------------------------------
| Ownership is always the session user. Rate limits: account-verification /
| account-grade (registered in AppServiceProvider).
*/
Route::middleware('auth')->group(function (): void {
    // PROMPT 3: the member Account Verify page is served by the
    // Verification controller (policy-authorized, self-scoped, the
    // immutable submission aggregate behind it). The reviewer decision
    // route is policy-walled (AccountVerificationPolicy::decide).
    Route::get('/account/verification', [MemberAccountVerificationController::class, 'show'])
        ->name('account.verification');
    Route::post('/account/verification', [MemberAccountVerificationController::class, 'submit'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.submit');
    Route::get('/account/verification/document/{document}', [MemberAccountVerificationController::class, 'download'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.document');
    Route::post('/account/verification/{verification}/decision', [MemberAccountVerificationController::class, 'decide'])
        ->middleware('throttle:account-verification')
        ->name('account.verification.decide');

    Route::get('/account/grade', [AccountGradeController::class, 'show'])
        ->middleware('throttle:account-grade')
        ->name('account.grade');
    Route::get('/account/grade/history', [AccountGradeController::class, 'history'])
        ->middleware('throttle:account-grade')
        ->name('account.grade.history');
    Route::post('/account/grade/refresh', [AccountGradeController::class, 'refresh'])
        ->middleware('throttle:account-grade')
        ->name('account.grade.refresh');
});

/*
|--------------------------------------------------------------------------
| Public Home + supporting public pages (anonymous by design)
|--------------------------------------------------------------------------
| Results are served from the verified projection only; fixture datasets are
| labeled FIXTURE_ONLY and are never called official.
*/

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/dashboard', [PlayerDashboardController::class, 'index'])->name('dashboard');
Route::get('/player/dashboard', [PlayerDashboardController::class, 'index'])->name('player.dashboard');
Route::get('/wallet', [WalletManagementPageController::class, 'index'])->name('wallet.index');
Route::get('/player/wallet', [WalletManagementPageController::class, 'index'])->name('player.wallet');
Route::get('/deposit', [DepositMethodsPageController::class, 'index'])->name('deposit.index');
Route::get('/wallet/deposit', [DepositMethodsPageController::class, 'index'])->name('wallet.deposit');
Route::get('/withdrawal', [WithdrawalMethodsPageController::class, 'index'])->name('withdrawal.index');
Route::get('/wallet/withdrawal', [WithdrawalMethodsPageController::class, 'index'])->name('wallet.withdrawal');
Route::get('/betting', [ThaiLotteryBettingController::class, 'index'])->name('betting.index');
Route::get('/lotto/betting', [ThaiLotteryBettingController::class, 'index'])->name('lotto.betting');
// Dedicated GLO L6 home. It uses the canonical public GLO services and is
// intentionally separate from the legacy /results page, whose historical
// controller is not a source for live GLO data.
Route::get('/glo-l6', [GloL6Controller::class, 'index'])
    ->middleware('public.legal')
    ->name('glo-l6.index');

Route::get('/results', [GloResultsPageController::class, 'index'])->name('results.index');
Route::get('/player/security', [PlayerSecuritySettingsController::class, 'index'])->name('player.security');
Route::get('/player/settings', [PlayerSecuritySettingsController::class, 'index'])->name('player.settings');
Route::get('/settings', [PlayerSettingsPortalController::class, 'index'])->name('settings.index');
Route::get('/member/settings', [PlayerSettingsPortalController::class, 'index'])->name('member.settings');
Route::get('/player/settings-portal', [PlayerSettingsPortalController::class, 'index'])->name('player.settings.portal');
Route::get('/profile', [PlayerProfilePortalController::class, 'index'])->name('profile.index');
Route::get('/member/profile', [PlayerProfilePortalController::class, 'index'])->name('member.profile');
Route::get('/player/profile-portal', [PlayerProfilePortalController::class, 'index'])->name('player.profile.portal');
Route::get('/history', [LotteryHistoryPortalController::class, 'index'])->name('history.index');
Route::get('/member/history', [LotteryHistoryPortalController::class, 'index'])->name('member.history');
Route::get('/player/history-portal', [LotteryHistoryPortalController::class, 'index'])->name('player.history.portal');
Route::get('/results/search', [ResultsController::class, 'search'])->name('results.search');

// Public ticket check UI (primary UX; the JSON API remains at /api/v1/glo/results/check/{n}).
Route::get('/check', [HomeController::class, 'checkForm'])->name('ticket-check');
Route::post('/check', [HomeController::class, 'checkSubmit'])
    ->middleware('throttle:home-check')
    ->name('ticket-check.submit');

// Public sales-point search UI (uses existing GloSalesPointService).
Route::get('/sales-points', [HomeController::class, 'salesPoints'])->name('sales-points');

// Public informational + legal pages (versioned Terms from config/legal.php).
// public.legal = PublicLegalHeaders middleware: safe guest GET cache only.
Route::get('/about', [PublicPagesController::class, 'about'])
    ->middleware('public.legal')
    ->name('about');
Route::get('/vision', [PublicPagesController::class, 'vision'])
    ->middleware('public.legal')
    ->name('vision');
Route::get('/terms', [PublicPagesController::class, 'terms'])
    ->middleware('public.legal')
    ->name('terms');

// Public Fees (PROMPT 3) — anonymous, config-driven, no user-specific fees.
Route::get('/fees', [PublicPagesController::class, 'fees'])
    ->middleware('public.legal')
    ->name('fees');
Route::get('/our-fees', [PublicPagesController::class, 'fees'])
    ->middleware('public.legal')
    ->name('our-fees');

// Public Prize Verification (PROMPT 4) — anonymous ticket / result checker.
Route::get('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('prize-verification');
Route::post('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'verifyApi'])
    ->middleware('throttle:ticket-verification')
    ->name('prize-verification.verify');
Route::post('/prize-verification', [\App\Http\Controllers\PublicPrizeVerificationController::class, 'verifyApi'])
    ->middleware('throttle:ticket-verification')
    ->name('prize-verification.submit');

// Public Discount Rules (PROMPT 4) — anonymous product/game matrix.
Route::get('/discounts', [\App\Http\Controllers\PublicLottoDiscountController::class, 'index'])
    ->middleware('public.legal')
    ->name('discounts');
Route::get('/lotto-discount', [\App\Http\Controllers\PublicLottoDiscountController::class, 'index'])
    ->middleware('public.legal')
    ->name('lotto-discount');

// Public How to Play Guide
Route::get('/how-to-play', [\App\Http\Controllers\PublicHowToPlayController::class, 'index'])
    ->middleware('public.legal')
    ->name('how-to-play');

// Public FAQ / Knowledge Base
Route::get('/faq', [\App\Http\Controllers\PublicFaqController::class, 'index'])
    ->middleware('public.legal')
    ->name('faq');

/*
|--------------------------------------------------------------------------
| PROMPT 5: public National Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These four routes serve national_lottery_* data and
| nothing else: not GLO L6/N3, not an operator market, not a lottery provider
| that has not published. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search, /buy, /latest, /history, /year/{year},
| /archive/{year}, /draw/{draw} and /result/{draw} are declared BEFORE /{draw}.
| Reversed, the wildcard would capture a literal page segment and turn it into
| a draw lookup.
|
| PARAMETER PATTERNS ARE HARD BOUNDARIES. {year} is at most four decimal
| digits and {draw} is at most forty characters of an explicit alphabet, so a
| hostile URL is refused by the router before a controller, a validator or a
| query is ever reached.
|
| /search carries throttle:national-result-search (registered in
| AppServiceProvider from config('national_lottery.rate_limit')): IP per
| minute, IP per hour, and a hashed query fingerprint per minute. robots.txt
| asks crawlers to stay out of the same path, but that is a request - this
| limiter is the control.
|
| public.legal = PublicLegalHeaders: short guest-GET cache headers on the
| listed cacheable paths only. The search route is deliberately outside that
| list, so a query-dependent response is never cached at the edge.
*/
Route::get('/lotteries', [LotteryHubController::class, 'index'])
    ->middleware('public.legal')
    ->name('lotteries.index');

Route::get('/national-lottery', [NationalLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('national-lottery.index');

Route::get('/national-lottery/buy', [LotteryPurchasePageController::class, 'national'])
    ->middleware('public.legal')
    ->name('national-lottery.buy');

Route::get('/national-lottery/latest', [NationalLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('national-lottery.latest');

Route::get('/national-lottery/history', [NationalLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('national-lottery.history');

Route::get('/national-lottery/draw/{draw}', [NationalLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.draw-detail');

Route::get('/national-lottery/result/{draw}', [NationalLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.result-detail');

Route::get('/national-lottery/search', [NationalLotteryController::class, 'search'])
    ->middleware('throttle:national-result-search')
    ->name('national-lottery.search');

Route::get('/national-lottery/year/{year}', [NationalLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('national-lottery.year');

Route::get('/national-lottery/archive/{year}', [NationalLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('national-lottery.year-archive');

Route::get('/national-lottery/{draw}', [NationalLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('national-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 6: public Weekly Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These canonical and page-specific routes serve
| weekly_lottery_* data and nothing else: not GLO L6/N3, not the National lane,
| not an operator market. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| PARAMETER PATTERNS ARE HARD BOUNDARIES. {year} is at most four decimal
| digits and {draw} is at most forty characters of an explicit alphabet, so a
| hostile URL is refused by the router before a controller, a validator or a
| query is ever reached.
|
| /search carries throttle:weekly-result-search (registered in
| AppServiceProvider from config('weekly_lottery.rate_limit')): IP per minute,
| IP per hour, and a hashed query fingerprint per minute. A public lookup over
| a 1,000,000-value space is an enumeration oracle without it.
|
| public.legal = PublicLegalHeaders: short guest-GET cache headers on the
| listed cacheable paths only. The search route is deliberately outside that
| list, so a query-dependent response is never cached at the edge.
*/
Route::get('/weekly-lottery', [WeeklyLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('weekly-lottery.index');

Route::get('/weekly-lottery/buy', [LotteryPurchasePageController::class, 'weekly'])
    ->middleware('public.legal')
    ->name('weekly-lottery.buy');

Route::get('/weekly-lottery/search', [WeeklyLotteryController::class, 'search'])
    ->middleware('throttle:weekly-result-search')
    ->name('weekly-lottery.search');

Route::get('/weekly-lottery/latest', [WeeklyLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('weekly-lottery.latest');

Route::get('/weekly-lottery/history', [WeeklyLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('weekly-lottery.history');

Route::get('/weekly-lottery/archive/{year}', [WeeklyLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('weekly-lottery.archive');

Route::get('/weekly-lottery/draw/{draw}', [WeeklyLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.draw');

Route::get('/weekly-lottery/result/{draw}', [WeeklyLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.result');

Route::get('/weekly-lottery/year/{year}', [WeeklyLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('weekly-lottery.year');

Route::get('/weekly-lottery/{draw}', [WeeklyLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('weekly-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 8: public Bingo / Mega Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. These canonical and page-specific routes serve
| bingo_lottery_* data and nothing else: not GLO L6/N3, not the National lane,
| not the Weekly lane, not an operator market. They read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| /search carries throttle:bingo-result-search (registered in
| AppServiceProvider from config('bingo_lottery.rate_limit')): IP per minute,
| IP per hour, and a hashed query fingerprint per minute. robots.txt asks
| crawlers to stay out of the same path, but that is a request - this limiter
| is the control.
|
*/
Route::get('/bingo-lottery', [BingoLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('bingo-lottery.index');

Route::get('/bingo-lottery/search', [BingoLotteryController::class, 'search'])
    ->middleware('throttle:bingo-result-search')
    ->name('bingo-lottery.search');

Route::get('/bingo-lottery/buy', [BingoLotteryController::class, 'buy'])
    ->middleware('public.legal')
    ->name('bingo-lottery.buy');

Route::get('/bingo-lottery/latest', [BingoLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('bingo-lottery.latest');

Route::get('/bingo-lottery/history', [BingoLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('bingo-lottery.history');

Route::get('/bingo-lottery/archive/{year}', [BingoLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('bingo-lottery.archive');

Route::get('/bingo-lottery/draw/{draw}', [BingoLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.draw');

Route::get('/bingo-lottery/result/{draw}', [BingoLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.result');

Route::get('/bingo-lottery/year/{year}', [BingoLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('bingo-lottery.year');

Route::get('/bingo-lottery/{draw}', [BingoLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('bingo-lottery.show');

/*
|--------------------------------------------------------------------------
| PROMPT 9: public PCSO Lottery result surface
|--------------------------------------------------------------------------
|
| A SEPARATE PRODUCT LANE. Four routes over pcso_lottery_* data: not GLO
| L6/N3, not National, not Weekly, not Mega, not an operator market. They
| read; nothing here writes.
|
| ORDER IS LOAD-BEARING. /search and /year/{year} are declared BEFORE
| /{draw}. Reversed, the wildcard would match the literal string "search" and
| a visitor asking to search would get a draw-not-found page for a draw named
| "search".
|
| The {draw} pattern allows the longer PCSO reference, which carries a draw
| TIME as well as a date (PCSO-20260910-2100) because this lane publishes
| several draws per day.
|
| /search carries throttle:pcso-result-search. robots.txt asks crawlers to
| stay out of the same path, but that is a request - this limiter is the
| control.
|
*/

Route::get('/pcso-lottery', [PcsoLotteryController::class, 'index'])
    ->middleware('public.legal')
    ->name('pcso-lottery.index');

Route::get('/pcso-lottery/search', [PcsoLotteryController::class, 'search'])
    ->middleware('throttle:pcso-result-search')
    ->name('pcso-lottery.search');

Route::get('/pcso-lottery/buy', [PcsoLotteryController::class, 'buy'])
    ->middleware('public.legal')
    ->name('pcso-lottery.buy');

Route::get('/pcso-lottery/latest', [PcsoLotteryController::class, 'latestResult'])
    ->middleware('public.legal')
    ->name('pcso-lottery.latest');

Route::get('/pcso-lottery/history', [PcsoLotteryController::class, 'historicalResults'])
    ->middleware('public.legal')
    ->name('pcso-lottery.history');

// /year/{year} is canonical. /archive/{year} is retained as a compatibility
// alias and is declared before both detail wildcards.
Route::get('/pcso-lottery/year/{year}', [PcsoLotteryController::class, 'year'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('pcso-lottery.year');

Route::get('/pcso-lottery/archive/{year}', [PcsoLotteryController::class, 'yearArchive'])
    ->where('year', '[0-9]{1,4}')
    ->middleware('public.legal')
    ->name('pcso-lottery.archive');

Route::get('/pcso-lottery/draw/{draw}', [PcsoLotteryController::class, 'drawDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.draw');

Route::get('/pcso-lottery/result/{draw}', [PcsoLotteryController::class, 'resultDetail'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.result');

// Original compatibility route; every named detail route above wins first.
Route::get('/pcso-lottery/{draw}', [PcsoLotteryController::class, 'show'])
    ->where('draw', '[A-Za-z0-9\-]{1,40}')
    ->middleware('public.legal')
    ->name('pcso-lottery.show');

// Static pages used by footer/support CTAs when configured.
Route::get('/privacy', [PublicPagesController::class, 'privacy'])->name('privacy');

/*
|--------------------------------------------------------------------------
| PROMPT 10: public Contact / Support centre
|--------------------------------------------------------------------------
|
| The GET route KEEPS ITS NAME. About, both footers, the privacy page and the
| terms page all link to route('contact'), and existing tests assert those
| links resolve. Renaming it to something tidier would have broken five
| surfaces to gain nothing.
|
| The POST carries throttle:contact-submit. A public endpoint that sends mail
| is a relay without one. It is also inside the normal web middleware group,
| so Laravel's CSRF protection applies - deliberately not excluded to make an
| AJAX submission simpler.
|
*/

/*
|--------------------------------------------------------------------------
| Public account-programme explainers
|--------------------------------------------------------------------------
|
| SIGNED-OUT INFORMATION, NOT THE ACCOUNT PAGES. /account/grade and
| /account/verification stay behind auth and show a person their own figures.
| These two show the LADDER and the PROCESS to somebody who has not
| registered and therefore cannot see either.
|
| Separate paths on purpose: relaxing auth on the existing routes would have
| meant one URL answering differently depending on who asked, which is how a
| personal figure eventually renders for a guest.
|
*/

Route::get('/account-grades', [\App\Http\Controllers\PublicGradeController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-grades');

Route::get('/account-grade', [\App\Http\Controllers\PublicGradeController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-grade');

Route::get('/account-verification', [\App\Http\Controllers\PublicVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-verification');

Route::get('/account-verification-guide', [\App\Http\Controllers\PublicVerificationController::class, 'index'])
    ->middleware('public.legal')
    ->name('account-verification-guide');

Route::get('/contact', [\App\Http\Controllers\ContactController::class, 'show'])
    ->middleware('public.legal')
    ->name('contact');

Route::get('/contact-us', [\App\Http\Controllers\ContactController::class, 'show'])
    ->middleware('public.legal')
    ->name('contact-us');

Route::get('/download', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('download');

Route::get('/download-app', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('download-app');

Route::get('/app', [\App\Http\Controllers\PublicDownloadAppController::class, 'index'])
    ->middleware('public.legal')
    ->name('app');

// XML sitemap (FINAL AUDIT #15): canonical public URLs only — no auth,
// admin, API, search-form, payment-return or legacy .php duplicates.
// Read-only and cacheable.
Route::get('/sitemap.xml', \App\Http\Controllers\SitemapController::class)
    ->name('sitemap');

/*
|--------------------------------------------------------------------------
| Browser payment-return pages (FINAL AUDIT #2)
|--------------------------------------------------------------------------
|
| Where a gateway drops the player's browser after checkout. PRESENTATION
| ONLY: the landing route is context, the displayed state is always the
| internal payment record (see PaymentCallbackController), and nothing on
| these pages can credit or change money. Paths come from the same
| config/payment.php callback block the gateway drivers build their
| success/cancel URLs from, so they can never drift apart.
|
*/

Route::middleware('auth')->group(function (): void {
    // The config values may be absolute URLs ("${APP_URL}/payment/success")
    // because the gateway drivers hand them to providers; route registration
    // only wants the path component, so normalize once here.
    $callbackPath = static function (string $key, string $default): string {
        $value = (string) config('payment.callback.'.$key, $default);
        $path = parse_url($value, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : $default;
    };

    Route::get($callbackPath('success_url', '/payment/success'), [PaymentCallbackController::class, 'success'])
        ->name('payment.callback.success');

    Route::get($callbackPath('failure_url', '/payment/failure'), [PaymentCallbackController::class, 'failure'])
        ->name('payment.callback.failure');

    Route::get($callbackPath('cancel_url', '/payment/cancel'), [PaymentCallbackController::class, 'cancel'])
        ->name('payment.callback.cancel');

    Route::get($callbackPath('pending_url', '/payment/pending'), [PaymentCallbackController::class, 'pending'])
        ->name('payment.callback.pending');
});

// User-facing locale switch route (session & cookie persistence)
Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, ['en', 'th'], true)) {
        session(['locale' => $locale]);
        cookie()->queue(cookie('locale', $locale, 60 * 24 * 365));
    }

    return redirect()->back();
})->name('locale.switch');

Route::post('/contact', [ContactController::class, 'submit'])
    ->middleware('throttle:contact-submit')
    ->name('contact.submit');

/*
|--------------------------------------------------------------------------
| LOTTOFIN ADMIN & Operations Console Routes
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/', [LottoFinExecutiveDashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [LottoFinExecutiveDashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/api/analytics', [LottoFinExecutiveDashboardController::class, 'analyticsApi'])->name('api.analytics');
    Route::get('/api/reconciliation', [LottoFinExecutiveDashboardController::class, 'reconciliationFeedApi'])->name('api.reconciliation');

    // Operational sub-panels
    Route::get('/draws', [LottoFinExecutiveDashboardController::class, 'index'])->name('draws.index');
    Route::get('/risk', [LottoFinExecutiveDashboardController::class, 'index'])->name('risk.index');
    Route::get('/bets', [LottoFinExecutiveDashboardController::class, 'index'])->name('bets.index');
    Route::get('/wallets', [LottoFinExecutiveDashboardController::class, 'index'])->name('wallets.index');
    Route::get('/ledger', [LottoFinExecutiveDashboardController::class, 'index'])->name('ledger.index');
    Route::get('/reconciliation', [LottoFinExecutiveDashboardController::class, 'index'])->name('reconciliation.index');
    Route::get('/audits', [LottoFinExecutiveDashboardController::class, 'index'])->name('audits.index');

    // Payments & Disbursements
    Route::get('/payments', [LottoFinExecutiveDashboardController::class, 'index'])->name('payments.index');
    Route::get('/withdrawals', [LottoFinExecutiveDashboardController::class, 'index'])->name('withdrawals.index');
    Route::post('/withdrawals/{id}/disburse', [LottoFinExecutiveDashboardController::class, 'index'])->name('withdrawals.disburse');
    Route::post('/withdrawals/{id}/reject', [LottoFinExecutiveDashboardController::class, 'index'])->name('withdrawals.reject');

    // KYC & Compliance
    Route::get('/kyc', [LottoFinExecutiveDashboardController::class, 'index'])->name('kyc.index');
    Route::get('/kyc/{id}/download', [LottoFinExecutiveDashboardController::class, 'index'])->name('kyc.download');
    Route::post('/kyc/{id}/approve', [LottoFinExecutiveDashboardController::class, 'index'])->name('kyc.approve');
    Route::post('/kyc/{id}/reject', [LottoFinExecutiveDashboardController::class, 'index'])->name('kyc.reject');
    Route::get('/compliance', [LottoFinExecutiveDashboardController::class, 'index'])->name('compliance.index');
});

/*
|--------------------------------------------------------------------------
| Agent Portal Routes
|--------------------------------------------------------------------------
*/
Route::prefix('agent')->name('agent.')->group(function (): void {
    Route::get('/', function () { return view('agent.dashboard'); })->name('dashboard');
    Route::get('/dashboard', function () { return view('agent.dashboard'); })->name('dashboard.index');
    Route::get('/commissions', function () { return view('agent.commissions'); })->name('commissions');
    Route::get('/settlements', function () { return view('agent.settlements'); })->name('settlements');
});

/*
|--------------------------------------------------------------------------
| Legacy .php URL compatibility layer (301)
|--------------------------------------------------------------------------
|
| Single home for every public .php URL the replaced site published:
| static pages, member auth surfaces, account explainer pages, the broken
| double-path member URLs, and the per-year archive pages — including the
| "lottoery" typo form search engines indexed. See LegacyRedirectController
| for the map and the rules.
|
| THIS MUST STAY THE LAST ROUTE IN THIS FILE. It only ever sees paths no
| real route claimed, because Laravel matches in registration order, and
| it answers 404 for .php paths it does not know rather than aliasing them.
|
*/

Route::match(['get', 'post'], '/{legacyPath}', [LegacyRedirectController::class, 'resolve'])
    ->where('legacyPath', '.*\.php$')
    ->name('legacy.redirect');
```

### FILE: `resources/views/pcso-lottery/index.blade.php`
# TYPE: Blade view
# PURPOSE: PCSO result landing and search surface for Page 37, using server projections and existing PCSO components.

```php
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/pcso-lottery.css'])
@endpush

@section('content')
@php
    $projection = is_array($current ?? null) ? $current : [];
    $searchPayload = is_array($search ?? null) ? $search : null;
    $searchRows = is_array($searchPayload['matches'] ?? null) ? $searchPayload['matches'] : [];
    $historyPayload = is_array($history ?? null) ? $history : null;
    $historyRows = is_array($historyPayload['rows'] ?? null) ? $historyPayload['rows'] : [];
@endphp

<div class="wl-page pcso-page" data-wl-page="pcso-lottery" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('pcso_lottery.skip_to_content') }}</a>

    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header pcso-page__header">
            <p class="wl-eyebrow">{{ trans('pcso_lottery.public_name') }}</p>
            <h1 class="wl-title">{{ trans('pcso_lottery.heading') }}</h1>
            <p class="wl-intro">{{ trans('pcso_lottery.intro') }}</p>
            <p class="wl-disclaimer">{{ trans('pcso_lottery.not_official_notice') }}</p>
            <nav class="wl-actions" aria-label="{{ trans('pcso_lottery.public_name') }}">
                <a class="wl-link" href="{{ route('pcso-lottery.latest') }}">{{ trans('pcso_lottery.latest_link') }}</a>
                <a class="wl-link" href="{{ route('pcso-lottery.history') }}">{{ trans('pcso_lottery.history_link') }}</a>
                <a class="wl-link" href="{{ route('pcso-lottery.buy') }}">{{ trans('pcso_lottery.buy_title') }}</a>
            </nav>
        </header>

        <section class="pcso-hero" aria-label="{{ trans('pcso_lottery.public_name') }}">
            <div class="pcso-hero__orb" aria-hidden="true">
                <span class="pcso-hero__ring pcso-hero__ring--one"></span>
                <span class="pcso-hero__ring pcso-hero__ring--two"></span>
                <span class="pcso-hero__orb-label">PCSO</span>
            </div>
            <div class="pcso-hero__copy">
                <p class="wl-eyebrow">{{ trans('pcso_lottery.current_result_heading') }}</p>
                <h2>{{ trans('pcso_lottery.public_name') }}</h2>
                <p>{{ trans('pcso_lottery.hero_copy') }}</p>
            </div>
        </section>

        <section class="wl-section" aria-labelledby="pcso-current-heading">
            <h2 id="pcso-current-heading" class="wl-section__heading">{{ trans('pcso_lottery.current_result_heading') }}</h2>
            <x-pcso-lottery.result-card :result="$projection" :is-thai="$is_thai" />
        </section>

        @if ($recent !== [])
            <section class="wl-section" aria-labelledby="pcso-recent-heading">
                <h2 id="pcso-recent-heading" class="wl-section__heading">{{ trans('pcso_lottery.recent_draws_heading') }}</h2>
                <div class="pcso-result-grid">
                    @foreach ($recent as $row)
                        <x-pcso-lottery.result-card :result="$row" :is-thai="$is_thai" />
                    @endforeach
                </div>
            </section>
        @endif

        @if ($historyPayload !== null && $historyRows !== [])
            <section class="wl-section" aria-labelledby="pcso-history-preview-heading">
                <h2 id="pcso-history-preview-heading" class="wl-section__heading">{{ trans('pcso_lottery.history_heading') }}</h2>
                <x-pcso-lottery.result-table
                    :rows="$historyRows"
                    :is-thai="$is_thai"
                    :pagination="$historyPayload['pagination'] ?? null"
                    pagination-route="pcso-lottery.year"
                    :pagination-params="['year' => is_array($historyPayload['year'] ?? null) ? ($historyPayload['year']['gregorian'] ?? '') : '']"
                />
            </section>
        @endif

        <x-pcso-lottery.search-form
            :action="$routes['search']"
            :types="$search_types"
            :max-length="$max_search_length"
            :type="$searchPayload['query']['type'] ?? null"
            :term="$searchPayload['query']['term'] ?? ''"
        />

        @if ($searchPayload !== null)
            <section class="wl-section" aria-labelledby="pcso-search-heading">
                <h2 id="pcso-search-heading" class="wl-section__heading">{{ trans('pcso_lottery.search_results_heading') }}</h2>
                <p class="wl-empty" role="status">
                    {{ trans('pcso_lottery.status.'.strtolower((string) ($searchPayload['status'] ?? 'unavailable'))) }}
                </p>
                <x-pcso-lottery.result-table :rows="$searchRows" :is-thai="$is_thai" />
            </section>
        @endif

        <x-pcso-lottery.year-nav
            :years="$years"
            :active-year="$active_year"
            :is-thai="$is_thai"
        />
    </main>
</div>

<x-public-page.footer />
@endsection

@push('scripts')
    @vite(['resources/js/pcso-lottery.js'])
@endpush
```

### FILE: `resources/views/pcso-lottery/latest-result.blade.php`
# TYPE: Blade view
# PURPOSE: Independently addressable Page 40 latest-result view backed by the canonical PCSO result service.

```php
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/pcso-lottery.css'])
@endpush

@section('content')
<div class="wl-page pcso-page" data-wl-page="pcso-lottery-latest" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('pcso_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <nav class="wl-breadcrumb" aria-label="{{ trans('pcso_lottery.heading') }}">
            <a class="wl-breadcrumb__link" href="{{ route('pcso-lottery.index') }}">{{ trans('pcso_lottery.heading') }}</a>
        </nav>

        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('pcso_lottery.public_name') }}</p>
            <h1 class="wl-title">{{ trans('pcso_lottery.current_result_heading') }}</h1>
            <p class="wl-intro">{{ trans('pcso_lottery.intro') }}</p>
            <p class="wl-disclaimer">{{ trans('pcso_lottery.not_official_notice') }}</p>
        </header>

        <section class="wl-section" aria-labelledby="pcso-latest-result-heading">
            <h2 id="pcso-latest-result-heading" class="wl-section__heading">{{ trans('pcso_lottery.current_result_heading') }}</h2>
            <x-pcso-lottery.result-card :result="$current" :is-thai="$is_thai" />
        </section>

        @if ($recent !== [])
            <section class="wl-section" aria-labelledby="pcso-latest-recent-heading">
                <h2 id="pcso-latest-recent-heading" class="wl-section__heading">{{ trans('pcso_lottery.recent_draws_heading') }}</h2>
                <div class="pcso-result-grid">
                    @foreach ($recent as $row)
                        <x-pcso-lottery.result-card :result="$row" :is-thai="$is_thai" />
                    @endforeach
                </div>
            </section>
        @endif

        <nav class="wl-actions" aria-label="{{ trans('pcso_lottery.public_name') }}">
            <a class="wl-link" href="{{ route('pcso-lottery.history') }}">{{ trans('pcso_lottery.history_link') }}</a>
            <a class="wl-link" href="{{ route('pcso-lottery.buy') }}">{{ trans('pcso_lottery.buy_title') }}</a>
        </nav>

        <x-pcso-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>

<x-public-page.footer />
@endsection

@push('scripts')
    @vite(['resources/js/pcso-lottery.js'])
@endpush
```

### FILE: `resources/views/pcso-lottery/history.blade.php`
# TYPE: Blade view
# PURPOSE: Independently addressable Page 41 historical-results view backed by the PCSO history service.

```php
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/pcso-lottery.css'])
@endpush

@section('content')
@php
    $historyPayload = is_array($history ?? null) ? $history : null;
    $historyRows = is_array($historyPayload['rows'] ?? null) ? $historyPayload['rows'] : [];
    $historyStatus = (string) ($historyPayload['status'] ?? 'NO_PUBLIC_DATA');
    $historyYear = is_array($historyPayload['year'] ?? null)
        ? ($historyPayload['year']['gregorian'] ?? '')
        : ($historyPayload['year'] ?? '');
@endphp

<div class="wl-page pcso-page" data-wl-page="pcso-lottery-history" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('pcso_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <nav class="wl-breadcrumb" aria-label="{{ trans('pcso_lottery.heading') }}">
            <a class="wl-breadcrumb__link" href="{{ route('pcso-lottery.index') }}">{{ trans('pcso_lottery.heading') }}</a>
        </nav>

        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('pcso_lottery.public_name') }}</p>
            <h1 class="wl-title">{{ trans('pcso_lottery.history_heading') }}</h1>
            <p class="wl-intro">{{ trans('pcso_lottery.intro') }}</p>
            <p class="wl-disclaimer">{{ trans('pcso_lottery.not_official_notice') }}</p>
        </header>

        @if ($historyPayload === null || $historyRows === [])
            <section class="wl-section wl-section--empty">
                <p class="wl-empty" role="status">{{ trans('pcso_lottery.status.'.strtolower($historyStatus)) }}</p>
            </section>
        @else
            <section class="wl-section" aria-labelledby="pcso-history-table-heading">
                <div class="pcso-section-heading">
                    <div>
                        <h2 id="pcso-history-table-heading" class="wl-section__heading">{{ trans('pcso_lottery.history_heading') }}</h2>
                        <p class="wl-section__subheading">{{ trans('pcso_lottery.history_year_label', ['year' => $historyYear]) }}</p>
                    </div>
                    <a class="wl-link" href="{{ route('pcso-lottery.year', ['year' => $historyYear]) }}">
                        {{ trans('pcso_lottery.year_archive_link') }}
                    </a>
                </div>
                <x-pcso-lottery.result-table
                    :rows="$historyRows"
                    :is-thai="$is_thai"
                    :pagination="$historyPayload['pagination'] ?? null"
                    pagination-route="pcso-lottery.year"
                    :pagination-params="['year' => $historyYear]"
                />
            </section>
        @endif

        <x-pcso-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>

<x-public-page.footer />
@endsection

@push('scripts')
    @vite(['resources/js/pcso-lottery.js'])
@endpush
```

### FILE: `resources/views/pcso-lottery/year.blade.php`
# TYPE: Blade view
# PURPOSE: Canonical Page 42 PCSO year archive view with server pagination and year navigation.

```php
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/pcso-lottery.css'])
@endpush

@section('content')
@php
    $historyPayload = is_array($history ?? null) ? $history : [];
    $historyRows = is_array($historyPayload['rows'] ?? null) ? $historyPayload['rows'] : [];
    $historyStatus = (string) ($historyPayload['status'] ?? 'NO_PUBLIC_DATA');
    $year = is_array($historyPayload['year'] ?? null)
        ? ($historyPayload['year']['gregorian'] ?? $active_year)
        : ($historyPayload['year'] ?? $active_year);
@endphp

<div class="wl-page pcso-page" data-wl-page="pcso-lottery-year" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('pcso_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <nav class="wl-breadcrumb" aria-label="{{ trans('pcso_lottery.heading') }}">
            <a class="wl-breadcrumb__link" href="{{ route('pcso-lottery.index') }}">{{ trans('pcso_lottery.heading') }}</a>
        </nav>

        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('pcso_lottery.public_name') }}</p>
            <h1 class="wl-title">{{ trans('pcso_lottery.year_archive_heading', ['year' => $year ?? '']) }}</h1>
            <p class="wl-intro">{{ trans('pcso_lottery.meta_description_year', ['year' => $year ?? '']) }}</p>
            <p class="wl-disclaimer">{{ trans('pcso_lottery.not_official_notice') }}</p>
        </header>

        @if ($historyRows === [])
            <section class="wl-section wl-section--empty">
                <p class="wl-empty" role="status">{{ trans('pcso_lottery.status.'.strtolower($historyStatus)) }}</p>
                <p class="wl-empty__action"><a class="wl-link" href="{{ route('pcso-lottery.history') }}">{{ trans('pcso_lottery.history_link') }}</a></p>
            </section>
        @else
            <section class="wl-section" aria-labelledby="pcso-year-table-heading">
                <div class="pcso-section-heading">
                    <h2 id="pcso-year-table-heading" class="wl-section__heading">{{ trans('pcso_lottery.history_heading') }}</h2>
                    <a class="wl-link" href="{{ route('pcso-lottery.latest') }}">{{ trans('pcso_lottery.latest_link') }}</a>
                </div>
                <x-pcso-lottery.result-table
                    :rows="$historyRows"
                    :is-thai="$is_thai"
                    :pagination="$historyPayload['pagination'] ?? null"
                    pagination-route="pcso-lottery.year"
                    :pagination-params="['year' => $year ?? '']"
                />
            </section>
        @endif

        <x-pcso-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>

<x-public-page.footer />
@endsection

@push('scripts')
    @vite(['resources/js/pcso-lottery.js'])
@endpush
```

### FILE: `resources/views/pcso-lottery/buy.blade.php`
# TYPE: Blade view
# PURPOSE: Page 38 PCSO buy/ticket-selection surface that visibly fails closed with NOT_CONFIGURED and no purchase controls.

```php
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/pcso-lottery.css'])
@endpush

@section('content')
<div class="wl-page pcso-page" data-wl-page="pcso-lottery-buy" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('pcso_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <nav class="wl-breadcrumb" aria-label="{{ trans('pcso_lottery.heading') }}">
            <a class="wl-breadcrumb__link" href="{{ $back_url }}">{{ trans('pcso_lottery.heading') }}</a>
        </nav>

        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('pcso_lottery.public_name') }}</p>
            <h1 class="wl-title">{{ trans('pcso_lottery.buy_title') }}</h1>
            <p class="wl-intro">{{ trans('pcso_lottery.purchase_intro') }}</p>
        </header>

        <section class="pcso-purchase-state pcso-purchase-state--disabled" aria-labelledby="pcso-purchase-state-heading">
            <div class="pcso-purchase-state__icon" aria-hidden="true">!</div>
            <div class="pcso-purchase-state__content">
                <p class="pcso-purchase-state__eyebrow">{{ trans('pcso_lottery.purchase_status_heading') }}</p>
                <h2 id="pcso-purchase-state-heading">{{ $capability['status'] }}</h2>
                <p>{{ trans('pcso_lottery.purchase_not_configured_explainer') }}</p>
                <p class="pcso-purchase-state__reason">{{ $capability['reason'] }}</p>
            </div>
        </section>

        <section class="wl-section" aria-labelledby="pcso-missing-contract-heading">
            <h2 id="pcso-missing-contract-heading" class="wl-section__heading">{{ trans('pcso_lottery.purchase_contract_heading') }}</h2>
            <p class="wl-section__subheading">{{ trans('pcso_lottery.purchase_contract_intro') }}</p>
            <ul class="pcso-contract-list">
                @foreach ($capability['missing'] as $missing)
                    <li>{{ $missing }}</li>
                @endforeach
            </ul>
            <p class="wl-disclaimer">{{ trans('pcso_lottery.purchase_no_action_notice') }}</p>
        </section>

        <nav class="wl-actions" aria-label="{{ trans('pcso_lottery.public_name') }}">
            <a class="wl-link" href="{{ $back_url }}">{{ trans('pcso_lottery.back_to_results') }}</a>
            <a class="wl-link" href="{{ route('pcso-lottery.latest') }}">{{ trans('pcso_lottery.latest_link') }}</a>
        </nav>
    </main>
</div>

<x-public-page.footer />
@endsection
```

### FILE: `resources/views/components/pcso-lottery/search-form.blade.php`
# TYPE: Blade component
# PURPOSE: Existing PCSO search form with the explicit 6D, 4D, 3D, 2D and date search contract documented.

```php
@props([
    'action' => '',
    'types' => [],
    'maxLength' => 32,
    'type' => null,
    'term' => '',
])

{{--
    Public search form (PROMPT 9, file 23).

    GET, NOT POST. A search is a read. GET keeps the query in the URL so a
    result page can be linked and bookmarked, and it keeps the form out of
    CSRF territory for an operation that changes nothing.

    THE TYPE IS AN EXPLICIT CHOICE, NOT AN INFERENCE. The visitor picks 6D, 4D, 3D, 2D or Draw date. The server re-checks the value against a closed
    whitelist. Nothing guesses from the length of the term, because with three
    numeric fields a guess means answering a question the visitor did not ask.

    THE INPUT IS A STRING, ALL THE WAY DOWN. type="text" with
    inputmode="numeric", NOT type="number". A number input strips leading
    zeros in several browsers and hands the server 49 when the visitor typed
    049 - which would silently search for a different draw. The maxlength
    mirrors the configured bound so the form cannot offer to submit something
    the validator will refuse.

    ACCESSIBILITY: every control has a real <label> bound by id, the hint text
    is linked with aria-describedby, and the form is fully keyboard operable
    with no custom widgets.
--}}

@php
    $types = is_array($types) ? $types : [];
    $maxLength = max(1, (int) $maxLength);
    $term = (string) $term;
@endphp

<form
    class="wl-search"
    method="GET"
    action="{{ $action }}"
    role="search"
    data-wl-search
    data-wl-max-length="{{ $maxLength }}"
>
    <h2 class="wl-search__heading">{{ trans('pcso_lottery.search_heading') }}</h2>
    <p class="wl-search__intro">{{ trans('pcso_lottery.search_intro') }}</p>

    <div class="wl-search__row">
        <div class="wl-search__group">
            <label class="wl-search__label" for="wl-search-type">
                {{ trans('pcso_lottery.search_type_label') }}
            </label>
            <select class="wl-search__select" id="wl-search-type" name="type" required>
                @foreach ($types as $option)
                    <option value="{{ $option }}" @selected($type === $option)>
                        {{ trans('pcso_lottery.search_type.'.$option) }}
                    </option>
                @endforeach
            </select>
        </div>

        <div class="wl-search__group">
            <label class="wl-search__label" for="wl-search-term">
                {{ trans('pcso_lottery.search_term_label') }}
            </label>
            <input
                class="wl-search__input"
                id="wl-search-term"
                name="term"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                maxlength="{{ $maxLength }}"
                placeholder="{{ trans('pcso_lottery.search_term_placeholder') }}"
                value="{{ $term }}"
                aria-describedby="wl-search-term-hint"
            >
            <p class="wl-search__hint" id="wl-search-term-hint">{{ trans('pcso_lottery.search_hint') }}</p>
        </div>
    </div>

    <div class="wl-search__actions">
        <button class="wl-search__submit" type="submit">{{ trans('pcso_lottery.search_submit') }}</button>
        <a class="wl-search__reset" href="{{ $action }}">{{ trans('pcso_lottery.search_reset') }}</a>
    </div>
</form>
```

### FILE: `resources/views/glo-l6/index.blade.php`
# TYPE: Blade view
# PURPOSE: Dedicated Page 44 GLO L6 home; all result, schedule, prize, live and product values are conditional service projections.

```php
@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/glo-l6.css'])
@endpush

@section('content')
@php
    $product = is_array($home['product'] ?? null) ? $home['product'] : [];
    $result = is_array($home['current_result'] ?? null) ? $home['current_result'] : [];
    $nextDraw = is_array($home['next_draw'] ?? null) ? $home['next_draw'] : [];
    $live = is_array($home['live_draw'] ?? null) ? $home['live_draw'] : [];
    $prize = is_array($home['prize_highlight'] ?? null) ? $home['prize_highlight'] : [];
    $purchase = is_array($home['purchase'] ?? null) ? $home['purchase'] : [];
    $hasResult = (bool) ($result['has_result'] ?? false);
    $sourceState = strtolower((string) ($result['source_state'] ?? 'unavailable'));
    $prizeSourceState = strtolower((string) ($prize['source_state'] ?? 'unavailable'));
@endphp

<div class="glo-l6-page" data-glo-l6-home>
    <a class="glo-l6-skip" href="#glo-l6-main">{{ trans('glo_l6.skip_to_content') }}</a>
    <main id="glo-l6-main" class="glo-l6-main" tabindex="-1">
        <header class="glo-l6-header">
            <p class="glo-l6-kicker">{{ trans('glo_l6.product_label') }}</p>
            <h1>{{ trans('glo_l6.heading') }}</h1>
            <p class="glo-l6-lede">{{ trans('glo_l6.intro') }}</p>
            <p class="glo-l6-disclaimer">{{ trans('glo_l6.provenance_notice') }}</p>
        </header>

        <section class="glo-l6-hero" aria-label="{{ trans('glo_l6.product_label') }}">
            <div class="glo-l6-hero__orb" aria-hidden="true">
                <span class="glo-l6-hero__ring glo-l6-hero__ring--one"></span>
                <span class="glo-l6-hero__ring glo-l6-hero__ring--two"></span>
                <strong>L6</strong>
            </div>
            <div class="glo-l6-hero__copy">
                <p class="glo-l6-kicker">{{ trans('glo_l6.hero_kicker') }}</p>
                <h2>{{ trans('glo_l6.hero_title') }}</h2>
                <p>{{ trans('glo_l6.hero_copy') }}</p>
                @if (($product['status'] ?? '') === 'CONFIGURED' && ($product['ticket_price'] ?? '') !== '')
                    <p class="glo-l6-product-note">
                        {{ trans('glo_l6.product_parameter_label') }}:
                        <strong>{{ $product['ticket_price'] }} {{ $product['currency'] ?? '' }}</strong>
                        · {{ trans('glo_l6.digits_label', ['digits' => $product['digits'] ?? '']) }}
                    </p>
                @else
                    <p class="glo-l6-state glo-l6-state--muted">{{ trans('glo_l6.product_not_configured') }}</p>
                @endif
            </div>
        </section>

        <section class="glo-l6-grid" aria-label="{{ trans('glo_l6.overview_heading') }}">
            <article class="glo-l6-panel glo-l6-panel--result">
                <div class="glo-l6-panel__heading">
                    <div>
                        <p class="glo-l6-kicker">{{ trans('glo_l6.latest_result_label') }}</p>
                        <h2>{{ trans('glo_l6.result_heading') }}</h2>
                    </div>
                    <span class="glo-l6-source glo-l6-source--{{ $sourceState }}">
                        {{ trans('glo_l6.source_state.'.$sourceState) }}
                    </span>
                </div>

                @if ($hasResult)
                    <div class="glo-l6-result-meta">
                        @if (($result['draw_number'] ?? null) !== null)
                            <span>{{ trans('glo_l6.draw_number_label') }}: {{ $result['draw_number'] }}</span>
                        @endif
                        @if (($result['draw_date'] ?? null) !== null)
                            <time datetime="{{ $result['draw_date'] }}">{{ $result['draw_date'] }}</time>
                        @endif
                    </div>
                    <div class="glo-l6-number-row" aria-label="{{ trans('glo_l6.first_prize_label') }}">
                        <span class="glo-l6-number-label">{{ trans('glo_l6.first_prize_label') }}</span>
                        <strong class="glo-l6-number">{{ $result['first_prize'] ?? trans('glo_l6.not_published') }}</strong>
                    </div>
                    <div class="glo-l6-tier-grid">
                        @foreach ([
                            'front_three' => 'front_three_label',
                            'last_three' => 'last_three_label',
                            'last_two' => 'last_two_label',
                            'adjacent_first' => 'adjacent_label',
                        ] as $field => $labelKey)
                            @php $values = is_array($result[$field] ?? null) ? $result[$field] : []; @endphp
                            <div class="glo-l6-tier">
                                <span>{{ trans('glo_l6.'.$labelKey) }}</span>
                                <strong>{{ $values === [] ? trans('glo_l6.not_published') : implode(', ', array_map('strval', $values)) }}</strong>
                            </div>
                        @endforeach
                    </div>
                    <p class="glo-l6-footnote">{{ trans('glo_l6.result_version_label') }}: {{ $result['result_version'] ?? trans('glo_l6.not_recorded') }}</p>
                @else
                    <p class="glo-l6-empty" role="status">{{ trans('glo_l6.no_result') }}</p>
                    @if (($result['message'] ?? '') !== '')
                        <p class="glo-l6-footnote">{{ $result['message'] }}</p>
                    @endif
                @endif
            </article>

            <article class="glo-l6-panel">
                <p class="glo-l6-kicker">{{ trans('glo_l6.next_draw_label') }}</p>
                <h2>{{ trans('glo_l6.next_draw_heading') }}</h2>
                @if (($nextDraw['status'] ?? '') === 'AVAILABLE' && ($nextDraw['scheduled_at_display'] ?? null) !== null)
                    <p class="glo-l6-next-date">{{ $nextDraw['scheduled_at_display'] }}</p>
                    <p class="glo-l6-muted">{{ trans('glo_l6.timezone_label') }}: {{ $nextDraw['timezone'] ?? trans('glo_l6.not_recorded') }}</p>
                    @if (($nextDraw['draw_number'] ?? null) !== null)
                        <p class="glo-l6-muted">{{ trans('glo_l6.draw_number_label') }}: {{ $nextDraw['draw_number'] }}</p>
                    @endif
                @else
                    <p class="glo-l6-empty" role="status">{{ trans('glo_l6.next_draw_unavailable') }}</p>
                @endif
            </article>
        </section>

        <section class="glo-l6-grid" aria-label="{{ trans('glo_l6.supporting_heading') }}">
            <article class="glo-l6-panel">
                <div class="glo-l6-panel__heading">
                    <div>
                        <p class="glo-l6-kicker">{{ trans('glo_l6.prize_label') }}</p>
                        <h2>{{ trans('glo_l6.prize_heading') }}</h2>
                    </div>
                    <span class="glo-l6-source glo-l6-source--{{ $prizeSourceState }}">
                        {{ trans('glo_l6.source_state.'.$prizeSourceState) }}
                    </span>
                </div>
                @if (($prize['amount'] ?? null) !== null)
                    <p class="glo-l6-prize-amount">{{ $prize['amount'] }}</p>
                    @if (($prize['label'] ?? null) !== null)
                        <p class="glo-l6-muted">{{ $prize['label'] }}</p>
                    @endif
                @else
                    <p class="glo-l6-empty" role="status">{{ trans('glo_l6.prize_unavailable') }}</p>
                @endif
            </article>

            <article class="glo-l6-panel">
                <p class="glo-l6-kicker">{{ trans('glo_l6.live_label') }}</p>
                <h2>{{ trans('glo_l6.live_heading') }}</h2>
                @if (($live['status'] ?? '') === 'CONFIGURED' && ($live['embed_url'] ?? null) !== null)
                    <p class="glo-l6-state glo-l6-state--available">{{ trans('glo_l6.live_available') }}</p>
                    <a class="glo-l6-button glo-l6-button--quiet" href="{{ $live['embed_url'] }}" rel="noopener noreferrer">{{ trans('glo_l6.open_live') }}</a>
                @else
                    <p class="glo-l6-empty" role="status">{{ trans('glo_l6.live_unavailable') }}</p>
                @endif
            </article>
        </section>

        <section class="glo-l6-purchase" aria-labelledby="glo-l6-purchase-heading">
            <div>
                <p class="glo-l6-kicker">{{ trans('glo_l6.purchase_label') }}</p>
                <h2 id="glo-l6-purchase-heading">{{ trans('glo_l6.purchase_heading') }}</h2>
                <p>{{ trans('glo_l6.purchase_not_configured_explainer') }}</p>
                <p class="glo-l6-purchase-reason">{{ $purchase['reason'] ?? trans('glo_l6.purchase_not_configured_explainer') }}</p>
            </div>
            <button class="glo-l6-button glo-l6-button--disabled" type="button" disabled aria-disabled="true">
                {{ trans('glo_l6.purchase_disabled') }}
            </button>
        </section>

        <section class="glo-l6-actions" aria-label="{{ trans('glo_l6.actions_heading') }}">
            <a class="glo-l6-button" href="{{ $home['checker_url'] }}">{{ trans('glo_l6.check_ticket') }}</a>
            <a class="glo-l6-button glo-l6-button--quiet" href="{{ $home['results_api_url'] }}">{{ trans('glo_l6.public_results_api') }}</a>
        </section>
    </main>
</div>

<x-public-page.footer />
@endsection
```

### FILE: `resources/css/pcso-lottery.css`
# TYPE: CSS stylesheet
# PURPOSE: Complete PCSO dark-gold-glass result, archive, provenance, search and fail-closed purchase styling.

```css
/*
 * PCSO Lottery public result lane.
 *
 * The lane uses the shared dark, gold and glass visual language while keeping
 * result values, provenance and unavailable states high contrast and readable.
 */

:root {
    --pcso-bg: #0b0904;
    --pcso-panel: rgba(20, 16, 7, 0.86);
    --pcso-panel-strong: #141007;
    --pcso-gold: #d4af37;
    --pcso-gold-light: #fff6d6;
    --pcso-muted: #a99f8b;
    --pcso-line: rgba(212, 175, 55, 0.24);
    --pcso-green: #54d69a;
    --pcso-red: #f58c8c;
}

.pcso-page {
    min-height: 100vh;
    background:
        radial-gradient(circle at 12% 0%, rgba(212, 175, 55, 0.12), transparent 34rem),
        radial-gradient(circle at 92% 18%, rgba(84, 214, 154, 0.07), transparent 28rem),
        var(--pcso-bg);
    color: #fff;
}

.pcso-page .wl-main {
    max-width: 78rem;
    margin: 0 auto;
    padding: 7rem 1rem 4rem;
}

.pcso-page .wl-header {
    position: relative;
    padding: 1rem 0 2.5rem;
    text-align: center;
}

.pcso-page .wl-eyebrow {
    margin: 0 0 .65rem;
    color: var(--pcso-gold);
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .18em;
    text-transform: uppercase;
}

.pcso-page .wl-title {
    margin: 0;
    color: var(--pcso-gold-light);
    font-size: clamp(2rem, 5vw, 4.2rem);
    font-weight: 900;
    letter-spacing: -.04em;
    line-height: 1.05;
}

.pcso-page .wl-intro,
.pcso-page .wl-disclaimer {
    max-width: 56rem;
    margin: 1rem auto 0;
    color: #d7d0c0;
    line-height: 1.7;
}

.pcso-page .wl-disclaimer {
    max-width: 64rem;
    color: #b7ad9a;
    font-size: .9rem;
}

.wl-skip-link {
    position: absolute;
    left: -999rem;
    top: auto;
    z-index: 20;
    padding: .7rem 1rem;
    background: var(--pcso-gold-light);
    color: var(--pcso-bg);
}

.wl-skip-link:focus {
    left: 1rem;
    top: 1rem;
}

.wl-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: .75rem;
    margin-top: 1.5rem;
}

.wl-link,
.wl-breadcrumb__link {
    display: inline-flex;
    align-items: center;
    min-height: 2.6rem;
    padding: .65rem 1rem;
    border: 1px solid var(--pcso-line);
    border-radius: .8rem;
    color: var(--pcso-gold-light);
    text-decoration: none;
    transition: border-color .2s ease, background .2s ease, transform .2s ease;
}

.wl-link:hover,
.wl-link:focus-visible,
.wl-breadcrumb__link:hover,
.wl-breadcrumb__link:focus-visible {
    border-color: var(--pcso-gold);
    background: rgba(212, 175, 55, .12);
    transform: translateY(-1px);
}

.pcso-hero {
    display: grid;
    grid-template-columns: minmax(9rem, 15rem) 1fr;
    gap: 2rem;
    align-items: center;
    margin: 0 0 2.5rem;
    padding: 2rem;
    overflow: hidden;
    border: 1px solid var(--pcso-line);
    border-radius: 1.7rem;
    background: linear-gradient(135deg, rgba(38, 29, 12, .9), rgba(14, 12, 7, .86));
    box-shadow: 0 1.6rem 4rem rgba(0, 0, 0, .38), inset 0 1px 0 rgba(255, 246, 214, .08);
}

.pcso-hero__orb {
    position: relative;
    display: grid;
    place-items: center;
    width: clamp(9rem, 24vw, 14rem);
    aspect-ratio: 1;
    border: 1px solid rgba(255, 246, 214, .42);
    border-radius: 50%;
    background: radial-gradient(circle at 33% 25%, #fff8e7 0, #e5c365 20%, #b8860b 58%, #513800 100%);
    box-shadow: 0 1.2rem 2.5rem rgba(0, 0, 0, .55), inset 0 .25rem .4rem rgba(255, 255, 255, .5), inset 0 -.5rem .7rem rgba(0, 0, 0, .4);
}

.pcso-hero__ring {
    position: absolute;
    border: 1px solid rgba(212, 175, 55, .55);
    border-radius: 50%;
    transform: rotate(-25deg) skewX(-20deg);
}

.pcso-hero__ring--one {
    width: 140%;
    height: 38%;
}

.pcso-hero__ring--two {
    width: 112%;
    height: 70%;
    transform: rotate(52deg) skewX(-20deg);
}

.pcso-hero__orb-label {
    position: relative;
    z-index: 1;
    color: var(--pcso-bg);
    font-size: clamp(1.2rem, 4vw, 2rem);
    font-weight: 1000;
    letter-spacing: .08em;
}

.pcso-hero__copy h2 {
    margin: 0;
    color: var(--pcso-gold-light);
    font-size: clamp(1.5rem, 4vw, 2.5rem);
    font-weight: 900;
}

.pcso-hero__copy p:last-child {
    max-width: 42rem;
    margin: .75rem 0 0;
    color: #d7d0c0;
    line-height: 1.7;
}

.wl-section {
    margin: 2.5rem 0;
}

.wl-section__heading {
    margin: 0 0 1rem;
    color: var(--pcso-gold-light);
    font-size: clamp(1.25rem, 3vw, 2rem);
    font-weight: 850;
}

.wl-section__subheading {
    margin: -.4rem 0 1.25rem;
    color: var(--pcso-muted);
}

.pcso-section-heading {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
}

.pcso-result-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}

.wl-card {
    position: relative;
    overflow: hidden;
    padding: 1.35rem;
    border: 1px solid var(--pcso-line);
    border-radius: 1.25rem;
    background: linear-gradient(145deg, rgba(33, 25, 10, .94), rgba(15, 13, 8, .92));
    box-shadow: 0 1rem 2.5rem rgba(0, 0, 0, .27), inset 0 1px 0 rgba(255, 246, 214, .07);
}

.wl-card::before {
    position: absolute;
    top: 0;
    left: 1.25rem;
    right: 1.25rem;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(255, 246, 214, .35), transparent);
    content: '';
}

.wl-card__heading {
    margin: 0 0 1rem;
    color: var(--pcso-gold-light);
    font-size: 1.1rem;
}

.wl-card__header {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    padding-bottom: 1rem;
    border-bottom: 1px solid rgba(212, 175, 55, .15);
}

.wl-card__date {
    display: grid;
    gap: .25rem;
    margin: 0;
}

.wl-card__date-main {
    color: #fff;
    font-weight: 800;
}

.wl-card__date-iso,
.wl-card__date-be,
.wl-card__date-time {
    color: var(--pcso-muted);
    font-size: .78rem;
}

.wl-card__numbers {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .7rem;
    margin: 1.25rem 0;
}

.wl-card__number {
    min-width: 0;
    text-align: center;
}

.wl-card__number-label {
    margin-bottom: .45rem;
    color: var(--pcso-muted);
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.wl-card__number-value {
    margin: 0;
}

.wl-digits,
.wl-card__number-missing {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 3.8rem;
    min-height: 3.8rem;
    padding: .45rem;
    border-radius: 50%;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: clamp(1rem, 3vw, 1.45rem);
    font-weight: 900;
    letter-spacing: .02em;
}

.wl-digits {
    color: var(--pcso-bg);
    border: 1px solid rgba(255, 246, 214, .45);
    background: radial-gradient(circle at 35% 25%, #fff8e7, #e5c365 30%, #b8860b 74%, #513800);
    box-shadow: 0 .45rem 1rem rgba(0, 0, 0, .45), inset 0 .15rem .25rem rgba(255, 255, 255, .48), inset 0 -.2rem .35rem rgba(0, 0, 0, .35);
}

.wl-card__number-missing {
    min-width: 4.8rem;
    min-height: 2.7rem;
    border: 1px dashed rgba(245, 140, 140, .55);
    border-radius: .75rem;
    color: var(--pcso-red);
    font-family: inherit;
    font-size: .78rem;
    letter-spacing: .05em;
    text-transform: uppercase;
}

.wl-card__actions {
    margin: 1rem 0 0;
    text-align: right;
}

.wl-card__link,
.wl-table__link {
    color: var(--pcso-gold-light);
    font-size: .86rem;
    font-weight: 800;
    text-decoration: underline;
    text-underline-offset: .2em;
}

.wl-card__empty,
.wl-card__unavailable,
.wl-empty {
    padding: 1rem;
    border: 1px solid rgba(245, 140, 140, .35);
    border-radius: .8rem;
    color: #ffd1d1;
    background: rgba(106, 32, 32, .18);
    line-height: 1.6;
}

.wl-card__unavailable {
    display: grid;
    gap: .4rem;
    margin: 1rem 0;
}

.wl-card__unavailable-badge {
    color: var(--pcso-red);
    font-weight: 850;
}

.wl-card__unavailable-text {
    color: #e5caca;
    font-size: .9rem;
}

.wl-source {
    min-width: 10rem;
}

.wl-source__badge {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    color: #ded7c6;
    font-size: .74rem;
    font-weight: 800;
    line-height: 1.4;
}

.wl-source__dot {
    width: .6rem;
    height: .6rem;
    border-radius: 50%;
    background: #888;
    box-shadow: 0 0 .55rem rgba(255, 255, 255, .18);
}

.wl-source--official .wl-source__dot { background: var(--pcso-green); }
.wl-source--internal .wl-source__dot { background: var(--pcso-gold); }
.wl-source--fixture .wl-source__dot { background: #d88cff; }
.wl-source--unknown .wl-source__dot { background: var(--pcso-red); }

.wl-source__hint,
.wl-source__explainer {
    color: var(--pcso-muted);
    font-size: .88rem;
    line-height: 1.6;
}

.wl-source__facts,
.wl-detail__dates {
    display: grid;
    gap: .7rem;
    margin: 1rem 0;
}

.wl-source__fact,
.wl-detail__date {
    display: grid;
    grid-template-columns: minmax(9rem, 15rem) 1fr;
    gap: 1rem;
    padding-bottom: .65rem;
    border-bottom: 1px solid rgba(255, 255, 255, .08);
}

.wl-source__term,
.wl-detail__date dt {
    color: var(--pcso-muted);
    font-size: .8rem;
    font-weight: 750;
}

.wl-source__value,
.wl-detail__date dd {
    min-width: 0;
    margin: 0;
    color: #f6f0df;
    overflow-wrap: anywhere;
}

.wl-source__hash {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: .78rem;
}

.wl-table-wrap {
    overflow-x: auto;
    border: 1px solid var(--pcso-line);
    border-radius: 1rem;
    background: rgba(16, 13, 7, .75);
}

.wl-table {
    width: 100%;
    min-width: 67rem;
    border-collapse: collapse;
}

.wl-table__caption {
    padding: 1rem;
    color: var(--pcso-muted);
    text-align: left;
    caption-side: top;
    font-size: .85rem;
}

.wl-table__th,
.wl-table__cell {
    padding: .9rem .75rem;
    border-top: 1px solid rgba(255, 255, 255, .08);
    text-align: left;
    vertical-align: middle;
}

.wl-table__th {
    color: var(--pcso-gold-light);
    background: rgba(212, 175, 55, .08);
    font-size: .75rem;
    letter-spacing: .06em;
    text-transform: uppercase;
}

.wl-table__cell {
    color: #ebe4d5;
    font-size: .88rem;
}

.wl-table__cell--date time {
    display: block;
    color: #fff;
    font-weight: 800;
}

.wl-table__date-alt,
.wl-table__time {
    display: block;
    margin-top: .2rem;
    color: var(--pcso-muted);
    font-size: .76rem;
}

.wl-table__cell .wl-digits {
    min-width: 0;
    min-height: 0;
    padding: .45rem .55rem;
    border-radius: .55rem;
    font-size: .95rem;
}

.wl-table__missing {
    color: var(--pcso-red);
    font-size: .76rem;
    font-weight: 800;
    text-transform: uppercase;
}

.wl-table__row--unavailable {
    background: rgba(100, 28, 28, .08);
}

.wl-table__cell--empty {
    padding: 2rem;
    color: var(--pcso-muted);
    text-align: center;
}

.wl-pagination {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: center;
    gap: 1rem;
    margin-top: 1rem;
}

.wl-pagination__link {
    color: var(--pcso-gold-light);
    font-weight: 800;
}

.wl-pagination__status {
    color: var(--pcso-muted);
    font-size: .85rem;
}

.wl-pagination__total {
    margin-left: .35rem;
    color: #e7dfce;
}

.wl-years {
    margin-top: 3rem;
    padding: 1.25rem;
    border: 1px solid var(--pcso-line);
    border-radius: 1rem;
    background: rgba(20, 16, 7, .6);
}

.wl-years__heading {
    margin: 0 0 1rem;
    color: var(--pcso-gold-light);
    font-size: 1.05rem;
}

.wl-years__list {
    display: flex;
    flex-wrap: wrap;
    gap: .7rem;
    margin: 0;
    padding: 0;
    list-style: none;
}

.wl-years__link {
    display: grid;
    gap: .15rem;
    padding: .7rem .85rem;
    border: 1px solid rgba(212, 175, 55, .2);
    border-radius: .75rem;
    color: #f5e6b8;
    text-decoration: none;
}

.wl-years__link:hover,
.wl-years__link:focus-visible,
.wl-years__link--active {
    border-color: var(--pcso-gold);
    background: rgba(212, 175, 55, .12);
}

.wl-years__label { font-weight: 850; }
.wl-years__count,
.wl-years__alt { color: var(--pcso-muted); font-size: .75rem; }

.wl-search {
    margin: 2.5rem 0;
    padding: 1.5rem;
    border: 1px solid var(--pcso-line);
    border-radius: 1.25rem;
    background: linear-gradient(145deg, rgba(32, 25, 11, .82), rgba(15, 13, 8, .86));
}

.wl-search__heading {
    margin: 0;
    color: var(--pcso-gold-light);
    font-size: 1.35rem;
}

.wl-search__intro,
.wl-search__hint {
    color: var(--pcso-muted);
    line-height: 1.6;
}

.wl-search__row {
    display: grid;
    grid-template-columns: minmax(10rem, .7fr) minmax(12rem, 1.3fr);
    gap: 1rem;
}

.wl-search__group { display: grid; gap: .45rem; }
.wl-search__label { color: #f7efd9; font-size: .84rem; font-weight: 800; }
.wl-search__select,
.wl-search__input {
    width: 100%;
    min-height: 2.8rem;
    padding: .7rem .8rem;
    border: 1px solid rgba(212, 175, 55, .3);
    border-radius: .7rem;
    outline: none;
    background: #0e0c07;
    color: #fff;
}

.wl-search__select:focus,
.wl-search__input:focus {
    border-color: var(--pcso-gold);
    box-shadow: 0 0 0 .2rem rgba(212, 175, 55, .13);
}

.wl-search__hint { margin: 0; font-size: .78rem; }
.wl-search__actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1rem; }
.wl-search__submit,
.wl-search__reset {
    min-height: 2.7rem;
    padding: .65rem 1rem;
    border-radius: .7rem;
    font-weight: 850;
    text-decoration: none;
    cursor: pointer;
}

.wl-search__submit { border: 1px solid var(--pcso-gold); background: var(--pcso-gold); color: var(--pcso-bg); }
.wl-search__reset { border: 1px solid var(--pcso-line); color: var(--pcso-gold-light); }

.wl-breadcrumb { margin-bottom: 1.25rem; }
.wl-page--detail .wl-main { max-width: 72rem; }
.wl-notice { padding: 1rem; border-radius: .8rem; line-height: 1.6; }
.wl-notice--correction { border: 1px solid rgba(212, 175, 55, .4); color: #fff1b7; background: rgba(92, 69, 18, .2); }
.wl-empty__action { margin-top: 1rem; }

.pcso-purchase-state {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 1rem;
    align-items: start;
    padding: 1.5rem;
    border: 1px solid rgba(245, 140, 140, .45);
    border-radius: 1.25rem;
    background: linear-gradient(145deg, rgba(72, 28, 28, .45), rgba(20, 16, 7, .9));
}

.pcso-purchase-state__icon {
    display: grid;
    place-items: center;
    width: 2.5rem;
    height: 2.5rem;
    border: 1px solid rgba(245, 140, 140, .6);
    border-radius: 50%;
    color: #ffd1d1;
    font-size: 1.3rem;
    font-weight: 900;
}

.pcso-purchase-state__eyebrow {
    margin: 0;
    color: var(--pcso-red);
    font-size: .75rem;
    font-weight: 850;
    letter-spacing: .1em;
    text-transform: uppercase;
}

.pcso-purchase-state h2 {
    margin: .35rem 0 .7rem;
    color: #fff;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 1.2rem;
}

.pcso-purchase-state p { margin: .5rem 0 0; color: #ead7d7; line-height: 1.65; }
.pcso-purchase-state__reason { color: #ffb7b7 !important; font-size: .88rem; }
.pcso-contract-list { display: grid; gap: .65rem; margin: 1rem 0; padding-left: 1.2rem; color: #e9e0cf; }
.pcso-contract-list li::marker { color: var(--pcso-gold); }

@media (max-width: 760px) {
    .pcso-page .wl-main { padding: 6rem .75rem 3rem; }
    .pcso-hero { grid-template-columns: 1fr; text-align: center; justify-items: center; }
    .pcso-result-grid { grid-template-columns: 1fr; }
    .wl-card__header { display: grid; }
    .wl-card__numbers { gap: .35rem; }
    .wl-digits,
    .wl-card__number-missing { min-width: 3.1rem; min-height: 3.1rem; }
    .wl-search__row { grid-template-columns: 1fr; }
    .wl-source__fact,
    .wl-detail__date { grid-template-columns: 1fr; gap: .25rem; }
    .pcso-section-heading { align-items: flex-start; flex-direction: column; }
}

@media (max-width: 430px) {
    .wl-card { padding: 1rem .75rem; }
    .wl-card__number-label { font-size: .62rem; }
    .wl-digits,
    .wl-card__number-missing { min-width: 2.6rem; min-height: 2.6rem; font-size: .88rem; }
}
```

### FILE: `resources/css/glo-l6.css`
# TYPE: CSS stylesheet
# PURPOSE: Dedicated GLO L6 dark-gold-glass home styling with readable result, financial and unavailable states.

```css
/* Dedicated GLO L6 public home: dark glass, gold depth, readable financial states. */

:root {
    --glo6-bg: #080704;
    --glo6-panel: rgba(24, 19, 8, .84);
    --glo6-line: rgba(212, 175, 55, .26);
    --glo6-gold: #d4af37;
    --glo6-light: #fff6d6;
    --glo6-muted: #aea48f;
    --glo6-green: #5bd8a0;
    --glo6-red: #f59a9a;
}

.glo-l6-page {
    min-height: 100vh;
    color: #fff;
    background:
        radial-gradient(circle at 14% 0, rgba(212, 175, 55, .14), transparent 34rem),
        radial-gradient(circle at 90% 24%, rgba(91, 216, 160, .06), transparent 26rem),
        var(--glo6-bg);
}

.glo-l6-main {
    max-width: 78rem;
    margin: 0 auto;
    padding: 7rem 1rem 4rem;
}

.glo-l6-skip {
    position: absolute;
    left: -999rem;
    top: auto;
    z-index: 20;
    padding: .7rem 1rem;
    background: var(--glo6-light);
    color: var(--glo6-bg);
}

.glo-l6-skip:focus {
    left: 1rem;
    top: 1rem;
}

.glo-l6-header {
    max-width: 60rem;
    margin: 0 auto 2.5rem;
    text-align: center;
}

.glo-l6-kicker {
    margin: 0 0 .65rem;
    color: var(--glo6-gold);
    font-size: .72rem;
    font-weight: 900;
    letter-spacing: .18em;
    text-transform: uppercase;
}

.glo-l6-header h1 {
    margin: 0;
    color: var(--glo6-light);
    font-size: clamp(2.2rem, 6vw, 4.5rem);
    font-weight: 950;
    letter-spacing: -.05em;
    line-height: 1.02;
}

.glo-l6-lede,
.glo-l6-disclaimer {
    max-width: 54rem;
    margin: 1rem auto 0;
    color: #d9d1bf;
    line-height: 1.7;
}

.glo-l6-disclaimer {
    color: var(--glo6-muted);
    font-size: .9rem;
}

.glo-l6-hero {
    display: grid;
    grid-template-columns: minmax(9rem, 15rem) 1fr;
    gap: 2.2rem;
    align-items: center;
    margin-bottom: 2rem;
    padding: 2rem;
    overflow: hidden;
    border: 1px solid var(--glo6-line);
    border-radius: 1.7rem;
    background: linear-gradient(135deg, rgba(46, 35, 12, .88), rgba(13, 11, 6, .9));
    box-shadow: 0 1.8rem 4rem rgba(0, 0, 0, .4), inset 0 1px 0 rgba(255, 246, 214, .08);
}

.glo-l6-hero__orb {
    position: relative;
    display: grid;
    place-items: center;
    width: clamp(9rem, 24vw, 14rem);
    aspect-ratio: 1;
    border: 1px solid rgba(255, 246, 214, .45);
    border-radius: 50%;
    background: radial-gradient(circle at 34% 24%, #fff9e9 0, #e8c86d 22%, #b8860b 60%, #513800 100%);
    box-shadow: 0 1.3rem 2.5rem rgba(0, 0, 0, .55), inset 0 .25rem .4rem rgba(255, 255, 255, .5), inset 0 -.5rem .7rem rgba(0, 0, 0, .42);
}

.glo-l6-hero__orb strong {
    position: relative;
    z-index: 1;
    color: var(--glo6-bg);
    font-size: 2.5rem;
    letter-spacing: .1em;
}

.glo-l6-hero__ring {
    position: absolute;
    border: 1px solid rgba(212, 175, 55, .58);
    border-radius: 50%;
    transform: rotate(-28deg) skewX(-18deg);
}

.glo-l6-hero__ring--one { width: 142%; height: 36%; }
.glo-l6-hero__ring--two { width: 112%; height: 72%; transform: rotate(54deg) skewX(-18deg); }
.glo-l6-hero__copy h2 { margin: 0; color: var(--glo6-light); font-size: clamp(1.6rem, 4vw, 2.7rem); font-weight: 950; }
.glo-l6-hero__copy > p:not(.glo-l6-kicker):not(.glo-l6-product-note):not(.glo-l6-state) { max-width: 44rem; margin: .8rem 0 0; color: #ded5c2; line-height: 1.7; }
.glo-l6-product-note { margin: 1rem 0 0; color: #ece1c8; }
.glo-l6-product-note strong { color: var(--glo6-gold); font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }

.glo-l6-grid { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(18rem, .65fr); gap: 1rem; margin: 1rem 0; }
.glo-l6-panel,
.glo-l6-purchase { border: 1px solid var(--glo6-line); border-radius: 1.25rem; background: linear-gradient(145deg, rgba(31, 24, 10, .9), rgba(14, 12, 7, .9)); box-shadow: 0 1rem 2.5rem rgba(0, 0, 0, .26), inset 0 1px 0 rgba(255, 246, 214, .07); }
.glo-l6-panel { min-width: 0; padding: 1.35rem; }
.glo-l6-panel h2,
.glo-l6-purchase h2 { margin: 0; color: var(--glo6-light); font-size: 1.35rem; font-weight: 900; }
.glo-l6-panel__heading { display: flex; align-items: start; justify-content: space-between; gap: 1rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(212, 175, 55, .15); }
.glo-l6-source { display: inline-flex; max-width: 13rem; padding: .35rem .55rem; border: 1px solid rgba(174, 164, 143, .28); border-radius: .6rem; color: var(--glo6-muted); font-size: .7rem; font-weight: 850; line-height: 1.35; text-align: right; }
.glo-l6-source--official_source_verified { border-color: rgba(91, 216, 160, .5); color: var(--glo6-green); }
.glo-l6-source--fixture_only { border-color: rgba(216, 140, 255, .5); color: #d88cff; }
.glo-l6-source--internal_reconciled { border-color: rgba(212, 175, 55, .5); color: var(--glo6-gold); }
.glo-l6-result-meta { display: flex; flex-wrap: wrap; gap: .75rem; margin: 1rem 0; color: var(--glo6-muted); font-size: .8rem; }
.glo-l6-number-row { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem; border: 1px solid rgba(212, 175, 55, .2); border-radius: 1rem; background: rgba(212, 175, 55, .07); }
.glo-l6-number-label { color: var(--glo6-muted); font-size: .8rem; font-weight: 800; text-transform: uppercase; }
.glo-l6-number { color: var(--glo6-light); font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: clamp(1.6rem, 5vw, 2.5rem); letter-spacing: .12em; }
.glo-l6-tier-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .7rem; margin-top: .8rem; }
.glo-l6-tier { display: grid; gap: .3rem; padding: .8rem; border: 1px solid rgba(255, 255, 255, .09); border-radius: .8rem; }
.glo-l6-tier span { color: var(--glo6-muted); font-size: .74rem; }
.glo-l6-tier strong { color: #f1e8d5; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; overflow-wrap: anywhere; }
.glo-l6-footnote,
.glo-l6-muted { color: var(--glo6-muted); font-size: .82rem; line-height: 1.55; }
.glo-l6-next-date { margin: 1.2rem 0 .5rem; color: var(--glo6-light); font-size: clamp(1.2rem, 3vw, 1.8rem); font-weight: 900; }
.glo-l6-empty { padding: .9rem; border: 1px solid rgba(245, 154, 154, .32); border-radius: .8rem; color: #ffd4d4; background: rgba(91, 29, 29, .18); line-height: 1.55; }
.glo-l6-prize-amount { margin: 1.3rem 0 .35rem; color: var(--glo6-gold); font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: clamp(1.6rem, 5vw, 2.5rem); font-weight: 950; }
.glo-l6-state--available { color: var(--glo6-green); font-weight: 800; }
.glo-l6-state--muted { color: var(--glo6-muted); }

.glo-l6-purchase { display: flex; align-items: center; justify-content: space-between; gap: 1.5rem; margin-top: 1rem; padding: 1.35rem; border-color: rgba(245, 154, 154, .42); background: linear-gradient(145deg, rgba(70, 28, 28, .46), rgba(20, 16, 7, .9)); }
.glo-l6-purchase p:not(.glo-l6-kicker) { max-width: 50rem; margin: .65rem 0 0; color: #ead7d7; line-height: 1.6; }
.glo-l6-purchase-reason { color: #ffb7b7 !important; font-size: .85rem; }
.glo-l6-actions { display: flex; flex-wrap: wrap; gap: .75rem; justify-content: center; margin-top: 1.5rem; }
.glo-l6-button { display: inline-flex; align-items: center; justify-content: center; min-height: 2.75rem; padding: .7rem 1rem; border: 1px solid var(--glo6-gold); border-radius: .75rem; background: var(--glo6-gold); color: var(--glo6-bg); font-weight: 900; text-decoration: none; transition: transform .2s ease, filter .2s ease; }
.glo-l6-button:hover:not(:disabled), .glo-l6-button:focus-visible { transform: translateY(-1px); filter: brightness(1.1); }
.glo-l6-button--quiet { border-color: var(--glo6-line); background: transparent; color: var(--glo6-light); }
.glo-l6-button--disabled { border-color: rgba(245, 154, 154, .5); background: rgba(245, 154, 154, .13); color: #ffb7b7; cursor: not-allowed; opacity: .78; }

@media (max-width: 760px) {
    .glo-l6-main { padding: 6rem .75rem 3rem; }
    .glo-l6-hero { grid-template-columns: 1fr; justify-items: center; text-align: center; }
    .glo-l6-grid { grid-template-columns: 1fr; }
    .glo-l6-panel__heading { display: grid; }
    .glo-l6-purchase { align-items: stretch; flex-direction: column; }
}

@media (max-width: 430px) {
    .glo-l6-panel,
    .glo-l6-purchase,
    .glo-l6-hero { padding: 1rem; }
    .glo-l6-number-row { align-items: start; flex-direction: column; }
    .glo-l6-tier-grid { grid-template-columns: 1fr; }
}
```

### FILE: `resources/js/pcso-lottery.js`
# TYPE: JavaScript
# PURPOSE: PCSO search progressive enhancement only; it contains no result, price, draw, inventory or purchase data.

```javascript
/*
 * PCSO Lottery public progressive enhancement.
 *
 * Server services remain authoritative. This file only improves the search
 * form's input affordance and does not contain results, prices, draw dates,
 * purchase actions or fallback data.
 */
(function () {
    'use strict';

    function bootSearch(form) {
        const type = form.querySelector('[name="type"]');
        const term = form.querySelector('[name="term"]');
        const hint = form.querySelector('.wl-search__hint');
        const configuredMax = Number.parseInt(form.dataset.wlMaxLength || '32', 10);
        const widths = {
            '6d': 6,
            '4d': 4,
            '3d': 3,
            '2d': 2,
            date: configuredMax
        };

        if (!type || !term) {
            return;
        }

        function updateInputMode() {
            const selected = type.value || 'date';
            const max = widths[selected] || configuredMax;
            const isDate = selected === 'date';

            term.maxLength = max;
            term.inputMode = isDate ? 'text' : 'numeric';
            term.pattern = isDate ? '' : '[0-9]{' + max + '}';

            if (hint) {
                hint.dataset.activeSearchType = selected;
            }
        }

        type.addEventListener('change', updateInputMode);
        updateInputMode();

        form.addEventListener('submit', function (event) {
            const selected = type.value;
            const value = term.value.trim();
            const max = widths[selected] || configuredMax;

            if (value === '') {
                return;
            }

            if (selected !== 'date' && !new RegExp('^[0-9]{' + max + '}$').test(value)) {
                event.preventDefault();
                term.focus();
                term.setCustomValidity('Enter the exact digit width for the selected PCSO category.');
                term.reportValidity();
                return;
            }

            term.setCustomValidity('');
        });

        term.addEventListener('input', function () {
            term.setCustomValidity('');
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-wl-search]').forEach(bootSearch);
    });
}());
```

### FILE: `lang/en/pcso_lottery.php`
# TYPE: English localization
# PURPOSE: English PCSO labels, states, provenance, pagination and purchase safety copy.

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PCSO Lottery result surface — English
|--------------------------------------------------------------------------
|
| This file contains labels only. Numbers, dates, source states and purchase
| capability come from the PCSO services and their projections.
|--------------------------------------------------------------------------
*/

return [
    'meta_title' => 'PCSO Lottery Results',
    'meta_title_search' => 'PCSO Lottery Results — Search',
    'meta_description' => 'Published PCSO Lottery results with 6D, 4D, 3D and 2D categories, draw times, source state and record version.',
    'meta_description_year' => 'PCSO Lottery results for :year, with draw time, source state and record version for each published draw.',

    'public_name' => 'PCSO Lottery',
    'heading' => 'PCSO Lottery Results',
    'intro' => 'Every result is shown with its published categories, draw time, source state and record version.',
    'hero_copy' => 'Browse the PCSO result record without replacing an absent category with a number. Each draw keeps its own date and local draw time.',
    'not_official_notice' => 'This page presents results recorded by this platform with their source attached. It is not an official government publication, and each result is only as authoritative as its source state.',
    'skip_to_content' => 'Skip to content',
    'latest_link' => 'Latest result',
    'history_link' => 'Historical results',
    'buy_title' => 'Buy / Ticket selection',
    'back_to_results' => 'Back to PCSO Lottery results',

    'current_result_heading' => 'Latest published result',
    'recent_draws_heading' => 'Recent draws',
    'history_heading' => 'Results history',
    'detail_heading' => 'Draw detail',
    'provenance_heading' => 'Where this result came from',
    'integrity_heading' => 'Record integrity',
    'year_archive_heading' => 'PCSO Lottery results for :year',
    'history_year_label' => 'Showing the available result records for :year.',
    'year_archive_link' => 'Open year archive',

    'field_six_digit' => '6D',
    'field_four_digit' => '4D',
    'field_three_digit' => '3D',
    'field_two_digit' => '2D',
    'field_date' => 'Draw date',
    'field_six_digit_hint' => 'Six digits, with leading zeros preserved.',
    'field_four_digit_hint' => 'Four digits, with leading zeros preserved.',
    'field_three_digit_hint' => 'Three digits, with leading zeros preserved.',
    'field_two_digit_hint' => 'Two digits, with leading zeros preserved.',

    'table_caption' => 'Published PCSO Lottery results, newest draw first. A category marked Off did not publish a value for that draw.',
    'col_draw_date' => 'Draw date',
    'col_draw_time' => 'Draw time',
    'col_six_digit' => '6D',
    'col_four_digit' => '4D',
    'col_three_digit' => '3D',
    'col_two_digit' => '2D',
    'col_source' => 'Source',
    'col_detail' => 'Detail',
    'view_detail' => 'View draw',

    'no_value' => 'Not published',
    'value_off' => 'Off',
    'result_unavailable_badge' => 'No result published',
    'result_unavailable_explainer' => 'This draw is on record and no numbers were published. Nothing is substituted for the missing values.',

    'date_buddhist_label' => 'Thai Buddhist year',
    'date_gregorian_label' => 'Gregorian date',
    'date_timezone_label' => 'Draw timezone',
    'published_at_label' => 'Published',

    'year_nav_heading' => 'Browse by year',
    'year_nav_empty' => 'No year has a published result yet.',
    'year_draw_count' => ':count draws',

    'search_heading' => 'Search results',
    'search_intro' => 'Choose a category or draw date, then enter the exact value. Leading zeros are preserved.',
    'search_type_label' => 'Search by',
    'search_term_label' => 'Value',
    'search_term_placeholder' => 'Exact value',
    'search_submit' => 'Search',
    'search_reset' => 'Clear',
    'search_results_heading' => 'Search results',
    'search_matched_in' => 'Matched in',
    'search_hint' => 'Matching is exact: 09 finds 09 and nothing else.',
    'search_type_missing' => 'Choose what you are searching for before searching.',
    'search_type' => [
        '6d' => '6D',
        '4d' => '4D',
        '3d' => '3D',
        '2d' => '2D',
        'date' => 'Draw date',
    ],

    'pagination_previous' => 'Previous',
    'pagination_next' => 'Next',
    'pagination_status' => 'Page :page of :last',
    'pagination_total' => ':total draws',

    'provenance' => [
        'provider' => 'Provider',
        'source_state' => 'Source state',
        'source_identifier' => 'Source reference',
        'source_host' => 'Source host',
        'payload_fingerprint' => 'Payload fingerprint',
        'normalized_fingerprint' => 'Value fingerprint',
        'parser_version' => 'Parser version',
        'retrieved_at' => 'Retrieved',
        'imported_at' => 'Imported',
        'result_version' => 'Result version',
        'supersedes' => 'Replaces version',
        'none' => 'Not recorded',
        'explainer' => 'The fingerprints are one-way hashes of the delivered payload and its canonical values. They help match a record without republishing the source payload.',
    ],

    'integrity' => [
        'status' => 'Integrity check',
        'canonical_version' => 'Canonical format',
        'independently_verified' => 'Independently re-derived',
        'yes' => 'Yes',
        'no' => 'No',
        'explainer' => 'This lane records a PHP-computed hash. It shows internal consistency and is not a claim of an independent signature or government authorisation.',
    ],
    'integrity_status' => [
        'integrity_hash_only' => 'Hash recorded (not signed)',
        'signature_unsupported' => 'Signature not supported in this lane',
        'fingerprint_mismatch' => 'Fingerprint did not match',
        'not_verified' => 'Not checked',
        'rejected' => 'Rejected',
    ],
    'integrity_hint' => [
        'integrity_hash_only' => 'The stored values match the hash recorded at import. This application computed the hash, so it says nothing about external authorisation.',
        'signature_unsupported' => 'Signature material was supplied, but this lane has no verifier able to check it.',
        'fingerprint_mismatch' => 'The recorded hash does not match the stored values.',
        'not_verified' => 'No integrity check is recorded for this version.',
        'rejected' => 'This record was refused by the integrity check.',
    ],

    'source_state' => [
        'official_source_verified' => 'Official source, verified',
        'official_source_configured' => 'Official source, not yet verified',
        'internal_reconciled' => 'Internally reconciled',
        'fixture_only' => 'Test fixture data',
        'not_configured' => 'No source configured',
        'unavailable' => 'Source unavailable',
    ],
    'source_state_hint' => [
        'official_source_verified' => 'Delivered by the configured official provider and validated on import.',
        'official_source_configured' => 'An official provider is configured but has not verified this record.',
        'internal_reconciled' => 'Recorded and reconciled inside this platform rather than delivered by an official provider.',
        'fixture_only' => 'Sample data used for development and testing. It is not a real draw result.',
        'not_configured' => 'No provider is configured for this lane, so no source can be claimed.',
        'unavailable' => 'The source of this record could not be established.',
    ],

    'status' => [
        'result_found' => 'Result found.',
        'result_unavailable' => 'This draw is on record and no numbers were published for it.',
        'result_not_found' => 'No published result matches that request.',
        'no_public_data' => 'There is no published result to show yet.',
        'invalid_query' => 'That request could not be read. Check the value and try again.',
        'unavailable' => 'Results are temporarily unavailable.',
    ],
    'empty_current' => 'No PCSO Lottery result has been published yet.',
    'empty_year' => 'No published result exists for this year.',
    'empty_search' => 'No published draw matches that search.',
    'correction_notice' => 'This draw has been corrected. You are reading version :version; the earlier version remains in the record.',
    'conflict_notice' => 'A conflicting payload is on hold until it is resolved. The previously published record remains visible.',

    'purchase_intro' => 'The result lane is available. Purchase is disabled until a complete PCSO purchase contract is verified.',
    'purchase_status_heading' => 'Purchase availability',
    'purchase_not_configured_explainer' => 'PCSO ticket selection and purchase are disabled because the product, draw, validation, price, responsible-gaming, wallet, reservation, issuance, ledger and idempotency path is not verified end to end.',
    'purchase_contract_heading' => 'Required purchase contract',
    'purchase_contract_intro' => 'The page does not collect a number, display a price or submit a purchase while any required backend step is unverified.',
    'purchase_no_action_notice' => 'No ticket, inventory, price, wallet debit or reservation has been created by this page.',
];
```

### FILE: `lang/th/pcso_lottery.php`
# TYPE: Thai localization
# PURPOSE: Thai PCSO localization with exact key parity against the English file.

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PCSO Lottery result surface — Thai
|--------------------------------------------------------------------------
|
| This file mirrors lang/en/pcso_lottery.php key-for-key. Values, dates and
| source states remain server-provided PCSO projections.
|--------------------------------------------------------------------------
*/

return [
    'meta_title' => 'ผลหวย PCSO',
    'meta_title_search' => 'ผลหวย PCSO — ค้นหา',
    'meta_description' => 'ผลหวย PCSO ที่เผยแพร่แล้ว พร้อมหมวด 6D, 4D, 3D และ 2D เวลาออกรางวัล สถานะแหล่งที่มา และเวอร์ชันบันทึก',
    'meta_description_year' => 'ผลหวย PCSO ประจำปี :year พร้อมเวลาออกรางวัล สถานะแหล่งที่มา และเวอร์ชันบันทึกของแต่ละงวด',

    'public_name' => 'หวย PCSO',
    'heading' => 'ผลหวย PCSO',
    'intro' => 'ผลแต่ละรายการแสดงหมวดที่เผยแพร่ เวลาออกรางวัล สถานะแหล่งที่มา และเวอร์ชันของบันทึก',
    'hero_copy' => 'ดูบันทึกผล PCSO โดยไม่แทนหมวดที่ไม่มีผลด้วยตัวเลข งวดแต่ละงวดมีวันที่และเวลาท้องถิ่นของตัวเอง',
    'not_official_notice' => 'หน้านี้แสดงผลที่ระบบนี้บันทึกไว้พร้อมแหล่งที่มา ไม่ใช่ประกาศอย่างเป็นทางการของหน่วยงานรัฐ และความน่าเชื่อถือของแต่ละผลขึ้นอยู่กับสถานะแหล่งที่มา',
    'skip_to_content' => 'ข้ามไปยังเนื้อหา',
    'latest_link' => 'ผลล่าสุด',
    'history_link' => 'ประวัติผลรางวัล',
    'buy_title' => 'ซื้อ / เลือกสลาก',
    'back_to_results' => 'กลับไปยังผลหวย PCSO',

    'current_result_heading' => 'ผลล่าสุดที่เผยแพร่',
    'recent_draws_heading' => 'งวดล่าสุด',
    'history_heading' => 'ประวัติผลรางวัล',
    'detail_heading' => 'รายละเอียดงวด',
    'provenance_heading' => 'ที่มาของผลรางวัลนี้',
    'integrity_heading' => 'ความถูกต้องของบันทึก',
    'year_archive_heading' => 'ผลหวย PCSO ประจำปี :year',
    'history_year_label' => 'กำลังแสดงบันทึกผลที่มีอยู่สำหรับปี :year',
    'year_archive_link' => 'เปิดคลังรายปี',

    'field_six_digit' => '6D',
    'field_four_digit' => '4D',
    'field_three_digit' => '3D',
    'field_two_digit' => '2D',
    'field_date' => 'วันที่ออกรางวัล',
    'field_six_digit_hint' => 'ตัวเลข 6 หลัก โดยคงเลขศูนย์นำหน้า',
    'field_four_digit_hint' => 'ตัวเลข 4 หลัก โดยคงเลขศูนย์นำหน้า',
    'field_three_digit_hint' => 'ตัวเลข 3 หลัก โดยคงเลขศูนย์นำหน้า',
    'field_two_digit_hint' => 'ตัวเลข 2 หลัก โดยคงเลขศูนย์นำหน้า',

    'table_caption' => 'ผลหวย PCSO ที่เผยแพร่แล้ว เรียงจากงวดล่าสุด หมวดที่ระบุว่างดออกไม่มีค่าตัวเลขสำหรับงวดนั้น',
    'col_draw_date' => 'วันที่ออกรางวัล',
    'col_draw_time' => 'เวลาออกรางวัล',
    'col_six_digit' => '6D',
    'col_four_digit' => '4D',
    'col_three_digit' => '3D',
    'col_two_digit' => '2D',
    'col_source' => 'แหล่งที่มา',
    'col_detail' => 'รายละเอียด',
    'view_detail' => 'ดูงวดนี้',

    'no_value' => 'ยังไม่เผยแพร่',
    'value_off' => 'งดออก',
    'result_unavailable_badge' => 'ไม่มีผลรางวัลที่เผยแพร่',
    'result_unavailable_explainer' => 'งวดนี้มีอยู่ในบันทึกแต่ไม่มีการเผยแพร่ตัวเลข ระบบจะไม่แทนค่าที่หายไปด้วยข้อมูลอื่น',

    'date_buddhist_label' => 'ปีพุทธศักราช',
    'date_gregorian_label' => 'วันที่คริสต์ศักราช',
    'date_timezone_label' => 'เขตเวลาของงวด',
    'published_at_label' => 'เผยแพร่เมื่อ',

    'year_nav_heading' => 'ดูตามปี',
    'year_nav_empty' => 'ยังไม่มีปีที่มีผลรางวัลเผยแพร่',
    'year_draw_count' => ':count งวด',

    'search_heading' => 'ค้นหาผลรางวัล',
    'search_intro' => 'เลือกหมวดหรือวันที่ออกรางวัล แล้วกรอกค่าที่ตรงกันทุกตัวอักษร ระบบจะคงเลขศูนย์นำหน้าไว้',
    'search_type_label' => 'ค้นหาจาก',
    'search_term_label' => 'ค่าที่ค้นหา',
    'search_term_placeholder' => 'ค่าที่ตรงกันทุกตัวอักษร',
    'search_submit' => 'ค้นหา',
    'search_reset' => 'ล้าง',
    'search_results_heading' => 'ผลการค้นหา',
    'search_matched_in' => 'ตรงกับ',
    'search_hint' => 'การค้นหาเป็นแบบตรงทุกตัวอักษร: 09 จะพบเฉพาะ 09 เท่านั้น',
    'search_type_missing' => 'กรุณาเลือกสิ่งที่ต้องการค้นหาก่อนค้นหา',
    'search_type' => [
        '6d' => '6D',
        '4d' => '4D',
        '3d' => '3D',
        '2d' => '2D',
        'date' => 'วันที่ออกรางวัล',
    ],

    'pagination_previous' => 'ก่อนหน้า',
    'pagination_next' => 'ถัดไป',
    'pagination_status' => 'หน้า :page จาก :last',
    'pagination_total' => ':total งวด',

    'provenance' => [
        'provider' => 'ผู้ให้ข้อมูล',
        'source_state' => 'สถานะแหล่งที่มา',
        'source_identifier' => 'รหัสอ้างอิงแหล่งที่มา',
        'source_host' => 'โฮสต์ของแหล่งที่มา',
        'payload_fingerprint' => 'ลายนิ้วมือข้อมูลดิบ',
        'normalized_fingerprint' => 'ลายนิ้วมือค่าที่จัดรูปแล้ว',
        'parser_version' => 'เวอร์ชันตัวอ่านข้อมูล',
        'retrieved_at' => 'ดึงข้อมูลเมื่อ',
        'imported_at' => 'นำเข้าเมื่อ',
        'result_version' => 'เวอร์ชันของผล',
        'supersedes' => 'แทนที่เวอร์ชัน',
        'none' => 'ไม่ได้บันทึกไว้',
        'explainer' => 'ลายนิ้วมือเป็นแฮชทางเดียวของข้อมูลที่ได้รับและค่ามาตรฐาน ใช้จับคู่บันทึกได้โดยไม่ต้องเผยแพร่ข้อมูลต้นทางซ้ำ',
    ],

    'integrity' => [
        'status' => 'การตรวจสอบความถูกต้อง',
        'canonical_version' => 'รูปแบบมาตรฐาน',
        'independently_verified' => 'ตรวจซ้ำโดยอิสระ',
        'yes' => 'ใช่',
        'no' => 'ไม่',
        'explainer' => 'ช่องทางนี้บันทึกแฮชที่คำนวณด้วย PHP แฮชแสดงความสอดคล้องภายในเท่านั้น ไม่ใช่ลายเซ็นอิสระหรือการรับรองจากหน่วยงานรัฐ',
    ],
    'integrity_status' => [
        'integrity_hash_only' => 'บันทึกแฮชแล้ว (ไม่มีลายเซ็น)',
        'signature_unsupported' => 'ช่องทางนี้ไม่รองรับลายเซ็น',
        'fingerprint_mismatch' => 'ลายนิ้วมือไม่ตรงกัน',
        'not_verified' => 'ยังไม่ได้ตรวจสอบ',
        'rejected' => 'ถูกปฏิเสธ',
    ],
    'integrity_hint' => [
        'integrity_hash_only' => 'ค่าที่จัดเก็บตรงกับแฮชที่บันทึกตอนนำเข้า แฮชคำนวณโดยแอปพลิเคชันนี้ จึงไม่ใช่หลักฐานการรับรองจากภายนอก',
        'signature_unsupported' => 'มีข้อมูลลายเซ็น แต่ช่องทางนี้ไม่มีตัวตรวจสอบที่ตรวจได้',
        'fingerprint_mismatch' => 'แฮชที่บันทึกไว้ไม่ตรงกับค่าที่จัดเก็บ',
        'not_verified' => 'ไม่มีบันทึกการตรวจสอบสำหรับเวอร์ชันนี้',
        'rejected' => 'บันทึกนี้ถูกปฏิเสธจากการตรวจสอบความถูกต้อง',
    ],

    'source_state' => [
        'official_source_verified' => 'แหล่งข้อมูลทางการ ตรวจสอบแล้ว',
        'official_source_configured' => 'แหล่งข้อมูลทางการ ยังไม่ได้ตรวจสอบ',
        'internal_reconciled' => 'กระทบยอดภายในระบบ',
        'fixture_only' => 'ข้อมูลตัวอย่างสำหรับทดสอบ',
        'not_configured' => 'ยังไม่ได้ตั้งค่าแหล่งข้อมูล',
        'unavailable' => 'ไม่ทราบแหล่งที่มา',
    ],
    'source_state_hint' => [
        'official_source_verified' => 'ได้รับจากผู้ให้ข้อมูลทางการที่ตั้งค่าไว้และผ่านการตรวจสอบเมื่อนำเข้า',
        'official_source_configured' => 'ตั้งค่าผู้ให้ข้อมูลทางการแล้วแต่ยังไม่ได้ยืนยันบันทึกนี้',
        'internal_reconciled' => 'บันทึกและกระทบยอดภายในระบบนี้ ไม่ได้รับจากผู้ให้ข้อมูลทางการ',
        'fixture_only' => 'ข้อมูลตัวอย่างสำหรับพัฒนาและทดสอบ ไม่ใช่ผลรางวัลจริง',
        'not_configured' => 'ยังไม่มีผู้ให้ข้อมูลสำหรับช่องทางนี้ จึงไม่สามารถอ้างแหล่งที่มาได้',
        'unavailable' => 'ไม่สามารถระบุแหล่งที่มาของบันทึกนี้ได้',
    ],

    'status' => [
        'result_found' => 'พบผลรางวัล',
        'result_unavailable' => 'งวดนี้มีอยู่ในบันทึกแต่ไม่มีการเผยแพร่ตัวเลข',
        'result_not_found' => 'ไม่พบผลรางวัลที่เผยแพร่ตรงกับคำขอ',
        'no_public_data' => 'ยังไม่มีผลรางวัลที่เผยแพร่ให้แสดง',
        'invalid_query' => 'ไม่สามารถอ่านคำขอนี้ได้ กรุณาตรวจสอบค่าแล้วลองใหม่',
        'unavailable' => 'ขณะนี้ยังไม่สามารถแสดงผลรางวัลได้',
    ],
    'empty_current' => 'ยังไม่มีการเผยแพร่ผลหวย PCSO',
    'empty_year' => 'ไม่มีผลรางวัลที่เผยแพร่สำหรับปีนี้',
    'empty_search' => 'ไม่พบงวดที่เผยแพร่ตรงกับการค้นหา',
    'correction_notice' => 'งวดนี้มีการแก้ไข คุณกำลังอ่านเวอร์ชัน :version และเวอร์ชันก่อนหน้ายังคงอยู่ในบันทึก',
    'conflict_notice' => 'ข้อมูลชุดใหม่ขัดแย้งและอยู่ระหว่างระงับเพื่อแก้ไข ผลที่เผยแพร่ก่อนหน้ายังคงแสดงอยู่',

    'purchase_intro' => 'ช่องทางผลรางวัลพร้อมใช้งาน แต่การซื้อถูกปิดไว้จนกว่าจะตรวจสอบสัญญาการซื้อ PCSO ได้ครบถ้วน',
    'purchase_status_heading' => 'สถานะการซื้อ',
    'purchase_not_configured_explainer' => 'ปิดการเลือกและซื้อสลาก PCSO เนื่องจากยังไม่ได้ตรวจสอบเส้นทางผลิตภัณฑ์ งวด การตรวจสอบ ราคา เกมอย่างรับผิดชอบ กระเป๋าเงิน การสำรอง การออกสลาก บัญชีแยกประเภท และ idempotency ครบถ้วน',
    'purchase_contract_heading' => 'สัญญาการซื้อที่ต้องมี',
    'purchase_contract_intro' => 'หน้านี้จะไม่รับหมายเลข แสดงราคา หรือส่งคำสั่งซื้อระหว่างที่ขั้นตอนใดยังไม่ได้ตรวจสอบ',
    'purchase_no_action_notice' => 'หน้านี้ไม่ได้สร้างสลาก สต็อก ราคา การหักเงิน การกันสลาก หรือการจองใด ๆ',
];
```

### FILE: `lang/en/glo_l6.php`
# TYPE: English localization
# PURPOSE: English GLO L6 home labels, source states, result states, schedule, live and purchase safety copy.

```php
<?php

declare(strict_types=1);

return [
    'meta_title' => 'GLO L6 Home',
    'meta_description' => 'GLO L6 public result, next-draw, prize and ticket-checking information from the canonical GLO service architecture.',
    'skip_to_content' => 'Skip to content',
    'product_label' => 'GLO L6',
    'heading' => 'GLO L6',
    'intro' => 'A dedicated public home for GLO L6 results and published product information.',
    'provenance_notice' => 'Result and status cards are populated from the canonical GLO public services. This page does not treat hardcoded legacy page data as a result source.',
    'hero_kicker' => 'Dedicated product lane',
    'hero_title' => 'GLO L6 public information',
    'hero_copy' => 'See the current published result, the next draw when scheduled, prize information and the existing public ticket checker in one focused surface.',
    'product_parameter_label' => 'Configured ticket parameter',
    'digits_label' => ':digits digits',
    'product_not_configured' => 'L6 product parameters are not configured.',
    'overview_heading' => 'L6 overview',
    'latest_result_label' => 'Canonical public result',
    'result_heading' => 'Latest result',
    'source_state' => [
        'official_source_verified' => 'Official source, verified',
        'internal_reconciled' => 'Internally reconciled',
        'fixture_only' => 'Fixture data',
        'not_configured' => 'Not configured',
        'unavailable' => 'Unavailable',
    ],
    'draw_number_label' => 'Draw',
    'first_prize_label' => 'First prize',
    'front_three_label' => 'Front 3',
    'last_three_label' => 'Last 3',
    'last_two_label' => 'Last 2',
    'adjacent_label' => 'Adjacent first',
    'not_published' => 'Not published',
    'result_version_label' => 'Result version',
    'not_recorded' => 'Not recorded',
    'no_result' => 'No published GLO L6 result is available.',
    'next_draw_label' => 'Schedule',
    'next_draw_heading' => 'Next draw',
    'timezone_label' => 'Timezone',
    'next_draw_unavailable' => 'No scheduled next draw is available.',
    'supporting_heading' => 'Prize and live status',
    'prize_label' => 'Prize catalogue',
    'prize_heading' => 'Prize highlight',
    'prize_unavailable' => 'No current prize summary is available.',
    'live_label' => 'Broadcast status',
    'live_heading' => 'Live or replay',
    'live_available' => 'A configured public live or replay link is available.',
    'open_live' => 'Open live or replay',
    'live_unavailable' => 'Live or replay is not configured for public display.',
    'purchase_label' => 'Purchase',
    'purchase_heading' => 'Ticket selection and purchase',
    'purchase_not_configured_explainer' => 'Purchase is disabled until the complete product, draw, selection, responsible-gaming, wallet, reservation, ticket, ledger and idempotency path is verified end to end.',
    'purchase_disabled' => 'Purchase unavailable',
    'actions_heading' => 'Public tools',
    'check_ticket' => 'Check a ticket',
    'public_results_api' => 'Open public results API',
];
```

### FILE: `lang/th/glo_l6.php`
# TYPE: Thai localization
# PURPOSE: Thai GLO L6 localization with exact key parity against the English file.

```php
<?php

declare(strict_types=1);

return [
    'meta_title' => 'หน้า GLO L6',
    'meta_description' => 'ข้อมูลผลรางวัล งวดถัดไป รางวัล และการตรวจสลาก GLO L6 จากสถาปัตยกรรมบริการ GLO สาธารณะมาตรฐาน',
    'skip_to_content' => 'ข้ามไปยังเนื้อหา',
    'product_label' => 'GLO L6',
    'heading' => 'GLO L6',
    'intro' => 'หน้าเฉพาะสำหรับผลรางวัลและข้อมูลผลิตภัณฑ์ GLO L6 ที่เผยแพร่แล้ว',
    'provenance_notice' => 'การ์ดผลรางวัลและสถานะมาจากบริการ GLO สาธารณะมาตรฐาน หน้านี้ไม่ใช้ข้อมูลฮาร์ดโค้ดจากหน้าเก่าเป็นแหล่งผลรางวัล',
    'hero_kicker' => 'ช่องทางผลิตภัณฑ์เฉพาะ',
    'hero_title' => 'ข้อมูลสาธารณะ GLO L6',
    'hero_copy' => 'ดูผลล่าสุดที่เผยแพร่ งวดถัดไปเมื่อมีการกำหนด ข้อมูลรางวัล และเครื่องมือตรวจสลากสาธารณะที่มีอยู่ในหน้าเดียว',
    'product_parameter_label' => 'พารามิเตอร์สลากที่ตั้งค่าไว้',
    'digits_label' => ':digits หลัก',
    'product_not_configured' => 'ยังไม่ได้ตั้งค่าพารามิเตอร์ผลิตภัณฑ์ L6',
    'overview_heading' => 'ภาพรวม L6',
    'latest_result_label' => 'ผลสาธารณะมาตรฐาน',
    'result_heading' => 'ผลล่าสุด',
    'source_state' => [
        'official_source_verified' => 'แหล่งข้อมูลทางการ ตรวจสอบแล้ว',
        'internal_reconciled' => 'กระทบยอดภายในระบบ',
        'fixture_only' => 'ข้อมูลตัวอย่าง',
        'not_configured' => 'ยังไม่ได้ตั้งค่า',
        'unavailable' => 'ไม่พร้อมใช้งาน',
    ],
    'draw_number_label' => 'งวด',
    'first_prize_label' => 'รางวัลที่หนึ่ง',
    'front_three_label' => 'เลขหน้า 3 ตัว',
    'last_three_label' => 'เลขท้าย 3 ตัว',
    'last_two_label' => 'เลขท้าย 2 ตัว',
    'adjacent_label' => 'รางวัลข้างเคียง',
    'not_published' => 'ยังไม่เผยแพร่',
    'result_version_label' => 'เวอร์ชันผลรางวัล',
    'not_recorded' => 'ไม่ได้บันทึกไว้',
    'no_result' => 'ยังไม่มีผล GLO L6 ที่เผยแพร่ให้แสดง',
    'next_draw_label' => 'กำหนดการ',
    'next_draw_heading' => 'งวดถัดไป',
    'timezone_label' => 'เขตเวลา',
    'next_draw_unavailable' => 'ยังไม่มีงวดถัดไปที่กำหนดไว้ให้แสดง',
    'supporting_heading' => 'สถานะรางวัลและถ่ายทอดสด',
    'prize_label' => 'ตารางรางวัล',
    'prize_heading' => 'ไฮไลต์รางวัล',
    'prize_unavailable' => 'ยังไม่มีสรุปรางวัลปัจจุบันให้แสดง',
    'live_label' => 'สถานะการถ่ายทอด',
    'live_heading' => 'ถ่ายทอดสดหรือย้อนหลัง',
    'live_available' => 'มีลิงก์ถ่ายทอดสดหรือย้อนหลังสาธารณะที่ตั้งค่าไว้',
    'open_live' => 'เปิดถ่ายทอดสดหรือย้อนหลัง',
    'live_unavailable' => 'ยังไม่ได้ตั้งค่าการถ่ายทอดสดหรือย้อนหลังสำหรับสาธารณะ',
    'purchase_label' => 'การซื้อ',
    'purchase_heading' => 'การเลือกและซื้อสลาก',
    'purchase_not_configured_explainer' => 'ปิดการซื้อจนกว่าจะตรวจสอบเส้นทางผลิตภัณฑ์ งวด การเลือก เกมอย่างรับผิดชอบ กระเป๋าเงิน การสำรอง สลาก บัญชีแยกประเภท และ idempotency ครบถ้วน',
    'purchase_disabled' => 'การซื้อไม่พร้อมใช้งาน',
    'actions_heading' => 'เครื่องมือสาธารณะ',
    'check_ticket' => 'ตรวจสลาก',
    'public_results_api' => 'เปิด API ผลสาธารณะ',
];
```

### FILE: `tests/Feature/Pages35To44/IndependentPublicPagesTest.php`
# TYPE: PHP feature test
# PURPOSE: Route-order, fail-closed purchase, dedicated GLO L6 and no-new-home-API coverage for Pages 35–44.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Pages35To44;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Route and fail-closed coverage for the independently addressable Pages
 * 35–44 surface. Database-backed result assertions remain in the lane tests;
 * this suite protects page identity, route ordering and purchase safety.
 */
final class IndependentPublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_pcso_page_routes_are_named_before_the_compatibility_wildcard(): void
    {
        $routes = app('router')->getRoutes();

        $this->assertSame('pcso-lottery.search', $routes->match(Request::create('/pcso-lottery/search', 'GET'))->getName());
        $this->assertSame('pcso-lottery.year', $routes->match(Request::create('/pcso-lottery/year/2026', 'GET'))->getName());
        $this->assertSame('pcso-lottery.archive', $routes->match(Request::create('/pcso-lottery/archive/2026', 'GET'))->getName());
        $this->assertSame('pcso-lottery.draw', $routes->match(Request::create('/pcso-lottery/draw/PCSO-20260930-1400', 'GET'))->getName());
        $this->assertSame('pcso-lottery.result', $routes->match(Request::create('/pcso-lottery/result/PCSO-20260930-1400', 'GET'))->getName());
    }

    public function test_pcso_purchase_page_is_fail_closed(): void
    {
        $this->get(route('pcso-lottery.buy'))
            ->assertOk()
            ->assertSee('NOT_CONFIGURED', false)
            ->assertSee('disabled', false)
            ->assertDontSee('Add to PCSO Bet Slip', false);
    }

    public function test_dedicated_glo_l6_home_is_not_the_legacy_results_route(): void
    {
        $this->get(route('glo-l6.index'))
            ->assertOk()
            ->assertSee('GLO L6', false)
            ->assertSee('Purchase unavailable', false)
            ->assertDontSee('482963', false);
    }

    public function test_glo_l6_home_has_no_new_home_api_route(): void
    {
        $routes = app('router')->getRoutes();

        $this->assertSame('glo-l6.index', $routes->match(Request::create('/glo-l6', 'GET'))->getName());
        $this->assertNull($routes->getByName('api.v1.glo-l6.home'));
    }
}
```
