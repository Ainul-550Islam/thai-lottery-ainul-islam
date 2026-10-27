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
    $recentRows = is_array($recent ?? null) ? $recent : [];
@endphp
<div class="wl-page" data-wl-page="weekly-lottery-latest" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('weekly_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('weekly_lottery.current_result_heading') }}</p>
            <h1 class="wl-title">{{ trans('weekly_lottery.heading') }}</h1>
            <p class="wl-intro">{{ trans('weekly_lottery.intro') }}</p>
            <p class="wl-disclaimer">{{ trans('weekly_lottery.not_official_notice') }}</p>
            <nav class="wl-actions" aria-label="{{ trans('weekly_lottery.current_result_heading') }}">
                <a class="wl-link" href="{{ route('prize-verification') }}">{{ trans('contact.link_prize_verification') }}</a>
            </nav>
        </header>

        <section class="wl-section" aria-labelledby="weekly-latest-heading">
            <h2 id="weekly-latest-heading" class="wl-section__heading">{{ trans('weekly_lottery.current_result_heading') }}</h2>
            <x-weekly-lottery.result-card :result="$projection" :is-thai="$is_thai" />
        </section>

        @if ($recentRows !== [])
            <section class="wl-section" aria-labelledby="weekly-recent-heading">
                <h2 id="weekly-recent-heading" class="wl-section__heading">{{ trans('weekly_lottery.recent_draws_heading') }}</h2>
                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($recentRows as $row)
                        <x-weekly-lottery.result-card :result="$row" :is-thai="$is_thai" />
                    @endforeach
                </div>
            </section>
        @endif

        <x-weekly-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>
@endsection

@push('scripts')
    @vite(['resources/js/weekly-lottery.js'])
@endpush
