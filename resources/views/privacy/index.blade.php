@extends('layouts.app')

@php
    $sections = is_array($privacy['sections'] ?? null) ? $privacy['sections'] : [];
    $available = (string) ($privacy['status'] ?? 'UNAVAILABLE') === 'AVAILABLE';
@endphp

@section('title', (string) ($meta['title'] ?? $privacy['meta_title'] ?? trans('public_pages.privacy_meta_title')))
@section('meta_description', (string) ($meta['description'] ?? $privacy['meta_description'] ?? trans('public_pages.privacy_meta_description')))
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/privacy')))
@section('meta_og_title', (string) ($meta['og_title'] ?? $privacy['meta_title'] ?? trans('public_pages.privacy_meta_title')))
@section('meta_og_description', (string) ($meta['og_description'] ?? $privacy['meta_description'] ?? trans('public_pages.privacy_meta_description')))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@section('meta_og_url', (string) ($meta['og_url'] ?? url('/privacy')))

@push('styles')
    @vite('resources/css/pages/privacy.css')
@endpush

@section('content')
<div class="next-public-page next-public-page--privacy" data-next-public-page="privacy" data-pp-page="privacy">
    <a class="pp-skip-link" href="#privacy-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header"><div class="next-shell next-page-header__inner">
        <a class="next-brand" href="{{ route('home') }}" aria-label="{{ trans('public_pages.privacy_home_aria') }}"><span class="next-brand__mark">TL</span><span>THAILOTTO<small>{{ trans('public_pages.privacy_brand_subtitle') }}</small></span></a>
        <nav class="next-nav" aria-label="{{ trans('public_pages.privacy_primary_nav') }}"><a href="{{ route('home') }}">{{ trans('public_pages.privacy_nav_home') }}</a><a href="{{ route('about') }}">{{ trans('public_pages.privacy_nav_about') }}</a><a href="{{ route('vision') }}">{{ trans('public_pages.privacy_nav_vision') }}</a><a class="is-active" href="{{ route('privacy') }}" aria-current="page">{{ trans('public_pages.privacy_nav_privacy') }}</a><a href="{{ route('terms') }}">{{ trans('public_pages.privacy_nav_terms') }}</a><a href="{{ route('contact') }}">{{ trans('public_pages.privacy_nav_contact') }}</a></nav>
        @guest<a class="next-button next-button--gold" href="{{ route('login') }}">{{ trans('public_pages.privacy_login') }}</a>@else<a class="next-button next-button--gold" href="{{ route('player.dashboard') }}">{{ trans('public_pages.privacy_dashboard') }}</a>@endguest
    </div></header>

    <main id="privacy-main" class="next-shell next-content" tabindex="-1">
        <section class="next-hero" aria-labelledby="privacy-title"><div><p class="next-eyebrow">{{ trans('public_pages.privacy_eyebrow') }}</p><h1 id="privacy-title">{{ $privacy['title'] ?? trans('public_pages.privacy_title') }}</h1><p>{{ $privacy['meta_description'] ?? trans('public_pages.privacy_meta_description') }}</p><p class="next-note">{{ trans('public_pages.privacy_public_note') }}</p></div><div class="next-hero-object next-hero-object--privacy" aria-hidden="true"><span>§</span></div></section>
        <section class="next-meta-strip" aria-label="{{ trans('public_pages.privacy_metadata_aria') }}"><div><span>{{ $privacy['version_label'] ?? trans('public_pages.privacy_version_label') }}</span><strong>{{ $privacy['version'] ?? ($privacy['not_configured'] ?? trans('public_pages.privacy_not_configured')) }}</strong></div><div><span>{{ $privacy['effective_label'] ?? trans('public_pages.privacy_effective_label') }}</span><strong>{{ $privacy['effective_at'] ?? ($privacy['not_configured'] ?? trans('public_pages.privacy_not_configured')) }}</strong></div><div><span>{{ $privacy['updated_label'] ?? trans('public_pages.privacy_updated_label') }}</span><strong>{{ $privacy['updated_at'] ?? ($privacy['not_configured'] ?? trans('public_pages.privacy_not_configured')) }}</strong></div><div><span>{{ trans('public_pages.privacy_status_label') }}</span><strong>{{ $available ? trans('public_pages.privacy_available') : trans('public_pages.privacy_unavailable') }}</strong></div></section>

        @if (! $available)
            <section class="next-panel" role="status"><h2>{{ trans('public_pages.privacy_unavailable_title') }}</h2><p>{{ trans('public_pages.privacy_unavailable_body') }}</p></section>
        @else
            <div class="next-layout">
                <aside class="next-sidebar" aria-label="{{ trans('public_pages.privacy_contents_aria') }}"><p class="next-eyebrow">{{ trans('public_pages.privacy_document_map') }}</p>@foreach ($sections as $section)<a href="#{{ $section['id'] ?? 'privacy-'.$loop->iteration }}" data-content-link="{{ $section['id'] ?? 'privacy-'.$loop->iteration }}">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} · {{ $section['title'] ?? trans('public_pages.privacy_section_fallback') }}</a>@endforeach</aside>
                <div>
                    <div class="next-search" role="search"><label for="privacy-search">{{ trans('public_pages.privacy_search_label') }}</label><div class="next-search__row"><input id="privacy-search" type="search" data-local-search placeholder="{{ trans('public_pages.privacy_search_placeholder') }}" autocomplete="off"><button class="next-button" type="button" data-clear-search>{{ trans('public_pages.privacy_clear') }}</button></div><p class="next-search__status" data-search-status aria-live="polite">{{ trans('public_pages.privacy_search_status', ['count' => count($sections)]) }}</p></div>
                    @foreach ($sections as $section)
                        @php $sectionId = (string) ($section['id'] ?? 'privacy-'.$loop->iteration); @endphp
                        <section class="next-section" id="{{ $sectionId }}" data-content-section data-search-item data-search-text="{{ ($section['title'] ?? '').' '.($section['body'] ?? '') }}" aria-labelledby="{{ $sectionId }}-title"><div class="next-section__heading"><span class="next-section__number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><div><p class="next-eyebrow">{{ trans('public_pages.privacy_section_eyebrow') }}</p><h2 id="{{ $sectionId }}-title">{{ $section['title'] ?? trans('public_pages.privacy_section_fallback') }}</h2></div></div><div class="next-section__body"><p>{{ $section['body'] ?? '' }}</p></div></section>
                    @endforeach
                </div>
            </div>
            <section class="next-bottom"><div><p class="next-eyebrow">{{ trans('public_pages.privacy_data_questions') }}</p><h2>{{ trans('public_pages.privacy_support_heading') }}</h2><p>{{ trans('public_pages.privacy_support_body') }}</p></div><div class="next-bottom__links"><button class="next-button next-button--gold" type="button" data-print-page>{{ trans('public_pages.privacy_print_save') }}</button><a class="next-button" href="{{ route('contact') }}">{{ trans('public_pages.privacy_contact_support') }}</a><a class="next-button" href="{{ route('terms') }}">{{ trans('public_pages.privacy_nav_terms') }}</a></div></section>
        @endif
    </main>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/privacy.js')
@endpush
