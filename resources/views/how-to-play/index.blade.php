@extends('layouts.app')

@php
    $steps = is_array($howToPlay['steps'] ?? null) ? $howToPlay['steps'] : [];
@endphp

@section('title', (string) ($meta['title'] ?? $howToPlay['meta_title'] ?? trans('how_page.meta_title')))
@section('meta_description', (string) ($meta['description'] ?? $howToPlay['meta_description'] ?? trans('how_page.meta_description')))
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/how-to-play')))
@section('meta_og_title', (string) ($meta['og_title'] ?? $howToPlay['meta_title'] ?? trans('how_page.meta_title')))
@section('meta_og_description', (string) ($meta['og_description'] ?? $howToPlay['meta_description'] ?? ''))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@section('meta_og_url', (string) ($meta['og_url'] ?? url('/how-to-play')))

@push('styles')
    @vite('resources/css/pages/how-to-play.css')
@endpush

@section('content')
<div class="next-public-page next-public-page--how" data-next-public-page="how-to-play">
    <a class="pp-skip-link" href="#how-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header"><div class="next-shell next-page-header__inner"><a class="next-brand" href="{{ route('home') }}" aria-label="{{ trans('how_page.home_aria') }}"><span class="next-brand__mark">TL</span><span>{{ config('app.name') }}<small>{{ trans('how_page.brand') }}</small></span></a><nav class="next-nav" aria-label="{{ trans('how_page.nav_aria') }}"><a href="{{ route('home') }}">{{ trans('how_page.nav_home') }}</a><a class="is-active" href="{{ route('how-to-play') }}" aria-current="page">{{ trans('how_page.nav_how') }}</a><a href="{{ route('results.index') }}">{{ trans('how_page.nav_results') }}</a><a href="{{ route('fees') }}">{{ trans('how_page.nav_fees') }}</a><a href="{{ route('contact') }}">{{ trans('how_page.nav_contact') }}</a></nav>@guest<a class="next-button next-button--gold" href="{{ route('login') }}">{{ trans('how_page.nav_login') }}</a>@else<a class="next-button next-button--gold" href="{{ route('player.dashboard') }}">{{ trans('how_page.nav_dashboard') }}</a>@endguest</div></header>
    <div id="how-main" class="next-shell next-content" tabindex="-1">
        <section class="next-hero" aria-labelledby="how-title"><div><p class="next-eyebrow">{{ trans('how_page.eyebrow') }}</p><h1 id="how-title">{{ $howToPlay['title'] ?? trans('how_page.title') }}</h1><p>{{ $howToPlay['meta_description'] ?? trans('how_page.description') }}</p><p class="next-note">{{ trans('how_page.note') }}</p></div><div class="next-hero-object next-hero-object--how" aria-hidden="true"><span>→</span></div></section>
        <section class="next-meta-strip" aria-label="{{ trans('how_page.metadata') }}"><div><span>{{ trans('how_page.steps') }}</span><strong>{{ count($steps) }}</strong></div><div><span>{{ trans('how_page.results') }}</span><strong>{{ trans('how_page.published_source') }}</strong></div><div><span>{{ trans('how_page.claims') }}</span><strong>{{ trans('how_page.eligibility') }}</strong></div><div><span>{{ trans('how_page.outcome') }}</span><strong>{{ trans('how_page.not_promised') }}</strong></div></section>
        <div class="next-layout"><aside class="next-sidebar" aria-label="{{ trans('how_page.journey_map') }}"><p class="next-eyebrow">{{ trans('how_page.journey_map') }}</p>@foreach ($steps as $step)<a href="#how-step-{{ $step['number'] ?? $loop->iteration }}" data-content-link="how-step-{{ $step['number'] ?? $loop->iteration }}">{{ $step['number'] ?? str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} · {{ $step['title'] ?? trans('how_page.step_fallback') }}</a>@endforeach<a href="#how-note" data-content-link="how-note">{{ trans('how_page.boundaries_link') }}</a></aside><div>
            @forelse ($steps as $step)
                @php $stepNumber = (string) ($step['number'] ?? str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT)); @endphp
                <section class="next-section" id="how-step-{{ $stepNumber }}" data-content-section data-search-item data-search-text="{{ ($step['title'] ?? '').' '.($step['text'] ?? '') }}" aria-labelledby="how-step-{{ $stepNumber }}-title"><div class="next-section__heading"><span class="next-section__number">{{ $stepNumber }}</span><div><p class="next-eyebrow">{{ trans('how_page.platform_step') }}</p><h2 id="how-step-{{ $stepNumber }}-title">{{ $step['title'] ?? trans('how_page.step_fallback') }}</h2></div></div><div class="next-section__body"><p>{{ $step['text'] ?? '' }}</p></div></section>
            @empty
                <section class="next-panel" role="status"><h2>{{ trans('how_page.empty_title') }}</h2><p>{{ trans('how_page.empty_body') }}</p></section>
            @endforelse
            <section class="next-section" id="how-note" data-content-section aria-labelledby="how-note-title"><div class="next-section__heading"><span class="next-section__number">!</span><div><p class="next-eyebrow">{{ trans('how_page.important') }}</p><h2 id="how-note-title">{{ trans('how_page.read_boundaries') }}</h2></div></div><div class="next-section__body"><p>{{ $howToPlay['disclaimer'] ?? trans('how_page.disclaimer_fallback') }}</p><p>{{ trans('how_page.official_routes_notice') }}</p></div></section>
        </div></div>
        <section class="next-bottom"><div><p class="next-eyebrow">{{ trans('how_page.ready_more') }}</p><h2>{{ trans('how_page.explore_title') }}</h2><p>{{ trans('how_page.explore_note') }}</p></div><div class="next-bottom__links"><button class="next-button next-button--gold" type="button" data-print-page>{{ trans('how_page.print_save') }}</button><a class="next-button" href="{{ route('results.index') }}">{{ trans('how_page.nav_results') }}</a><a class="next-button" href="{{ route('prize-verification') }}">{{ trans('how_page.verify_prize') }}</a></div></section>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/how-to-play.js')
@endpush
