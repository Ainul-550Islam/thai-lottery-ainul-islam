@extends('layouts.app')

@php
    $isAvailable = (string) ($terms['status'] ?? 'UNAVAILABLE') === 'AVAILABLE';
    $sections = is_array($terms['sections'] ?? null) ? $terms['sections'] : [];
    $operator = is_array($terms['operator'] ?? null) ? $terms['operator'] : [];
    $facts = is_array($terms['facts'] ?? null) ? $terms['facts'] : [];
    $notConfigured = (string) ($terms['not_configured'] ?? 'NOT_CONFIGURED');
@endphp

@section('title', (string) ($meta['title'] ?? $terms['meta_title'] ?? 'Terms of Use'))
@section('meta_description', (string) ($meta['description'] ?? $terms['meta_description'] ?? 'Versioned terms of use.'))
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/terms')))
@section('meta_og_title', (string) ($meta['og_title'] ?? $terms['meta_title'] ?? 'Terms of Use'))
@section('meta_og_description', (string) ($meta['og_description'] ?? $terms['meta_description'] ?? ''))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@section('meta_og_url', (string) ($meta['og_url'] ?? url('/terms')))

@push('styles')
    @vite('resources/css/pages/terms.css')
@endpush

@section('content')
<div class="terms-page" data-terms-page data-terms-status="{{ $isAvailable ? 'AVAILABLE' : 'UNAVAILABLE' }}" data-locale="{{ (string) ($terms['locale'] ?? app()->getLocale()) }}">
    <a class="pp-skip-link" href="#terms-main">{{ trans('public_pages.skip_to_content') }}</a>

    <header class="terms-header">
        <div class="terms-shell terms-header__inner">
            <a class="terms-brand" href="{{ route('home') }}" aria-label="{{ config('app.name', 'Thai Lottery') }} home">
                <span class="terms-brand__mark" aria-hidden="true"><span>TL</span></span>
                <span class="terms-brand__name">{{ config('app.name') }} <small>{{ __('frontend_ui.legal_brand') }}</small></span>
            </a>
            <nav class="terms-nav" aria-label="{{ __('frontend_ui.primary_nav') }}">
                <a href="{{ route('home') }}">{{ __('public_pages.privacy_nav_home') }}</a>
                <a href="{{ route('results.index') }}">{{ __('public_pages.footer_lane_results') }}</a>
                <a href="{{ route('about') }}">{{ __('public_pages.privacy_nav_about') }}</a>
                <a href="{{ route('vision') }}">{{ __('public_pages.privacy_nav_vision') }}</a>
                <a class="is-active" href="{{ route('terms') }}" aria-current="page">{{ __('public_pages.privacy_nav_terms') }}</a>
                <a href="{{ route('contact') }}">{{ __('public_pages.privacy_nav_contact') }}</a>
            </nav>
            <div class="terms-header__actions">
                <button class="terms-button terms-button--quiet terms-print-button" type="button" data-print-terms>{{ __('frontend_ui.print') }}</button>
                @auth
                    <a class="terms-button terms-button--gold" href="{{ route('player.dashboard') }}">{{ __('public_pages.privacy_dashboard') }}</a>
                @else
                    <a class="terms-button terms-button--gold" href="{{ route('login') }}">{{ __('public_pages.privacy_login') }}</a>
                @endauth
            </div>
        </div>
    </header>

    <div id="terms-main" class="terms-shell terms-content" tabindex="-1">
        <section class="terms-hero" aria-labelledby="terms-title">
            <div class="terms-hero__copy">
                <p class="terms-eyebrow">{{ __('frontend_ui.legal_eyebrow') }}</p>
                <h1 id="terms-title">{{ $terms['title'] ?? 'Terms of Use' }}</h1>
                <p>{{ $terms['meta_description'] ?? 'Versioned public terms governing use of this platform.' }}</p>
                <p class="terms-identity-note">{{ trans('public_pages.terms_identity_note') }}</p>
                @if (($terms['locale'] ?? app()->getLocale()) === 'bn')
                    <p class="terms-language-notice" role="note"><strong>{{ trans('public_pages.terms_language_notice_title') }}:</strong> {{ trans('public_pages.terms_language_notice') }}</p>
                @endif
            </div>
            <div class="terms-document" aria-hidden="true">
                <div class="terms-document__sheet">
                    <span class="terms-document__fold"></span>
                    <span class="terms-document__seal">§</span>
                    <span class="terms-document__line terms-document__line--wide"></span>
                    <span class="terms-document__line"></span>
                    <span class="terms-document__line"></span>
                    <span class="terms-document__line terms-document__line--short"></span>
                    <span class="terms-document__clause">01</span>
                    <span class="terms-document__clause terms-document__clause--two">02</span>
                </div>
                <span class="terms-document__glow"></span>
            </div>
        </section>

        <section class="terms-meta" aria-label="{{ __('frontend_ui.contents_aria') }}">
            <dl class="terms-meta__grid">
                <div data-pp-version>
                    <dt>{{ $terms['version_label'] ?? 'Version' }}</dt>
                    <dd data-pp-legal-version="{{ (string) ($terms['version'] ?? $notConfigured) }}">{{ $terms['version'] ?? $notConfigured }}</dd>
                </div>
                <div data-pp-effective>
                    <dt>{{ $terms['effective_label'] ?? 'Effective date' }}</dt>
                    <dd>{{ $terms['effective_at'] ?? $notConfigured }}</dd>
                </div>
                <div data-pp-updated>
                    <dt>{{ $terms['updated_label'] ?? 'Last updated' }}</dt>
                    <dd>{{ $terms['updated_at'] ?? $notConfigured }}</dd>
                </div>
                <div>
                    <dt>{{ __('frontend_ui.publication_status') }}</dt>
                    <dd>{{ $isAvailable ? 'AVAILABLE' : 'UNAVAILABLE' }}</dd>
                </div>
            </dl>
            <div class="terms-meta__actions">
                <button class="terms-button terms-button--gold" type="button" data-print-terms>{{ __('frontend_ui.print_save') }}</button>
                <a class="terms-button terms-button--quiet" href="#terms-contents">{{ __('frontend_ui.contents') }}</a>
            </div>
        </section>

        @if (! $isAvailable)
            <section class="terms-empty" role="status" aria-labelledby="terms-unavailable-title">
                <span class="terms-empty__icon" aria-hidden="true">§</span>
                <h2 id="terms-unavailable-title">{{ __('frontend_ui.legal_unavailable') }}</h2>
                <p>{{ __('frontend_ui.legal_unavailable_body') }}</p>
                <a class="terms-button terms-button--gold" href="{{ route('contact') }}">{{ __('frontend_ui.contact_support') }}</a>
            </section>
        @else
            <div class="terms-layout">
                <aside class="terms-sidebar" id="terms-contents" aria-label="{{ __('frontend_ui.contents_aria') }}">
                    <button class="terms-contents-toggle" type="button" data-contents-toggle aria-expanded="false" aria-controls="terms-contents-list">
                        <span>{{ __('frontend_ui.contents') }}</span><span aria-hidden="true">⌄</span>
                    </button>
                    <div class="terms-sidebar__inner" id="terms-contents-list">
                        <p class="terms-sidebar__eyebrow">{{ __('frontend_ui.document_map') }}</p>
                        <nav class="terms-contents-nav" data-terms-contents aria-label="{{ __('frontend_ui.section_nav') }}">
                            @foreach ($sections as $section)
                                @php $sectionId = (string) ($section['id'] ?? ''); @endphp
                                @if ($sectionId !== '')
                                    <a href="#{{ $sectionId }}" data-section-link="{{ $sectionId }}">
                                        <span>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                        <strong>{{ $section['title'] ?? 'Section' }}</strong>
                                    </a>
                                @endif
                            @endforeach
                        </nav>
                        <div class="terms-sidebar__operator">
                            <span>{{ __('frontend_ui.operator_identity') }}</span>
                            <strong>{{ $operator['legal_name'] ?? $notConfigured }}</strong>
                            <small>{{ __('frontend_ui.registration') }} {{ $operator['registration_number'] ?? $notConfigured }}</small>
                            <small>{{ __('frontend_ui.support') }} {{ $operator['support_email'] ?? $notConfigured }}</small>
                            <small>{{ __('frontend_ui.approved_only') }}</small>
                        </div>
                    </div>
                </aside>

                <div class="terms-main-column">
                    <div class="terms-search" role="search">
                        <label for="terms-search-input">{{ __('frontend_ui.search_terms') }}</label>
                        <div class="terms-search__row">
                            <input id="terms-search-input" type="search" placeholder="{{ __('frontend_ui.search_placeholder') }}" autocomplete="off" data-terms-search>
                            <button class="terms-button terms-button--quiet" type="button" data-clear-search>{{ __('frontend_ui.clear') }}</button>
                        </div>
                        <p class="terms-search__status" data-terms-search-status data-matches="{{ __('frontend_ui.search_sections', ['count' => '__COUNT__']) }}" data-none="{{ __('frontend_ui.search_none') }}" aria-live="polite">{{ __('frontend_ui.all_sections', ['count' => count($sections)]) }}</p>
                    </div>

                    <div class="terms-sections" data-terms-sections>
                        @foreach ($sections as $section)
                            @php
                                $sectionId = (string) ($section['id'] ?? 'section-'.$loop->iteration);
                                $extra = is_array($section['extra'] ?? null) ? $section['extra'] : [];
                            @endphp
                            <section class="terms-section" id="{{ $sectionId }}" data-term-section data-searchable-text="{{ ($section['title'] ?? '').' '.($section['body'] ?? '').' '.implode(' ', array_map('strval', $extra)) }}" aria-labelledby="{{ $sectionId }}-title">
                                <div class="terms-section__heading">
                                    <span class="terms-section__number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                    <div>
                                        <p class="terms-eyebrow">{{ __('frontend_ui.legal_section') }}</p>
                                        <h2 id="{{ $sectionId }}-title">{{ $section['title'] ?? 'Section' }}</h2>
                                    </div>
                                </div>
                                <div class="terms-section__body">
                                    <p>{{ $section['body'] ?? '' }}</p>
                                    @foreach ($extra as $paragraph)
                                        <p>{{ $paragraph }}</p>
                                    @endforeach
                                </div>
                                <a class="terms-section__back" href="#terms-contents">{{ __('frontend_ui.back_contents') }}</a>
                            </section>
                        @endforeach
                    </div>
                </div>
            </div>

            <section class="terms-facts" aria-labelledby="terms-facts-title">
                <div>
                    <p class="terms-eyebrow">{{ __('frontend_ui.configured_reference') }}</p>
                    <h2 id="terms-facts-title">{{ __('frontend_ui.document_values') }}</h2>
                    <p>{{ __('frontend_ui.values_note') }}</p>
                </div>
                <dl class="terms-facts__grid">
                    @foreach ([
                        __('frontend_ui.l6_price') => $facts['l6_price'] ?? $notConfigured,
                        __('frontend_ui.l6_allocation') => $facts['l6_allocation'] ?? $notConfigured,
                        __('frontend_ui.l6_units') => $facts['l6_units'] ?? $notConfigured,
                        __('frontend_ui.l6_count') => $facts['l6_prize_count'] ?? $notConfigured,
                        __('frontend_ui.n3_price') => $facts['n3_price'] ?? $notConfigured,
                        __('frontend_ui.n3_pool') => $facts['n3_pool_rate'] ?? $notConfigured,
                        __('frontend_ui.stamp_divisor') => $facts['stamp_divisor'] ?? $notConfigured,
                        __('frontend_ui.claim_window') => isset($facts['claim_window_years']) ? __('frontend_ui.years', ['count' => $facts['claim_window_years']]) : $notConfigured,
                    ] as $label => $value)
                        <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </section>

            <section class="terms-contact" aria-labelledby="terms-contact-title">
                <div>
                    <p class="terms-eyebrow">{{ __('frontend_ui.questions') }}</p>
                    <h2 id="terms-contact-title">{{ __('frontend_ui.support_heading') }}</h2>
                    <p>{{ __('frontend_ui.support_body') }}</p>
                </div>
                <div class="terms-contact__links">
                    <a class="terms-button terms-button--gold" href="{{ route('contact') }}">{{ __('frontend_ui.contact_support') }}</a>
                    <a class="terms-button terms-button--quiet" href="{{ route('privacy') }}">{{ __('public_pages.privacy_nav_privacy') }}</a>
                    <a class="terms-button terms-button--quiet" href="{{ route('fees') }}">{{ __('public_pages.footer_fees') }}</a>
                </div>
            </section>
        @endif
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/terms.js')
@endpush
