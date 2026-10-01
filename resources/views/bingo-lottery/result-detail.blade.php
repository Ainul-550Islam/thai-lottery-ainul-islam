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
    $available = (bool) ($projection['available'] ?? false);
    $status = strtolower((string) ($projection['status'] ?? 'unavailable'));
@endphp
<div class="wl-page wl-page--detail" data-wl-page="bingo-lottery-result-detail" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('bingo_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('bingo_lottery.detail_heading') }}</p>
            <h1 class="wl-title">{{ trans('bingo_lottery.detail_heading') }}</h1>
            <p class="wl-disclaimer">{{ trans('bingo_lottery.not_official_notice') }}</p>
        </header>
        <section class="wl-section" aria-labelledby="mega-result-detail-heading">
            <h2 id="mega-result-detail-heading" class="wl-section__heading">{{ trans('bingo_lottery.current_result_heading') }}</h2>
            @if (! $available)
                <p class="wl-empty" role="status">{{ trans('bingo_lottery.status.'.$status) }}</p>
            @else
                <x-bingo-lottery.result-card :result="$projection" :is-thai="$is_thai" :show-link="false" />
                <section class="wl-section wl-section--provenance" aria-labelledby="mega-result-provenance">
                    <h3 id="mega-result-provenance" class="wl-section__heading">{{ trans('bingo_lottery.provenance_heading') }}</h3>
                    <x-bingo-lottery.source-status :provenance="(array) ($projection['provenance'] ?? [])" :integrity="$projection['integrity'] ?? null" />
                </section>
            @endif
        </section>
        <x-bingo-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/bingo-lottery.js'])
@endpush
