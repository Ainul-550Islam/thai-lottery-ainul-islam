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
    {{--
        PCSO Lottery results — landing, year and search surface
        (PROMPT 9, file 18).

        ONE TEMPLATE, THREE STATES. The controller decides which of 'current',
        'history' and 'search' is populated; this file renders whichever it
        receives. Three near-identical templates would be three places for the
        source badge or the zero padding to drift.

        NO BUSINESS LOGIC LIVES HERE. No date arithmetic (the projection
        carries both calendars), no source reasoning (the badge component
        renders a state the service already clamped), no query. The inline PHP
        blocks below only unpack arrays; the word is spelled out rather than
        written as a Blade directive, because a directive name inside a Blade
        comment is still seen by the raw-block scanner and swallows the real
        block that follows it.

        EVERY VALUE IS ESCAPED. This lane uses no unescaped raw-echo tag
        anywhere, and the JavaScript module reads data-* attributes instead of
        writing markup.
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
        $searchType = $search['query']['type'] ?? null;
        $searchTerm = (string) ($search['query']['term'] ?? '');
    @endphp

    <div class="wl-page" data-wl-page="pcso-lottery" data-wl-locale="{{ $meta['lang'] }}">
        <a class="wl-skip-link" href="#wl-main">{{ trans('pcso_lottery.skip_to_content') }}</a>

        <main id="wl-main" class="wl-main" tabindex="-1">
            <header class="wl-header">
                <h1 class="wl-title">{{ trans('pcso_lottery.heading') }}</h1>
                <p class="wl-lead">{{ trans('pcso_lottery.intro') }}</p>
                {{-- Stated once, prominently, on every state of this page.
                     The lane presents provenance; it does not claim official
                     government status. --}}
                <p class="wl-disclaimer">{{ trans('pcso_lottery.not_official_notice') }}</p>
            </header>

            <x-pcso-lottery.search-form
                :action="$routes['search']"
                :types="$search_types"
                :max-length="$max_search_length"
                :type="$searchType"
                :term="$searchTerm"
            />

            @if ($search !== null)
                <section class="wl-section wl-section--search" aria-labelledby="wl-search-results-heading">
                    <h2 class="wl-section__heading" id="wl-search-results-heading">
                        {{ trans('pcso_lottery.search_results_heading') }}
                    </h2>

                    @if (($search['status'] ?? '') !== 'RESULT_FOUND')
                        <p class="wl-empty" role="status">
                            {{ trans('pcso_lottery.status.'.strtolower((string) ($search['status'] ?? 'unavailable'))) }}
                        </p>
                    @else
                        @if (($search['matched_field'] ?? null) !== null)
                            <p class="wl-search__matched">
                                {{ trans('pcso_lottery.search_matched_in') }}:
                                <span class="wl-chip">
                                    {{ ($search['query']['type'] ?? '') === 'date'
                                        ? trans('pcso_lottery.field_date')
                                        : trans('pcso_lottery.field_'.$search['matched_field']) }}
                                </span>
                            </p>
                        @endif

                        <x-pcso-lottery.result-table
                            :rows="$search['matches']"
                            :is-thai="$is_thai"
                            :pagination="$search['pagination']"
                        />
                    @endif
                </section>
            @endif

            @if ($current !== null)
                <section class="wl-section wl-section--current" aria-labelledby="wl-current-heading">
                    <h2 class="wl-section__heading" id="wl-current-heading">
                        {{ trans('pcso_lottery.current_result_heading') }}
                    </h2>

                    @if (($current['available'] ?? false) !== true)
                        {{-- Honest empty state. No invented draw, no fixture
                             standing in for a missing official result. --}}
                        <p class="wl-empty" role="status">{{ trans('pcso_lottery.empty_current') }}</p>
                    @else
                        <x-pcso-lottery.result-card
                            :result="$current"
                            :is-thai="$is_thai"
                            :show-link="true"
                        />

                        <x-pcso-lottery.source-status
                            :provenance="$current['provenance']"
                            :integrity="$current['integrity']"
                        />
                    @endif
                </section>
            @endif

            @if ($recent !== [])
                <section class="wl-section wl-section--recent" aria-labelledby="wl-recent-heading">
                    <h2 class="wl-section__heading" id="wl-recent-heading">
                        {{ trans('pcso_lottery.recent_draws_heading') }}
                    </h2>

                    <x-pcso-lottery.result-table :rows="$recent" :is-thai="$is_thai" />
                </section>
            @endif

            @if ($history !== null)
                <section class="wl-section wl-section--history" aria-labelledby="wl-history-heading">
                    <h2 class="wl-section__heading" id="wl-history-heading">
                        {{ trans('pcso_lottery.history_heading') }}
                    </h2>

                    @if (($history['status'] ?? '') !== 'RESULT_FOUND')
                        <p class="wl-empty" role="status">
                            {{ ($history['status'] ?? '') === 'NO_PUBLIC_DATA'
                                ? trans('pcso_lottery.empty_year')
                                : trans('pcso_lottery.status.'.strtolower((string) ($history['status'] ?? 'unavailable'))) }}
                        </p>
                    @else
                        <x-pcso-lottery.result-table
                            :rows="$history['rows']"
                            :is-thai="$is_thai"
                            :pagination="$history['pagination']"
                            pagination-route="pcso-lottery.year"
                            :pagination-params="['year' => $history['year']['gregorian'] ?? $active_year]"
                        />
                    @endif
                </section>
            @endif

            <x-pcso-lottery.year-nav
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
    @vite(['resources/js/pcso-lottery.js'])
@endpush
