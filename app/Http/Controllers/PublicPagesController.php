<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PublicPages\PublicPageDataService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Public informational/legal pages: /about, /vision, /terms.
 *
 * Anonymous by design. SEO meta is injected via layout yields
 * (meta_description / meta_canonical / meta_og_*). No business rules live in
 * Blade — controllers only compose data from PublicPageDataService.
 */
final class PublicPagesController
{
    public function __construct(private readonly PublicPageDataService $pages)
    {
    }

    public function about(Request $request): View
    {
        $locale = $this->locale($request);
        $meta = $this->pages->meta('about', $locale);
        $data = $this->pages->about($locale);

        return view('about.index', $this->bind($data, $meta));
    }

    public function vision(Request $request): View
    {
        $locale = $this->locale($request);
        $meta = $this->pages->meta('vision', $locale);
        $data = $this->pages->vision($locale);

        return view('vision.index', $this->bind($data, $meta));
    }

    public function terms(Request $request): View
    {
        $locale = $this->locale($request);
        $meta = $this->pages->meta('terms', $locale);
        $data = $this->pages->terms($locale);

        return view('terms.index', $this->bind($data, $meta));
    }

    private function locale(Request $request): string
    {
        // Site runs the configured default locale; no client-controlled locale
        // switching exists (intentionally — parity is tested at file level).
        unset($request);

        return (string) app()->getLocale();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $meta
     * @return array<string, mixed>
     */
    private function bind(array $data, array $meta): array
    {
        return [
            'page' => $data + $meta,
            'meta' => $meta,
        ];
    }
}
