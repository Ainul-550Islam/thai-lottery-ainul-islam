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
    @vite(['resources/css/national-lottery.css'])
@endpush

@section('content')
    {{--
        National Lottery results — landing, year and search surface
        (PROMPT 5, file 18).

        ONE TEMPLATE, THREE STATES. The controller decides which of
        'current', 'history' and 'search' is populated; this file renders
        whichever it receives. Three near-identical templates would be three
        places for the source badge or the zero padding to drift.

        NO BUSINESS LOGIC LIVES HERE. No date arithmetic (the projection
        carries both calendars), no source reasoning (the badge component
        renders a state the service already clamped), no query, no prize
        calculation. The inline PHP blocks below only unpack arrays; the
        word is spelled out rather than written as a Blade directive,
        because a directive name inside a Blade comment is still seen by the
        raw-block scanner and swallows the real block that follows it.

        EVERY VALUE IS ESCAPED. This lane uses no unescaped raw-echo tag
        anywhere, and the JavaScript module reads data-* attributes instead
        of writing markup.
    --}}

    @php
        $current = is_array($current ?? null) ? $current : null;
        $history = is_array($history ?? null) ? $history : null;
        $search = is_array($search ?? null) ? $search : null;
        $recent = is_array($recent ?? null) ? $recent : [];
        $years = is_array($years ?? null) ? $years : [];

        // Repopulate the form from the query the SERVER parsed, not from raw
        // input. A term that failed validation comes back empty rather than
        // being echoed into the field.
        $searchType = (string) ($search['query']['type'] ?? '');
        $searchTerm = (string) ($search['query']['term'] ?? '');
        $searchNumber = $searchType === 'number' ? $searchTerm : '';
        $searchDate = $searchType === 'date' ? $searchTerm : '';
        $searchField = $search['query']['field'] ?? null;
    @endphp

    <div class="nl-page" data-nl-page="national-lottery" data-nl-locale="{{ $meta['lang'] }}">
        <a class="nl-skip-link" href="#nl-main">{{ trans('national_lottery.skip_to_content') }}</a>

        <main id="nl-main" class="nl-main" tabindex="-1">
            <header class="nl-header">
                <h1 class="nl-title">{{ trans('national_lottery.heading') }}</h1>
                <p class="nl-lead">{{ trans('national_lottery.intro') }}</p>
                {{-- Stated once, prominently, on every state of this page.
                     The lane presents provenance; it does not claim official
                     government status. --}}
                <p class="nl-disclaimer">{{ trans('national_lottery.not_official_notice') }}</p>
            </header>

            <x-national-lottery.search-form
                :action="$routes['search']"
                :fields="$searchable_fields"
                :max-length="$max_search_length"
                :number="$searchNumber"
                :date="$searchDate"
                :field="$searchField"
            />

            @if ($search !== null)
                <section class="nl-section nl-section--search" aria-labelledby="nl-search-results-heading">
                    <h2 class="nl-section__heading" id="nl-search-results-heading">
                        {{ trans('national_lottery.search_results_heading') }}
                    </h2>

                    @if (($search['status'] ?? '') !== 'RESULT_FOUND')
                        <p class="nl-empty" role="status">
                            {{ trans('national_lottery.status.'.strtolower((string) ($search['status'] ?? 'unavailable'))) }}
                        </p>
                    @else
                        @if (($search['matched_fields'] ?? []) !== [])
                            <p class="nl-search__matched">
                                {{ trans('national_lottery.search_matched_in') }}:
                                @foreach ($search['matched_fields'] as $matchedField)
                                    <span class="nl-chip">{{ trans('national_lottery.field_'.$matchedField) }}</span>
                                @endforeach
                            </p>
                        @endif

                        <x-national-lottery.result-table
                            :rows="$search['matches']"
                            :is-thai="$is_thai"
                            :pagination="$search['pagination']"
                        />
                    @endif
                </section>
            @endif

            @if ($current !== null)
                <section class="nl-section nl-section--current" aria-labelledby="nl-current-heading">
                    <h2 class="nl-section__heading" id="nl-current-heading">
                        {{ trans('national_lottery.current_result_heading') }}
                    </h2>

                    @if (($current['available'] ?? false) !== true)
                        {{-- Honest empty state. No invented draw, no fixture
                             standing in for a missing official result. --}}
                        <p class="nl-empty" role="status">{{ trans('national_lottery.empty_current') }}</p>
                    @else
                        <x-national-lottery.result-card
                            :result="$current"
                            :is-thai="$is_thai"
                            :show-link="true"
                        />

                        <x-national-lottery.source-status :provenance="$current['provenance']" />
                    @endif
                </section>
            @endif

            @if ($recent !== [])
                <section class="nl-section nl-section--recent" aria-labelledby="nl-recent-heading">
                    <h2 class="nl-section__heading" id="nl-recent-heading">
                        {{ trans('national_lottery.recent_draws_heading') }}
                    </h2>

                    <x-national-lottery.result-table
                        :rows="$recent"
                        :is-thai="$is_thai"
                    />
                </section>
            @endif

            @if ($history !== null)
                <section class="nl-section nl-section--history" aria-labelledby="nl-history-heading">
                    <h2 class="nl-section__heading" id="nl-history-heading">
                        {{ trans('national_lottery.history_heading') }}
                    </h2>

                    @if (($history['status'] ?? '') !== 'RESULT_FOUND')
                        <p class="nl-empty" role="status">
                            {{ ($history['status'] ?? '') === 'NO_PUBLIC_DATA'
                                ? trans('national_lottery.empty_year')
                                : trans('national_lottery.status.'.strtolower((string) ($history['status'] ?? 'unavailable'))) }}
                        </p>
                    @else
                        <x-national-lottery.result-table
                            :rows="$history['rows']"
                            :is-thai="$is_thai"
                            :pagination="$history['pagination']"
                            pagination-route="national-lottery.year"
                            :pagination-params="['year' => $history['year']['gregorian'] ?? $active_year]"
                        />
                    @endif
                </section>
            @endif

            <x-national-lottery.year-nav
                :years="$years"
                :active-year="$active_year"
                :is-thai="$is_thai"
            />
        </main>
    </div>

        {{-- The shared public footer, the same one About, Vision, Terms, Fees,
             Prize Verification and Discounts already carry. Without it a
             visitor who arrived on a lane page from a search engine had no
             way out of it except the browser's back button. --}}
        <x-public-page.footer />

@endsection

@push('scripts')
    @vite(['resources/js/national-lottery.js'])
@endpush
