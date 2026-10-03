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
    @vite(['resources/css/bingo-lottery.css'])
@endpush

@section('content')
@php
    $projection = is_array($current ?? null) ? $current : [];
    $searchPayload = is_array($search ?? null) ? $search : null;
    $searchRows = is_array($searchPayload['matches'] ?? null) ? $searchPayload['matches'] : [];

    // LANE PARITY: the controller has always passed `history` for the latest
    // year, and this view never read it. The Mega lane was therefore the only
    // one of the four whose landing page had no results table at all - the
    // visitor saw the current-draw card plus a row of year links, while
    // National, Weekly and PCSO all render the year's table inline.
    $historyPayload = is_array($history ?? null) ? $history : null;
    $historyRows = is_array($historyPayload['rows'] ?? null) ? $historyPayload['rows'] : [];
@endphp
<div class="wl-page" data-wl-page="bingo-lottery" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('bingo_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('bingo_lottery.public_name') }}</p>
            <h1 class="wl-title">{{ trans('bingo_lottery.heading') }}</h1>
            <p class="wl-intro">{{ trans('bingo_lottery.intro') }}</p>
            <p class="wl-disclaimer">{{ trans('bingo_lottery.not_official_notice') }}</p>
            <nav class="wl-actions" aria-label="{{ trans('bingo_lottery.public_name') }}">
                <a class="wl-link" href="{{ route('bingo-lottery.latest') }}">{{ trans('bingo_lottery.latest_link') }}</a>
                <a class="wl-link" href="{{ route('bingo-lottery.history') }}">{{ trans('bingo_lottery.history_link') }}</a>
                <a class="wl-link" href="{{ route('bingo-lottery.buy') }}">{{ trans('bingo_lottery.buy_title') }}</a>
            </nav>
        </header>

        <section class="mega-hero" aria-label="{{ trans('bingo_lottery.public_name') }}">
            <div class="mega-hero__orb" aria-hidden="true">
                <span class="mega-hero__ring mega-hero__ring--one"></span>
                <span class="mega-hero__ring mega-hero__ring--two"></span>
                <span class="mega-hero__orb-label">MEGA</span>
            </div>
            <div class="mega-hero__copy">
                <p class="wl-eyebrow">{{ trans('bingo_lottery.current_result_heading') }}</p>
                <h2>{{ trans('bingo_lottery.public_name') }}</h2>
                <p>{{ trans('bingo_lottery.intro') }}</p>
            </div>
        </section>

        <section class="wl-section" aria-labelledby="mega-current-heading">
            <h2 id="mega-current-heading" class="wl-section__heading">{{ trans('bingo_lottery.current_result_heading') }}</h2>
            <x-bingo-lottery.result-card :result="$projection" :is-thai="$is_thai" />
        </section>

        @if ($recent !== [])
            <section class="wl-section" aria-labelledby="mega-recent-heading">
                <h2 id="mega-recent-heading" class="wl-section__heading">{{ trans('bingo_lottery.recent_draws_heading') }}</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($recent as $row)
                        <x-bingo-lottery.result-card :result="$row" :is-thai="$is_thai" />
                    @endforeach
                </div>
            </section>
        @endif

        @if ($historyPayload !== null && $historyRows !== [])
            <section class="wl-section" aria-labelledby="mega-history-preview-heading">
                <h2 id="mega-history-preview-heading" class="wl-section__heading">{{ trans('bingo_lottery.history_heading') }}</h2>
                <x-bingo-lottery.result-table :rows="$historyRows" :is-thai="$is_thai" />
            </section>
        @endif

        <x-bingo-lottery.search-form
            :action="$routes['search']"
            :types="$search_types"
            :max-length="$max_search_length"
            :type="$searchPayload['query']['type'] ?? null"
            :term="$searchPayload['query']['term'] ?? ''"
        />

        @if ($searchPayload !== null)
            <section class="wl-section" aria-labelledby="mega-search-heading">
                <h2 id="mega-search-heading" class="wl-section__heading">{{ trans('bingo_lottery.search_results_heading') }}</h2>
                <p class="wl-empty" role="status">{{ trans('bingo_lottery.status.'.strtolower((string) ($searchPayload['status'] ?? 'unavailable'))) }}</p>
                <x-bingo-lottery.result-table :rows="$searchRows" :is-thai="$is_thai" />
            </section>
        @endif

        <x-bingo-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/bingo-lottery.js'])
@endpush
