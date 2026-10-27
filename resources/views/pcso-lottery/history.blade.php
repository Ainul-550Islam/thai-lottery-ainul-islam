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
@php
    $historyPayload = is_array($history ?? null) ? $history : null;
    $historyRows = is_array($historyPayload['rows'] ?? null) ? $historyPayload['rows'] : [];
    $historyStatus = (string) ($historyPayload['status'] ?? 'NO_PUBLIC_DATA');
    $historyYear = is_array($historyPayload['year'] ?? null)
        ? ($historyPayload['year']['gregorian'] ?? '')
        : ($historyPayload['year'] ?? '');
@endphp

<div class="wl-page pcso-page" data-wl-page="pcso-lottery-history" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('pcso_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <nav class="wl-breadcrumb" aria-label="{{ trans('pcso_lottery.heading') }}">
            <a class="wl-breadcrumb__link" href="{{ route('pcso-lottery.index') }}">{{ trans('pcso_lottery.heading') }}</a>
        </nav>

        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('pcso_lottery.public_name') }}</p>
            <h1 class="wl-title">{{ trans('pcso_lottery.history_heading') }}</h1>
            <p class="wl-intro">{{ trans('pcso_lottery.intro') }}</p>
            <p class="wl-disclaimer">{{ trans('pcso_lottery.not_official_notice') }}</p>
        </header>

        @if ($historyPayload === null || $historyRows === [])
            <section class="wl-section wl-section--empty">
                <p class="wl-empty" role="status">{{ trans('pcso_lottery.status.'.strtolower($historyStatus)) }}</p>
            </section>
        @else
            <section class="wl-section" aria-labelledby="pcso-history-table-heading">
                <div class="pcso-section-heading">
                    <div>
                        <h2 id="pcso-history-table-heading" class="wl-section__heading">{{ trans('pcso_lottery.history_heading') }}</h2>
                        <p class="wl-section__subheading">{{ trans('pcso_lottery.history_year_label', ['year' => $historyYear]) }}</p>
                    </div>
                    <a class="wl-link" href="{{ route('pcso-lottery.year', ['year' => $historyYear]) }}">
                        {{ trans('pcso_lottery.year_archive_link') }}
                    </a>
                </div>
                <x-pcso-lottery.result-table
                    :rows="$historyRows"
                    :is-thai="$is_thai"
                    :pagination="$historyPayload['pagination'] ?? null"
                    pagination-route="pcso-lottery.year"
                    :pagination-params="['year' => $historyYear]"
                />
            </section>
        @endif

        <x-pcso-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>

<x-public-page.footer />
@endsection

@push('scripts')
    @vite(['resources/js/pcso-lottery.js'])
@endpush
