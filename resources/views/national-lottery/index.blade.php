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

    @vite(['resources/css/national-lottery.css'])
@endpush

@section('content')
@php
    $projection = is_array($current ?? null) ? $current : [];
    $historyRows = is_array($history['rows'] ?? null) ? $history['rows'] : [];
    $searchMatches = is_array($search['matches'] ?? null) ? $search['matches'] : [];
@endphp
<main class="min-h-screen bg-[#0B0904] px-4 pb-20 pt-24 text-white sm:px-6 lg:px-8" data-national-lottery-portal>
    <div class="mx-auto max-w-7xl space-y-10">
        <a class="sr-only focus:not-sr-only" href="#national-main">{{ trans('national_lottery.skip_to_content') }}</a>
        <header id="national-main" class="max-w-4xl space-y-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#D4AF37]">{{ trans('national_lottery.heading') }}</p>
            <h1 class="text-4xl font-black tracking-tight text-[#F5E6B8] sm:text-6xl">{{ trans('national_lottery.heading') }}</h1>
            <p class="text-base leading-8 text-gray-300 sm:text-lg">{{ trans('national_lottery.intro') }}</p>
            <p class="rounded-2xl border border-white/10 bg-white/[0.03] p-4 text-sm leading-7 text-gray-400">{{ trans('national_lottery.not_official_notice') }}</p>
            <div class="flex flex-wrap gap-3"><a class="rounded-xl border border-[#D4AF37]/40 bg-[#221B0E] px-4 py-3 text-sm font-bold text-[#F5E6B8]" href="{{ route('national-lottery.latest') }}">{{ trans('national_lottery.current_result_heading') }}</a><a class="rounded-xl border border-[#D4AF37]/40 bg-[#221B0E] px-4 py-3 text-sm font-bold text-[#F5E6B8]" href="{{ route('national-lottery.history') }}">{{ trans('national_lottery.history_heading') }}</a><a class="rounded-xl bg-gradient-to-r from-[#D4AF37] to-[#AA7C11] px-4 py-3 text-sm font-bold text-[#0B0904]" href="{{ route('national-lottery.buy') }}">{{ trans('lottery_hub.buy_title') }}</a></div>
        </header>

        @if ($projection !== [])
            <section aria-labelledby="national-current-heading"><h2 id="national-current-heading" class="sr-only">{{ trans('national_lottery.current_result_heading') }}</h2><x-national-lottery.result-card :result="$projection" :is-thai="$is_thai" /></section>
        @else
            <section class="rounded-3xl border border-amber-400/30 bg-[#141007] p-8" role="status"><h2 class="text-xl font-bold text-[#F5E6B8]">{{ trans('national_lottery.empty_current') }}</h2></section>
        @endif

        @if ($recent !== [])
            <section class="space-y-4" aria-labelledby="national-recent-heading"><div class="flex items-end justify-between gap-4"><h2 id="national-recent-heading" class="text-2xl font-black text-[#F5E6B8]">{{ trans('national_lottery.recent_draws_heading') }}</h2><a class="text-sm font-semibold text-[#D4AF37]" href="{{ route('national-lottery.history') }}">{{ trans('national_lottery.history_heading') }} →</a></div><div class="grid gap-4 md:grid-cols-2">@foreach ($recent as $row)<x-national-lottery.result-card :result="$row" :is-thai="$is_thai" :show-link="true" />@endforeach</div></section>
        @endif

        <x-national-lottery.search-form :action="$routes['search']" :max-length="$max_search_length" :number="$search['query']['term'] ?? ''" :date="($search['query']['type'] ?? '') === 'date' ? ($search['query']['term'] ?? '') : ''" :field="$search['query']['field'] ?? null" />

        @if ($search !== null)
            <section class="space-y-4" aria-labelledby="national-search-results"><h2 id="national-search-results" class="text-2xl font-black text-[#F5E6B8]">{{ trans('national_lottery.search_results_heading') }}</h2><p class="text-sm text-gray-400">{{ trans('national_lottery.status.'.strtolower((string) ($search['status'] ?? 'unavailable'))) }}</p><x-national-lottery.result-table :rows="$searchMatches" :is-thai="$is_thai" /></section>
        @endif

        @if ($history !== null)
            <section class="space-y-4" aria-labelledby="national-history-heading"><h2 id="national-history-heading" class="text-2xl font-black text-[#F5E6B8]">{{ trans('national_lottery.history_heading') }}@if ($active_year !== null) <span class="text-[#D4AF37]">— {{ $active_year }}</span>@endif</h2><x-national-lottery.result-table :rows="$historyRows" :is-thai="$is_thai" /><x-national-lottery.year-nav :years="$years" :active-year="$active_year" /></section>
        @else
            <x-national-lottery.year-nav :years="$years" :active-year="$active_year ?? null" />
        @endif
    </div>
</main>
@endsection

@push('scripts')
    @vite(['resources/js/national-lottery.js'])
@endpush
