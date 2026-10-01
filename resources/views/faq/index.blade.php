@extends('layouts.app')

@php
    $questions = is_array($faq['questions'] ?? null) ? $faq['questions'] : [];
@endphp

@section('title', (string) ($meta['title'] ?? $faq['meta_title'] ?? 'FAQ'))
@section('meta_description', (string) ($meta['description'] ?? $faq['meta_description'] ?? 'Frequently asked questions.'))
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/faq')))
@section('meta_og_title', (string) ($meta['og_title'] ?? $faq['meta_title'] ?? 'FAQ'))
@section('meta_og_description', (string) ($meta['og_description'] ?? $faq['meta_description'] ?? ''))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@section('meta_og_url', (string) ($meta['og_url'] ?? url('/faq')))

@push('styles')
    @vite('resources/css/pages/faq.css')
@endpush

@section('content')
<div class="next-public-page next-public-page--faq" data-next-public-page="faq">
    <a class="pp-skip-link" href="#faq-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header"><div class="next-shell next-page-header__inner"><a class="next-brand" href="{{ route('home') }}" aria-label="Home"><span class="next-brand__mark">TL</span><span>THAILOTTO<small>ANSWER CENTRE</small></span></a><nav class="next-nav" aria-label="Primary navigation"><a href="{{ route('home') }}">HOME</a><a class="is-active" href="{{ route('faq') }}" aria-current="page">FAQ</a><a href="{{ route('how-to-play') }}">HOW TO PLAY</a><a href="{{ route('fees') }}">FEES</a><a href="{{ route('contact') }}">CONTACT</a></nav>@guest<a class="next-button next-button--gold" href="{{ route('login') }}">LOGIN</a>@else<a class="next-button next-button--gold" href="{{ route('player.dashboard') }}">DASHBOARD</a>@endguest</div></header>
    <main id="faq-main" class="next-shell next-content" tabindex="-1">
        <section class="next-hero" aria-labelledby="faq-title"><div><p class="next-eyebrow">12 · ANSWERS</p><h1 id="faq-title">{{ $faq['title'] ?? 'FAQ' }}</h1><p>{{ $faq['meta_description'] ?? 'Source-driven answers to common platform questions.' }}</p><p class="next-note">Answers describe configured platform behavior and boundaries. They do not turn a lookup into a guarantee or expose personal account data.</p></div><div class="next-hero-object next-hero-object--faq" aria-hidden="true"><span>?</span></div></section>
        <section class="next-meta-strip" aria-label="FAQ metadata"><div><span>QUESTIONS</span><strong>{{ count($questions) }}</strong></div><div><span>SEARCH</span><strong>LOCAL</strong></div><div><span>PERSONAL DATA</span><strong>EXCLUDED</strong></div><div><span>UPDATED</span><strong>CONFIGURED</strong></div></section>
        <div class="next-layout"><aside class="next-sidebar" aria-label="Frequently asked questions"><p class="next-eyebrow">QUESTION MAP</p>@foreach ($questions as $question)<a href="#{{ $question['id'] ?? 'faq-'.$loop->iteration }}" data-content-link="{{ $question['id'] ?? 'faq-'.$loop->iteration }}">{{ $question['number'] ?? str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} · {{ $question['question'] ?? 'Question' }}</a>@endforeach</aside><div>
            <div class="next-search" role="search"><label for="faq-search">Search questions and answers</label><div class="next-search__row"><input id="faq-search" type="search" data-local-search placeholder="Type a keyword" autocomplete="off"><button class="next-button" type="button" data-clear-search>CLEAR</button></div><p class="next-search__status" data-search-status aria-live="polite">Showing all {{ count($questions) }} questions.</p></div>
            @forelse ($questions as $question)
                @php $questionId = (string) ($question['id'] ?? 'faq-'.$loop->iteration); $panelId = $questionId.'-answer'; @endphp
                <section class="next-section" id="{{ $questionId }}" data-content-section data-search-item data-search-text="{{ ($question['question'] ?? '').' '.($question['answer'] ?? '') }}" aria-labelledby="{{ $questionId }}-title"><div class="next-section__heading"><span class="next-section__number">{{ $question['number'] ?? str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><div style="width:100%"><p class="next-eyebrow">QUESTION</p><h2 id="{{ $questionId }}-title"><button class="next-button faq-question" type="button" data-accordion-button aria-expanded="false" aria-controls="{{ $panelId }}">{{ $question['question'] ?? 'Question' }}</button></h2></div></div><div class="next-section__body" id="{{ $panelId }}" hidden><p>{{ $question['answer'] ?? 'NOT_CONFIGURED' }}</p></div></section>
            @empty
                <section class="next-panel" role="status"><h2>FAQ unavailable</h2><p>No approved questions are configured.</p></section>
            @endforelse
        </div></div>
        <section class="next-bottom"><div><p class="next-eyebrow">NOT ANSWERED?</p><h2>Ask through the configured support route</h2><p>Do not include passwords, payment credentials or unnecessary identity documents.</p></div><div class="next-bottom__links"><button class="next-button next-button--gold" type="button" data-print-page>PRINT / SAVE</button><a class="next-button" href="{{ route('contact') }}">CONTACT SUPPORT</a></div></section>
    </main>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/faq.js')
@endpush
