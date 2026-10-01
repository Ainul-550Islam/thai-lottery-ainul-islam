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
<div class="wl-page pcso-page" data-wl-page="pcso-lottery-latest" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('pcso_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <nav class="wl-breadcrumb" aria-label="{{ trans('pcso_lottery.heading') }}">
            <a class="wl-breadcrumb__link" href="{{ route('pcso-lottery.index') }}">{{ trans('pcso_lottery.heading') }}</a>
        </nav>

        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('pcso_lottery.public_name') }}</p>
            <h1 class="wl-title">{{ trans('pcso_lottery.current_result_heading') }}</h1>
            <p class="wl-intro">{{ trans('pcso_lottery.intro') }}</p>
            <p class="wl-disclaimer">{{ trans('pcso_lottery.not_official_notice') }}</p>
        </header>

        <section class="wl-section" aria-labelledby="pcso-latest-result-heading">
            <h2 id="pcso-latest-result-heading" class="wl-section__heading">{{ trans('pcso_lottery.current_result_heading') }}</h2>
            <x-pcso-lottery.result-card :result="$current" :is-thai="$is_thai" />
        </section>

        @if ($recent !== [])
            <section class="wl-section" aria-labelledby="pcso-latest-recent-heading">
                <h2 id="pcso-latest-recent-heading" class="wl-section__heading">{{ trans('pcso_lottery.recent_draws_heading') }}</h2>
                <div class="pcso-result-grid">
                    @foreach ($recent as $row)
                        <x-pcso-lottery.result-card :result="$row" :is-thai="$is_thai" />
                    @endforeach
                </div>
            </section>
        @endif

        <nav class="wl-actions" aria-label="{{ trans('pcso_lottery.public_name') }}">
            <a class="wl-link" href="{{ route('pcso-lottery.history') }}">{{ trans('pcso_lottery.history_link') }}</a>
            <a class="wl-link" href="{{ route('pcso-lottery.buy') }}">{{ trans('pcso_lottery.buy_title') }}</a>
        </nav>

        <x-pcso-lottery.year-nav :years="$years" :active-year="$active_year" :is-thai="$is_thai" />
    </main>
</div>

<x-public-page.footer />
@endsection

@push('scripts')
    @vite(['resources/js/pcso-lottery.js'])
@endpush
