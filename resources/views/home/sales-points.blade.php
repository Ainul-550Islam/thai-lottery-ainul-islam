@extends('layouts.app')

@section('title', $home['text']['footer_sales'] ?? 'Sales Points')

@section('content')
    <div class="home-page">
        <h1 class="home-page__title">{{ $home['text']['sales_title'] ?? 'Sales points' }}</h1>
        <p class="home-muted">{{ $home['text']['sales_help'] ?? 'Find published GLO sales points near you.' }}</p>

        @if (!empty($error))
            <p class="home-error" role="alert">{{ $error }}</p>
        @endif

        <form class="home-check-form" method="GET" action="{{ route('sales-points') }}" role="search" aria-label="Sales point search">
            <div class="home-field">
                <label for="sp-q">Name or address</label>
                <input id="sp-q" name="q" type="search" value="{{ $filters['q'] ?? '' }}" class="home-input" maxlength="120">
            </div>
            <div class="home-field">
                <label for="sp-province">Province</label>
                <input id="sp-province" name="province" type="text" value="{{ $filters['province'] ?? '' }}" class="home-input" maxlength="80">
            </div>
            <button type="submit" class="home-btn home-btn--primary">Search</button>
        </form>

        <section class="home-card" aria-labelledby="sp-results-title">
            <div class="home-card__head">
                <h2 id="sp-results-title">Results</h2>
                @if ($page instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    <span class="home-badge home-badge--VERIFIED">{{ $page->total() }} found</span>
                @endif
            </div>

            @if (! ($page instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator))
                <p class="home-empty">Sales point list unavailable.</p>
            @elseif ($page->isEmpty())
                <p class="home-empty" role="status">No sales points match this search.</p>
            @else
                <ul class="home-sales-list" role="list">
                    @foreach ($page as $point)
                        <li class="home-sales-point">
                            <h3>{{ $point['display_name'] ?? ($point['name'] ?? 'Sales point') }}</h3>
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
                    <nav class="home-pagination" aria-label="Sales point pages">
                        @if ($page->onFirstPage())
                            <span aria-disabled="true">Previous</span>
                        @else
                            <a href="{{ $page->previousPageUrl() }}" rel="prev">Previous</a>
                        @endif
                        <span>Page {{ $page->currentPage() }} of {{ $page->lastPage() }}</span>
                        @if ($page->hasMorePages())
                            <a href="{{ $page->nextPageUrl() }}" rel="next">Next</a>
                        @else
                            <span aria-disabled="true">Next</span>
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
