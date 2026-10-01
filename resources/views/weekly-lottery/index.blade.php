@extends('layouts.app')

@section('title', $meta['title'].'')
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/weekly-lottery.css'])
@endpush

@section('content')
@php
    $projection = is_array($current ?? null) ? $current : [];
    $historyRows = is_array($history['rows'] ?? null) ? $history['rows'] : [];
    $searchMatches = is_array($search['matches'] ?? null) ? $search['matches'] : [];
@endphp
<div class="wl-page" data-wl-page="weekly-lottery" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('weekly_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header"><p class="wl-eyebrow">{{ trans('weekly_lottery.current_result_heading') }}</p><h1 class="wl-title">{{ trans('weekly_lottery.heading') }}</h1><p class="wl-intro">{{ trans('weekly_lottery.intro') }}</p><p class="wl-disclaimer">{{ trans('weekly_lottery.not_official_notice') }}</p><nav class="wl-actions" aria-label="{{ trans('weekly_lottery.heading') }}"><a class="wl-link" href="{{ route('weekly-lottery.latest') }}">{{ trans('weekly_lottery.current_result_heading') }}</a><a class="wl-link" href="{{ route('weekly-lottery.history') }}">{{ trans('weekly_lottery.history_heading') }}</a><a class="wl-link" href="{{ route('weekly-lottery.buy') }}">{{ trans('lottery_hub.buy_title') }}</a></nav></header>

        @if ($projection !== [])<section class="wl-section" aria-labelledby="wl-current-heading"><h2 id="wl-current-heading" class="wl-section__heading">{{ trans('weekly_lottery.current_result_heading') }}</h2><x-weekly-lottery.result-card :result="$projection" :is-thai="$is_thai" /></section>@else<section class="wl-section wl-section--empty" role="status"><p class="wl-empty">{{ trans('weekly_lottery.empty_current') }}</p></section>@endif

        @if ($recent !== [])<section class="wl-section" aria-labelledby="wl-recent-heading"><h2 id="wl-recent-heading" class="wl-section__heading">{{ trans('weekly_lottery.recent_draws_heading') }}</h2><div class="grid gap-4 md:grid-cols-2">@foreach ($recent as $row)<x-weekly-lottery.result-card :result="$row" :is-thai="$is_thai" />@endforeach</div></section>@endif

        <x-weekly-lottery.search-form :action="$routes['search']" :types="$search_types" :max-length="$max_search_length" :type="$search['query']['type'] ?? null" :term="$search['query']['term'] ?? ''" />
        @if ($search !== null)<section class="wl-section" aria-labelledby="wl-search-results"><h2 id="wl-search-results" class="wl-section__heading">{{ trans('weekly_lottery.search_results_heading') }}</h2><p class="wl-empty">{{ trans('weekly_lottery.status.'.strtolower((string) ($search['status'] ?? 'unavailable'))) }}</p><x-weekly-lottery.result-table :rows="$searchMatches" :is-thai="$is_thai" /></section>@endif

        @if ($history !== null)<section class="wl-section" aria-labelledby="wl-history-heading"><h2 id="wl-history-heading" class="wl-section__heading">{{ trans('weekly_lottery.history_heading') }}@if ($active_year !== null) — {{ $active_year }}@endif</h2><x-weekly-lottery.result-table :rows="$historyRows" :is-thai="$is_thai" /><x-weekly-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" /></section>@else<x-weekly-lottery.year-nav :years="$years" :active-year="$active_year ?? null" :is-thai="$is_thai" />@endif
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/weekly-lottery.js'])
@endpush
