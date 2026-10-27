@extends('layouts.app')

@section('title', $home['text']['footer_sales'] ?? __('home.footer_sales'))

@section('content')
    <div class="home-page">
        <h1 class="home-page__title">{{ $home['text']['sales_title'] ?? __('home.sales_title') }}</h1>
        <p class="home-muted">{{ $home['text']['sales_help'] ?? __('home.sales_help') }}</p>

        @if (!empty($error))
            <p class="home-error" role="alert">{{ $error }}</p>
        @endif

        <form class="home-check-form" method="GET" action="{{ route('sales-points') }}" role="search" aria-label="{{ $home['text']['sales_search_label'] ?? __('home.sales_search_label') }}">
            <div class="home-field">
                <label for="sp-q">{{ $home['text']['sales_name_address_label'] ?? __('home.sales_name_address_label') }}</label>
                <input id="sp-q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" class="home-input" maxlength="120">
            </div>
            <div class="home-field">
                <label for="sp-province">{{ $home['text']['sales_province_label'] ?? __('home.sales_province_label') }}</label>
                <input id="sp-province" name="province" type="text" value="{{ $filters['province'] ?? '' }}" class="home-input" maxlength="80">
            </div>
            <button type="submit" class="home-btn home-btn--primary">{{ $home['text']['sales_search_button'] ?? __('home.sales_search_button') }}</button>
        </form>

        <section class="home-card" aria-labelledby="sp-results-title">
            <div class="home-card__head">
                <h2 id="sp-results-title">{{ $home['text']['sales_results_title'] ?? __('home.sales_results_title') }}</h2>
                @if ($page instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    <span class="home-badge home-badge--VERIFIED">{{ trans('home.sales_found_count', ['count' => $page->total()]) }}</span>
                @endif
            </div>

            @if (! ($page instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator))
                <p class="home-empty">{{ $home['text']['sales_unavailable'] ?? __('home.sales_unavailable') }}</p>
            @elseif ($page->isEmpty())
                <p class="home-empty" role="status">{{ $home['text']['sales_no_match'] ?? __('home.sales_no_match') }}</p>
            @else
                <ul class="home-sales-list" role="list">
                    @foreach ($page as $point)
                        <li class="home-sales-point">
                            <h3>{{ $point['display_name'] ?? ($point['name'] ?? ($home['text']['sales_point_default'] ?? __('home.sales_point_default'))) }}</h3>
                            @if (!empty($point['address']))
                                <p>{{ $point['address'] }}</p>
                            @endif
                            <p class="home-muted">
                                @if (!empty($point['province'])) {{ $point['province'] }} @endif
                                @if (!empty($point['distance_km']))
                                    · {{ number_format((float) $point['distance_km'], 1) }} km
                                @endif
                                @if (!empty($point['status']))
                                    · <span class="home-badge home-badge--neutral">{{ $point['status'] }}</span>
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>

                @if ($page->hasPages())
                    <nav class="home-pagination" aria-label="{{ $home['text']['sales_results_title'] ?? __('home.sales_results_title') }}">
                        @if ($page->onFirstPage())
                            <span aria-disabled="true">{{ $home['text']['sales_previous'] ?? __('home.sales_previous') }}</span>
                        @else
                            <a href="{{ $page->previousPageUrl() }}" rel="prev">{{ $home['text']['sales_previous'] ?? __('home.sales_previous') }}</a>
                        @endif
                        <span>{{ trans('home.sales_page', ['current' => $page->currentPage(), 'last' => $page->lastPage()]) }}</span>
                        @if ($page->hasMorePages())
                            <a href="{{ $page->nextPageUrl() }}" rel="next">{{ $home['text']['sales_next'] ?? __('home.sales_next') }}</a>
                        @else
                            <span aria-disabled="true">{{ $home['text']['sales_next'] ?? __('home.sales_next') }}</span>
                        @endif
                    </nav>
                @endif
            @endif
        </section>

        <x-home.footer
            :text="$home['text']"
            :appName="$home['hero']['app_name'] ?? config('app.name')"
        />
    </div>
@endsection
