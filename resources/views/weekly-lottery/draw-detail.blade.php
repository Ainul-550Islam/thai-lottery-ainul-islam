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
    $projection = is_array($current ?? null) ? $current : [];
    $available = (bool) ($projection['available'] ?? false);
    $status = strtolower((string) ($projection['status'] ?? 'unavailable'));
    $draw = is_array($projection['draw'] ?? null) ? $projection['draw'] : [];
    $date = is_array($draw['date'] ?? null) ? $draw['date'] : [];
@endphp
<div class="wl-page wl-page--detail" data-wl-page="weekly-lottery-draw-detail" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('weekly_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <nav class="wl-breadcrumb" aria-label="{{ trans('weekly_lottery.heading') }}">
            <a class="wl-breadcrumb__link" href="{{ $routes['index'] }}">{{ trans('weekly_lottery.heading') }}</a>
        </nav>
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('weekly_lottery.detail_heading') }}</p>
            <h1 class="wl-title">{{ trans('weekly_lottery.detail_heading') }}</h1>
            <p class="wl-disclaimer">{{ trans('weekly_lottery.not_official_notice') }}</p>
        </header>

        @if (! $available)
            <section class="wl-section wl-section--empty" role="status">
                <p class="wl-empty">{{ trans('weekly_lottery.status.'.$status) }}</p>
                <a class="wl-link" href="{{ $routes['index'] }}">{{ trans('weekly_lottery.heading') }}</a>
            </section>
        @else
            <section class="wl-section" aria-labelledby="weekly-draw-result">
                <h2 id="weekly-draw-result" class="wl-section__heading">{{ trans('weekly_lottery.current_result_heading') }}</h2>
                <x-weekly-lottery.result-card :result="$projection" :is-thai="$is_thai" :show-link="false" />
                <dl class="wl-detail__dates">
                    <div><dt>{{ trans('weekly_lottery.date_gregorian_label') }}</dt><dd><time datetime="{{ $date['iso'] ?? '' }}">{{ $date['iso'] ?? '' }}</time></dd></div>
                    <div><dt>{{ trans('weekly_lottery.date_buddhist_label') }}</dt><dd>{{ $date['buddhist_year'] ?? '' }}</dd></div>
                    @if (($draw['timezone'] ?? '') !== '')
                        <div><dt>{{ trans('weekly_lottery.date_timezone_label') }}</dt><dd>{{ $draw['timezone'] }}</dd></div>
                    @endif
                    @if (($draw['published_at'] ?? null) !== null)
                        <div><dt>{{ trans('weekly_lottery.published_at_label') }}</dt><dd><time datetime="{{ $draw['published_at'] }}">{{ $draw['published_at'] }}</time></dd></div>
                    @endif
                </dl>
            </section>
            <section class="wl-section" aria-labelledby="weekly-draw-source">
                <h2 id="weekly-draw-source" class="wl-section__heading">{{ trans('weekly_lottery.provenance_heading') }}</h2>
                <x-weekly-lottery.source-status :provenance="(array) ($projection['provenance'] ?? [])" :integrity="$projection['integrity'] ?? null" />
            </section>
        @endif

        <x-weekly-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/weekly-lottery.js'])
@endpush
