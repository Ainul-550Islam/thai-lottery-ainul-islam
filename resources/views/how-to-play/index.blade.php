@extends('layouts.app')

@php
    $steps = is_array($howToPlay['steps'] ?? null) ? $howToPlay['steps'] : [];
@endphp

@section('title', (string) ($meta['title'] ?? $howToPlay['meta_title'] ?? 'How to Play'))
@section('meta_description', (string) ($meta['description'] ?? $howToPlay['meta_description'] ?? 'Educational guide.'))
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/how-to-play')))
@section('meta_og_title', (string) ($meta['og_title'] ?? $howToPlay['meta_title'] ?? 'How to Play'))
@section('meta_og_description', (string) ($meta['og_description'] ?? $howToPlay['meta_description'] ?? ''))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@section('meta_og_url', (string) ($meta['og_url'] ?? url('/how-to-play')))

@push('styles')
    @vite('resources/css/pages/how-to-play.css')
@endpush

@section('content')
<div class="next-public-page next-public-page--how" data-next-public-page="how-to-play">
    <a class="pp-skip-link" href="#how-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header"><div class="next-shell next-page-header__inner"><a class="next-brand" href="{{ route('home') }}" aria-label="Home"><span class="next-brand__mark">TL</span><span>THAILOTTO<small>EDUCATION CENTRE</small></span></a><nav class="next-nav" aria-label="Primary navigation"><a href="{{ route('home') }}">HOME</a><a class="is-active" href="{{ route('how-to-play') }}" aria-current="page">HOW TO PLAY</a><a href="{{ route('results.index') }}">RESULTS</a><a href="{{ route('fees') }}">FEES</a><a href="{{ route('contact') }}">CONTACT</a></nav>@guest<a class="next-button next-button--gold" href="{{ route('login') }}">LOGIN</a>@else<a class="next-button next-button--gold" href="{{ route('player.dashboard') }}">DASHBOARD</a>@endguest</div></header>
    <main id="how-main" class="next-shell next-content" tabindex="-1">
        <section class="next-hero" aria-labelledby="how-title"><div><p class="next-eyebrow">11 · EDUCATION</p><h1 id="how-title">{{ $howToPlay['title'] ?? 'How to Play' }}</h1><p>{{ $howToPlay['meta_description'] ?? 'A clear guide to the configured platform journey.' }}</p><p class="next-note">Learn the flow without turning it into a promise: participation does not guarantee a win, payout, approval, or outcome.</p></div><div class="next-hero-object next-hero-object--how" aria-hidden="true"><span>→</span></div></section>
        <section class="next-meta-strip" aria-label="How to play metadata"><div><span>STEPS</span><strong>{{ count($steps) }}</strong></div><div><span>RESULTS</span><strong>PUBLISHED SOURCE</strong></div><div><span>CLAIMS</span><strong>ELIGIBILITY CHECKED</strong></div><div><span>OUTCOME</span><strong>NOT PROMISED</strong></div></section>
        <div class="next-layout"><aside class="next-sidebar" aria-label="How to play contents"><p class="next-eyebrow">JOURNEY MAP</p>@foreach ($steps as $step)<a href="#how-step-{{ $step['number'] ?? $loop->iteration }}" data-content-link="how-step-{{ $step['number'] ?? $loop->iteration }}">{{ $step['number'] ?? str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} · {{ $step['title'] ?? 'Step' }}</a>@endforeach<a href="#how-note" data-content-link="how-note">Important boundaries</a></aside><div>
            @forelse ($steps as $step)
                @php $stepNumber = (string) ($step['number'] ?? str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT)); @endphp
                <section class="next-section" id="how-step-{{ $stepNumber }}" data-content-section data-search-item data-search-text="{{ ($step['title'] ?? '').' '.($step['text'] ?? '') }}" aria-labelledby="how-step-{{ $stepNumber }}-title"><div class="next-section__heading"><span class="next-section__number">{{ $stepNumber }}</span><div><p class="next-eyebrow">PLATFORM STEP</p><h2 id="how-step-{{ $stepNumber }}-title">{{ $step['title'] ?? 'Step' }}</h2></div></div><div class="next-section__body"><p>{{ $step['text'] ?? '' }}</p></div></section>
            @empty
                <section class="next-panel" role="status"><h2>Guide unavailable</h2><p>No approved how-to-play steps are configured.</p></section>
            @endforelse
            <section class="next-section" id="how-note" data-content-section aria-labelledby="how-note-title"><div class="next-section__heading"><span class="next-section__number">!</span><div><p class="next-eyebrow">IMPORTANT</p><h2 id="how-note-title">Read the boundaries</h2></div></div><div class="next-section__body"><p>{{ $howToPlay['disclaimer'] ?? 'This guide does not promise a win, payout, approval, or immediate payment.' }}</p><p>Use official configured result, verification, fee and support routes. Keep account credentials and identity documents private.</p></div></section>
        </div></div>
        <section class="next-bottom"><div><p class="next-eyebrow">READY TO LEARN MORE?</p><h2>Explore the source-driven pages</h2><p>Check published results, fees and verification boundaries before taking action.</p></div><div class="next-bottom__links"><button class="next-button next-button--gold" type="button" data-print-page>PRINT / SAVE</button><a class="next-button" href="{{ route('results.index') }}">RESULTS</a><a class="next-button" href="{{ route('prize-verification') }}">VERIFY PRIZE</a></div></section>
    </main>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/how-to-play.js')
@endpush
