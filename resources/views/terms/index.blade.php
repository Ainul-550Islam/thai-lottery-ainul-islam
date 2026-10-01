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

    <header class="terms-header" role="banner">
        <div class="terms-shell terms-header__inner">
            <a class="terms-brand" href="{{ route('home') }}" aria-label="ThaiLotto home">
                <span class="terms-brand__mark" aria-hidden="true"><span>TL</span></span>
                <span class="terms-brand__name">THAILOTTO <small>LEGAL INFORMATION</small></span>
            </a>
            <nav class="terms-nav" aria-label="Primary navigation">
                <a href="{{ route('home') }}">HOME</a>
                <a href="{{ route('results.index') }}">RESULTS</a>
                <a href="{{ route('about') }}">ABOUT US</a>
                <a href="{{ route('vision') }}">VISION</a>
                <a class="is-active" href="{{ route('terms') }}" aria-current="page">TERMS</a>
                <a href="{{ route('contact') }}">CONTACT</a>
            </nav>
            <div class="terms-header__actions">
                <button class="terms-button terms-button--quiet terms-print-button" type="button" data-print-terms>PRINT</button>
                @auth
                    <a class="terms-button terms-button--gold" href="{{ route('player.dashboard') }}">DASHBOARD</a>
                @else
                    <a class="terms-button terms-button--gold" href="{{ route('login') }}">LOGIN</a>
                @endauth
            </div>
        </div>
    </header>

    <div id="terms-main" class="terms-shell terms-content" tabindex="-1">
        <section class="terms-hero" aria-labelledby="terms-title">
            <div class="terms-hero__copy">
                <p class="terms-eyebrow">04 · LEGAL AGREEMENT</p>
                <h1 id="terms-title">{{ $terms['title'] ?? 'Terms of Use' }}</h1>
                <p>{{ $terms['meta_description'] ?? 'Versioned public terms governing use of this platform.' }}</p>
                <p class="terms-identity-note">This page uses the independent platform’s approved public legal content. It does not claim government ownership, official agency status, licensing, or endorsement.</p>
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

        <section class="terms-meta" aria-label="Terms metadata">
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
                    <dt>Publication status</dt>
                    <dd>{{ $isAvailable ? 'AVAILABLE' : 'UNAVAILABLE' }}</dd>
                </div>
            </dl>
            <div class="terms-meta__actions">
                <button class="terms-button terms-button--gold" type="button" data-print-terms>PRINT / SAVE</button>
                <a class="terms-button terms-button--quiet" href="#terms-contents">CONTENTS</a>
            </div>
        </section>

        @if (! $isAvailable)
            <section class="terms-empty" role="status" aria-labelledby="terms-unavailable-title">
                <span class="terms-empty__icon" aria-hidden="true">§</span>
                <h2 id="terms-unavailable-title">Legal content temporarily unavailable</h2>
                <p>The approved Terms source could not be loaded. No substitute legal wording is shown.</p>
                <a class="terms-button terms-button--gold" href="{{ route('contact') }}">CONTACT SUPPORT</a>
            </section>
        @else
            <div class="terms-layout">
                <aside class="terms-sidebar" id="terms-contents" aria-label="Terms contents">
                    <button class="terms-contents-toggle" type="button" data-contents-toggle aria-expanded="false" aria-controls="terms-contents-list">
                        <span>CONTENTS</span><span aria-hidden="true">⌄</span>
                    </button>
                    <div class="terms-sidebar__inner" id="terms-contents-list">
                        <p class="terms-sidebar__eyebrow">DOCUMENT MAP</p>
                        <nav class="terms-contents-nav" data-terms-contents aria-label="Terms section navigation">
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
                            <span>OPERATOR IDENTITY</span>
                            <strong>{{ $operator['legal_name'] ?? $notConfigured }}</strong>
                            <small>Registration: {{ $operator['registration_number'] ?? $notConfigured }}</small>
                            <small>Support: {{ $operator['support_email'] ?? $notConfigured }}</small>
                            <small>Only approved configuration is displayed.</small>
                        </div>
                    </div>
                </aside>

                <div class="terms-main-column">
                    <div class="terms-search" role="search">
                        <label for="terms-search-input">Search within these Terms</label>
                        <div class="terms-search__row">
                            <input id="terms-search-input" type="search" placeholder="Search headings and legal text" autocomplete="off" data-terms-search>
                            <button class="terms-button terms-button--quiet" type="button" data-clear-search>CLEAR</button>
                        </div>
                        <p class="terms-search__status" data-terms-search-status aria-live="polite">Showing all {{ count($sections) }} sections.</p>
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
                                        <p class="terms-eyebrow">LEGAL SECTION</p>
                                        <h2 id="{{ $sectionId }}-title">{{ $section['title'] ?? 'Section' }}</h2>
                                    </div>
                                </div>
                                <div class="terms-section__body">
                                    <p>{{ $section['body'] ?? '' }}</p>
                                    @foreach ($extra as $paragraph)
                                        <p>{{ $paragraph }}</p>
                                    @endforeach
                                </div>
                                <a class="terms-section__back" href="#terms-contents">Back to contents ↑</a>
                            </section>
                        @endforeach
                    </div>
                </div>
            </div>

            <section class="terms-facts" aria-labelledby="terms-facts-title">
                <div>
                    <p class="terms-eyebrow">CONFIGURED REFERENCE</p>
                    <h2 id="terms-facts-title">Values used in this document</h2>
                    <p>These values are rendered from the approved legal and GLO-compatible configuration source. They are not replaced with decorative marketing claims.</p>
                </div>
                <dl class="terms-facts__grid">
                    @foreach ([
                        'L6 ticket price' => $facts['l6_price'] ?? $notConfigured,
                        'L6 full-sale allocation' => $facts['l6_allocation'] ?? $notConfigured,
                        'L6 full-sale units' => $facts['l6_units'] ?? $notConfigured,
                        'L6 prize count' => $facts['l6_prize_count'] ?? $notConfigured,
                        'N3 ticket price' => $facts['n3_price'] ?? $notConfigured,
                        'N3 prize pool' => $facts['n3_pool_rate'] ?? $notConfigured,
                        'Stamp duty divisor' => $facts['stamp_divisor'] ?? $notConfigured,
                        'Claim window' => isset($facts['claim_window_years']) ? $facts['claim_window_years'].' years' : $notConfigured,
                    ] as $label => $value)
                        <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </section>

            <section class="terms-contact" aria-labelledby="terms-contact-title">
                <div>
                    <p class="terms-eyebrow">QUESTIONS ABOUT THESE TERMS?</p>
                    <h2 id="terms-contact-title">Use the real support route</h2>
                    <p>For questions about the published Terms, contact support. Legal content is versioned and should not be interpreted from a search result excerpt alone.</p>
                </div>
                <div class="terms-contact__links">
                    <a class="terms-button terms-button--gold" href="{{ route('contact') }}">CONTACT SUPPORT</a>
                    <a class="terms-button terms-button--quiet" href="{{ route('privacy') }}">PRIVACY POLICY</a>
                    <a class="terms-button terms-button--quiet" href="{{ route('fees') }}">PLATFORM FEES</a>
                </div>
            </section>
        @endif
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/terms.js')
@endpush
