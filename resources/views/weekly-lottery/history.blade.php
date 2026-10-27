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
    @vite(['resources/css/weekly-lottery.css'])
@endpush

@section('content')
@php
    $historyPayload = is_array($history ?? null) ? $history : [];
    $rows = is_array($historyPayload['rows'] ?? null) ? $historyPayload['rows'] : [];
    $pagination = is_array($historyPayload['pagination'] ?? null) ? $historyPayload['pagination'] : null;
    $historyStatus = strtolower((string) ($historyPayload['status'] ?? 'no_public_data'));
    $year = $active_year !== null ? (int) $active_year : null;
@endphp
<div class="wl-page" data-wl-page="weekly-lottery-history" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('weekly_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('weekly_lottery.history_heading') }}</p>
            <h1 class="wl-title">{{ trans('weekly_lottery.history_heading') }}</h1>
            <p class="wl-disclaimer">{{ trans('weekly_lottery.not_official_notice') }}</p>
        </header>

        @if ($historyPayload === [] || $historyStatus !== 'result_found')
            <section class="wl-section wl-section--empty" role="status">
                <p class="wl-empty">{{ trans('weekly_lottery.status.'.$historyStatus) }}</p>
            </section>
        @else
            <section class="wl-section" aria-labelledby="weekly-history-heading">
                <h2 id="weekly-history-heading" class="wl-section__heading">
                    {{ trans('weekly_lottery.history_heading') }}@if ($year !== null) — {{ $year }}@endif
                </h2>
                <x-weekly-lottery.result-table :rows="$rows" :is-thai="$is_thai" />
                @if ($pagination !== null && (int) ($pagination['last_page'] ?? 1) > 1 && $year !== null)
                    <nav class="wl-pagination" aria-label="{{ trans('weekly_lottery.history_heading') }}">
                        @if ((bool) ($pagination['has_previous'] ?? false))
                            <a class="wl-pagination__link" rel="prev" href="{{ route('weekly-lottery.history', ['page' => (int) $pagination['page'] - 1]) }}">{{ trans('weekly_lottery.pagination_previous') }}</a>
                        @endif
                        <span class="wl-pagination__status">{{ trans('weekly_lottery.pagination_status', ['page' => (int) ($pagination['page'] ?? 1), 'last' => (int) ($pagination['last_page'] ?? 1)]) }}</span>
                        @if ((bool) ($pagination['has_next'] ?? false))
                            <a class="wl-pagination__link" rel="next" href="{{ route('weekly-lottery.history', ['page' => (int) $pagination['page'] + 1]) }}">{{ trans('weekly_lottery.pagination_next') }}</a>
                        @endif
                    </nav>
                @endif
            </section>
        @endif

        <x-weekly-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/weekly-lottery.js'])
@endpush
