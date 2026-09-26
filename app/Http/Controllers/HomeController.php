<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Home\HomePageDataService;
use App\Services\Lottery\GloPublicResultService;
use App\Services\Lottery\GloSalesPointService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View as ViewAlias;

/**
 * Public Home composition + public ticket check / sales-point UI.
 *
 * GET / is anonymous. All dynamic sections come from HomePageDataService —
 * this controller never scatters unrelated DB queries into Blade.
 */
class HomeController
{
    public function __construct(
        private readonly HomePageDataService $homeData,
        private readonly GloPublicResultService $publicResults,
        private readonly GloSalesPointService $salesPoints,
    ) {}

    public function index(): View
    {
        return view('home', [
            'home' => $this->homeData->pageData(),
        ]);
    }

    /**
     * Dedicated public ticket-check page (UI form — not a raw API link).
     */
    public function checkForm(?string $number = null): View
    {
        $normalised = $this->normaliseTicket($number);

        $result = null;
        $error = null;

        if ($normalised !== null) {
            $result = $this->runCheck($normalised, $error);
        }

        return view('home.check', [
            'home' => $this->homeData->pageData(),
            'number' => $normalised ?? '',
            'result' => $result,
            'error' => $error,
        ]);
    }

    /**
     * POST from the Home "Check Your Ticket" form. Rate-limited, six digits only.
     */
    public function checkSubmit(Request $request): View
    {
        $raw = (string) $request->input('number', '');
        $normalised = $this->normaliseTicket($raw);

        $error = null;
        $result = null;

        if ($normalised === null) {
            $error = 'Enter exactly six digits (leading zeros allowed).';
        } else {
            $key = 'home-check:'.sha1($normalised.'|'.$request->ip());
            if (RateLimiter::tooManyAttempts($key, 20)) {
                $error = 'Too many checks from this address. Please wait a moment and try again.';
                $normalised = null;
            } else {
                RateLimiter::hit($key, 60);
                $result = $this->runCheck($normalised, $error);
            }
        }

        return view('home.check', [
            'home' => $this->homeData->pageData(),
            'number' => $normalised ?? '',
            'result' => $result,
            'error' => $error,
        ]);
    }

    /**
     * Public sales-point search UI (CTA target). Never loads the whole table.
     */
    public function salesPoints(Request $request): View
    {
        $query = [
            'q' => $request->query('q'),
            'province' => $request->query('province'),
            'page' => $request->query('page', 1),
        ];
        $query = array_filter($query, static fn ($v): bool => $v !== null && $v !== '');

        $page = null;
        $error = null;

        try {
            $page = $this->salesPoints->publicSearch($query);
        } catch (\Throwable $e) {
            report($e);
            $error = 'Sales point search is temporarily unavailable.';
        }

        return view('home.sales-points', [
            'home' => $this->homeData->pageData(),
            'page' => $page,
            'error' => $error,
            'filters' => [
                'q' => (string) ($query['q'] ?? ''),
                'province' => (string) ($query['province'] ?? ''),
            ],
        ]);
    }

    /**
     * Public contact / support page (configured data only — no invented phone).
     */
    public function contact(): View
    {
        return view('home.contact', [
            'home' => $this->homeData->pageData(),
        ]);
    }

    private function normaliseTicket(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        $trimmed = trim($raw);

        // Preserve leading zeros; reject anything that is not exactly six digits.
        if (preg_match('/^\d{6}$/', $trimmed) !== 1) {
            return null;
        }

        return $trimmed;
    }

    /**
     * @param  string|null  $error  Out-param error message.
     * @return array<string, mixed>|null
     */
    private function runCheck(string $number, ?string &$error): ?array
    {
        try {
            return $this->publicResults->checkSixDigit($number);
        } catch (\App\Exceptions\GloDealerException $e) {
            $error = $e->getMessage();

            return null;
        } catch (\Throwable $e) {
            report($e);
            $error = 'Result check is temporarily unavailable.';

            return null;
        }
    }
}
