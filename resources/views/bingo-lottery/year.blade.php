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
    $historyPayload = is_array($history ?? null) ? $history : [];
    $rows = is_array($historyPayload['rows'] ?? null) ? $historyPayload['rows'] : [];
    $pagination = is_array($historyPayload['pagination'] ?? null) ? $historyPayload['pagination'] : null;
    $historyStatus = strtolower((string) ($historyPayload['status'] ?? 'no_public_data'));
    $year = $active_year !== null ? (int) $active_year : null;
@endphp
<div class="wl-page" data-wl-page="bingo-lottery-year" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('bingo_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('bingo_lottery.history_heading') }}</p>
            <h1 class="wl-title">{{ trans('bingo_lottery.history_heading') }}@if ($year !== null) — {{ $year }}@endif</h1>
            <p class="wl-disclaimer">{{ trans('bingo_lottery.not_official_notice') }}</p>
        </header>
        @if ($historyPayload === [] || $historyStatus !== 'result_found')
            <section class="wl-section wl-section--empty" role="status">
                <p class="wl-empty">{{ trans('bingo_lottery.status.'.$historyStatus) }}</p>
            </section>
        @else
            <section class="wl-section" aria-labelledby="mega-year-heading">
                <h2 id="mega-year-heading" class="wl-section__heading">{{ trans('bingo_lottery.history_heading') }}</h2>
                <x-bingo-lottery.result-table :rows="$rows" :is-thai="$is_thai" :pagination="$pagination" pagination-route="bingo-lottery.year" :pagination-params="['year' => $year]" />
            </section>
        @endif
        <x-bingo-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/bingo-lottery.js'])
@endpush
