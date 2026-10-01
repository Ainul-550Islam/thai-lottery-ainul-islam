<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Lottery\GloL6HomeService;
use App\Services\Lottery\GloPublicResultService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Http\Request;

/**
 * Dedicated public GLO L6 page controller.
 *
 * This controller is a presentation adapter only. Home, result, archive and
 * detail data come from the existing canonical GLO services. Purchase remains
 * fail-closed behind GloL6PurchaseCapabilityService until the complete L6
 * product-to-draw-to-wallet contract is verified.
 */
final class GloL6Controller
{
    public function __construct(
        private readonly GloL6HomeService $home,
        private readonly GloPublicResultService $results,
    ) {
    }

    public function index(): View
    {
        return view('glo-l6.index', [
            'home' => $this->home->pageData(),
            'meta' => $this->meta('meta_title', 'meta_description', route('glo-l6.index')),
        ]);
    }

    /**
     * Page 45: the dedicated L6 purchase boundary.
     *
     * A selection form is intentionally not enabled when the canonical
     * capability reports NOT_CONFIGURED. Rendering a checkout button here
     * would imply a money contract the backend has not proved.
     */
    public function buy(): View
    {
        $capability = $this->home->pageData()['purchase'];

        return view('glo-l6.buy', [
            'capability' => is_array($capability) ? $capability : [],
            'meta' => $this->meta('buy_meta_title', 'buy_meta_description', route('glo-l6.buy')),
        ]);
    }

    /**
     * Page 46: latest result from GloPublicResultService.
     */
    public function latestResult(): View
    {
        return view('glo-l6.result', [
            'result' => $this->safeResult(),
            'pageHeading' => trans('glo_l6.latest_page_heading'),
            'pageLead' => trans('glo_l6.latest_page_lead'),
            'backRoute' => route('glo-l6.index'),
            'meta' => $this->meta('latest_meta_title', 'latest_meta_description', route('glo-l6.latest')),
        ]);
    }

    /**
     * Page 47: bounded historical result index.
     */
    public function history(Request $request): View
    {
        $query = $request->query();
        $history = $this->safeHistory($query);

        return view('glo-l6.history', [
            'history' => $history,
            'archiveYear' => null,
            'pageHeading' => trans('glo_l6.history_page_heading'),
            'pageLead' => trans('glo_l6.history_page_lead'),
            'meta' => $this->meta('history_meta_title', 'history_meta_description', route('glo-l6.history')),
        ]);
    }

    /**
     * Page 48: one-year archive using the canonical two-year history window.
     */
    public function year(Request $request, int $year): View
    {
        $minimumYear = (int) now()->subYears((int) config('glo.result_experience.history_years', 2))->year;
        $maximumYear = (int) now()->year;

        if ($year < $minimumYear || $year > $maximumYear) {
            abort(404);
        }

        $history = $this->safeHistory([
            'from' => sprintf('%04d-01-01', $year),
            'to' => sprintf('%04d-12-31', $year),
            'page' => $request->integer('page', 1),
        ]);

        return view('glo-l6.history', [
            'history' => $history,
            'archiveYear' => $year,
            'pageHeading' => trans('glo_l6.year_page_heading', ['year' => $year]),
            'pageLead' => trans('glo_l6.year_page_lead'),
            'meta' => $this->meta('year_meta_title', 'year_meta_description', route('glo-l6.year', ['year' => $year])),
        ]);
    }

    /**
     * Page 49: draw detail and Page 50: result detail share the same
     * canonical result projection; the route names remain separate for
     * navigation and audit traceability.
     */
    public function drawDetail(string $draw): View
    {
        return view('glo-l6.result', [
            'result' => $this->safeResult($draw),
            'pageHeading' => trans('glo_l6.draw_page_heading'),
            'pageLead' => trans('glo_l6.draw_page_lead'),
            'backRoute' => route('glo-l6.history'),
            'meta' => $this->meta('draw_meta_title', 'draw_meta_description', route('glo-l6.draw', ['draw' => $draw])),
        ]);
    }

    public function resultDetail(string $draw): View
    {
        return view('glo-l6.result', [
            'result' => $this->safeResult($draw),
            'pageHeading' => trans('glo_l6.result_page_heading'),
            'pageLead' => trans('glo_l6.result_page_lead'),
            'backRoute' => route('glo-l6.history'),
            'meta' => $this->meta('result_meta_title', 'result_meta_description', route('glo-l6.result', ['draw' => $draw])),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function safeResult(?string $draw = null): array
    {
        try {
            $payload = $this->results->cachedResult($draw);

            return is_array($payload) ? $payload : $this->unavailableResult();
        } catch (\Throwable $exception) {
            report($exception);

            return $this->unavailableResult();
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function safeHistory(array $query): LengthAwarePaginator
    {
        try {
            return $this->results->history($query);
        } catch (\Throwable $exception) {
            report($exception);

            $perPage = max(1, min((int) config('glo.result_experience.history_page_size', 20), (int) config('glo.result_experience.max_history_page_size', 50)));

            return new LengthAwarePaginator(
                [],
                0,
                $perPage,
                max(1, (int) ($query['page'] ?? 1)),
                ['path' => Paginator::resolveCurrentPath()],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailableResult(): array
    {
        return [
            'draw_id' => null,
            'draw_number' => null,
            'draw_date' => null,
            'has_result' => false,
            'source_state' => 'UNAVAILABLE',
            'message' => trans('glo_l6.result_unavailable'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function meta(string $titleKey, string $descriptionKey, string $canonical): array
    {
        $title = (string) trans('glo_l6.'.$titleKey);
        $description = (string) trans('glo_l6.'.$descriptionKey);

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'robots' => 'index,follow',
            'og_title' => $title,
            'og_description' => $description,
            'og_type' => 'website',
            'og_url' => $canonical,
        ];
    }
}
