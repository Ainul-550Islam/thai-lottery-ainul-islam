@extends('layouts.app')

@php
    $questions = is_array($faq['questions'] ?? null) ? $faq['questions'] : [];
@endphp

@section('title', (string) ($meta['title'] ?? $faq['meta_title'] ?? trans('faq_page.meta_title')))
@section('meta_description', (string) ($meta['description'] ?? $faq['meta_description'] ?? trans('faq_page.meta_description')))
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/faq')))
@section('meta_og_title', (string) ($meta['og_title'] ?? $faq['meta_title'] ?? trans('faq_page.meta_title')))
@section('meta_og_description', (string) ($meta['og_description'] ?? $faq['meta_description'] ?? ''))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@section('meta_og_url', (string) ($meta['og_url'] ?? url('/faq')))

@push('styles')
    @vite('resources/css/pages/faq.css')
@endpush

@section('content')
<div class="next-public-page next-public-page--faq" data-next-public-page="faq">
    <a class="pp-skip-link" href="#faq-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header"><div class="next-shell next-page-header__inner"><a class="next-brand" href="{{ route('home') }}" aria-label="{{ trans('faq_page.home_aria') }}"><span class="next-brand__mark">TL</span><span>{{ config('app.name') }}<small>{{ trans('faq_page.brand') }}</small></span></a><nav class="next-nav" aria-label="{{ trans('faq_page.nav_aria') }}"><a href="{{ route('home') }}">{{ trans('faq_page.nav_home') }}</a><a class="is-active" href="{{ route('faq') }}" aria-current="page">{{ trans('faq_page.nav_faq') }}</a><a href="{{ route('how-to-play') }}">{{ trans('faq_page.nav_how') }}</a><a href="{{ route('fees') }}">{{ trans('faq_page.nav_fees') }}</a><a href="{{ route('contact') }}">{{ trans('faq_page.nav_contact') }}</a></nav>@guest<a class="next-button next-button--gold" href="{{ route('login') }}">{{ trans('faq_page.nav_login') }}</a>@else<a class="next-button next-button--gold" href="{{ route('player.dashboard') }}">{{ trans('faq_page.nav_dashboard') }}</a>@endguest</div></header>
    <main id="faq-main" class="next-shell next-content" tabindex="-1">
        <section class="next-hero" aria-labelledby="faq-title"><div><p class="next-eyebrow">{{ trans('faq_page.eyebrow') }}</p><h1 id="faq-title">{{ $faq['title'] ?? trans('faq_page.title') }}</h1><p>{{ $faq['meta_description'] ?? trans('faq_page.description') }}</p><p class="next-note">{{ trans('faq_page.note') }}</p></div><div class="next-hero-object next-hero-object--faq" aria-hidden="true"><span>?</span></div></section>
        <section class="next-meta-strip" aria-label="{{ trans('faq_page.metadata') }}"><div><span>{{ trans('faq_page.questions') }}</span><strong>{{ count($questions) }}</strong></div><div><span>{{ trans('faq_page.search') }}</span><strong>{{ trans('faq_page.local') }}</strong></div><div><span>{{ trans('faq_page.personal_data') }}</span><strong>{{ trans('faq_page.excluded') }}</strong></div><div><span>{{ trans('faq_page.updated') }}</span><strong>{{ trans('faq_page.configured') }}</strong></div></section>
        <div class="next-layout"><aside class="next-sidebar" aria-label="{{ trans('faq_page.question_map') }}"><p class="next-eyebrow">{{ trans('faq_page.question_map') }}</p>@foreach ($questions as $question)<a href="#{{ $question['id'] ?? 'faq-'.$loop->iteration }}" data-content-link="{{ $question['id'] ?? 'faq-'.$loop->iteration }}">{{ $question['number'] ?? str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} · {{ $question['question'] ?? trans('faq_page.question_fallback') }}</a>@endforeach</aside><div>
            <div class="next-search" role="search"><label for="faq-search">{{ trans('faq_page.search_label') }}</label><div class="next-search__row"><input id="faq-search" type="search" data-local-search placeholder="{{ trans('faq_page.search_placeholder') }}" autocomplete="off"><button class="next-button" type="button" data-clear-search>{{ trans('faq_page.clear') }}</button></div><p class="next-search__status" data-search-status aria-live="polite">{{ trans('faq_page.questions_shown', ['count' => count($questions)]) }}</p></div>
            @forelse ($questions as $question)
                @php $questionId = (string) ($question['id'] ?? 'faq-'.$loop->iteration); $panelId = $questionId.'-answer'; @endphp
                <section class="next-section" id="{{ $questionId }}" data-content-section data-search-item data-search-text="{{ ($question['question'] ?? '').' '.($question['answer'] ?? '') }}" aria-labelledby="{{ $questionId }}-title"><div class="next-section__heading"><span class="next-section__number">{{ $question['number'] ?? str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><div style="width:100%"><p class="next-eyebrow">{{ trans('faq_page.question') }}</p><h2 id="{{ $questionId }}-title"><button class="next-button faq-question" type="button" data-accordion-button aria-expanded="false" aria-controls="{{ $panelId }}">{{ $question['question'] ?? trans('faq_page.question_fallback') }}</button></h2></div></div><div class="next-section__body" id="{{ $panelId }}" hidden><p>{{ $question['answer'] ?? trans('faq_page.not_configured') }}</p></div></section>
            @empty
                <section class="next-panel" role="status"><h2>{{ trans('faq_page.empty_title') }}</h2><p>{{ trans('faq_page.empty_body') }}</p></section>
            @endforelse
        </div></div>
        <section class="next-bottom"><div><p class="next-eyebrow">{{ trans('faq_page.not_answered') }}</p><h2>{{ trans('faq_page.support_title') }}</h2><p>{{ trans('faq_page.support_note') }}</p></div><div class="next-bottom__links"><button class="next-button next-button--gold" type="button" data-print-page>{{ trans('faq_page.print_save') }}</button><a class="next-button" href="{{ route('contact') }}">{{ trans('faq_page.contact_support') }}</a></div></section>
    </main>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/faq.js')
@endpush
