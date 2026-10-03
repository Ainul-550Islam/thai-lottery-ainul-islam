@extends('layouts.app')

@section('title', (string) ($page['title'] ?? trans('public_pages.about_meta_title', [], app()->getLocale())))
@section('meta_description', (string) ($meta['description'] ?? trans('public_pages.about_meta_description', [], app()->getLocale())))
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/about')))
@section('meta_og_title', (string) ($meta['og_title'] ?? $page['title'] ?? 'About'))
@section('meta_og_description', (string) ($meta['og_description'] ?? $page['lead'] ?? ''))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@section('meta_og_url', (string) ($meta['og_url'] ?? url('/about')))

@push('styles')
    @vite('resources/css/pages/about.css')
@endpush

@section('content')
<div class="about-page" data-about-page data-locale="{{ e((string) ($page['locale'] ?? app()->getLocale())) }}">
    <a class="pp-skip-link" href="#about-main">{{ trans('public_pages.skip_to_content') }}</a>

    <header class="about-header" role="banner">
        <div class="about-shell about-header__inner">
            <a class="about-brand" href="{{ route('home') }}" aria-label="ThaiLotto home">
                <span class="about-brand__mark" aria-hidden="true"><span>TL</span></span>
                <span class="about-brand__name">{{ config('app.name') }} <small>PUBLIC INFORMATION</small></span>
            </a>
            <nav class="about-nav" aria-label="Primary navigation">
                <a href="{{ route('home') }}">HOME</a>
                <a href="{{ route('results.index') }}">RESULTS</a>
                <a href="{{ route('ticket-check') }}">VERIFY</a>
                <a href="{{ route('vision') }}">VISION</a>
                <a class="is-active" href="{{ route('about') }}" aria-current="page">ABOUT US</a>
                <a href="{{ route('contact') }}">CONTACT</a>
            </nav>
            <div class="about-header__actions">
                @auth
                    <a class="about-button about-button--gold" href="{{ route('player.dashboard') }}">DASHBOARD</a>
                @else
                    <a class="about-button about-button--quiet" href="{{ route('login') }}">LOGIN</a>
                    <a class="about-button about-button--gold" href="{{ route('register') }}">JOIN NOW</a>
                @endauth
            </div>
        </div>
    </header>

    <div id="about-main" class="about-shell about-content" tabindex="-1">
        <section class="about-hero" aria-labelledby="about-hero-title">
            <div class="about-hero__copy">
                <p class="about-eyebrow">{{ $page['hero']['eyebrow'] }}</p>
                <h1 id="about-hero-title">{{ $page['hero']['title'] }}</h1>
                <p class="about-hero__lead">{{ $page['lead'] }}</p>
                <div class="about-hero__notice" role="note">
                    <span class="about-notice__icon" aria-hidden="true">i</span>
                    <p>ThaiLotto is an independent platform. It is <strong>not the Government Lottery Office</strong>, <strong>not an official GLO agent</strong>, and <strong>not a government portal</strong>.</p>
                </div>
                <a class="about-text-link" href="#about-timeline">EXPLORE THE STORY <span aria-hidden="true">↓</span></a>
            </div>
            <div class="about-hero__visual" aria-label="{{ $page['hero']['visual_label'] }}" role="img">
                <div class="about-visual-grid" aria-hidden="true"></div>
                <div class="about-orbit about-orbit--outer" aria-hidden="true"></div>
                <div class="about-orbit about-orbit--inner" aria-hidden="true"></div>
                <div class="about-visual-card about-visual-card--main">
                    <span class="about-visual-card__label">{{ $page['hero']['visual_label'] }}</span>
                    <div class="about-ball-cluster" aria-hidden="true">
                        <span class="about-ball about-ball--large">5</span>
                        <span class="about-ball about-ball--small">8</span>
                        <span class="about-ball about-ball--medium">2</span>
                    </div>
                    <span class="about-visual-card__caption">HISTORY · CLARITY · CONTROL</span>
                </div>
                <span class="about-visual-chip about-visual-chip--top">SOURCE LABELLED</span>
                <span class="about-visual-chip about-visual-chip--bottom">EDITORIAL ARCHIVE</span>
            </div>
        </section>

        <section class="about-section about-story" aria-labelledby="about-story-title">
            <div class="about-section__heading">
                <p class="about-eyebrow">{{ $page['story']['eyebrow'] }}</p>
                <h2 id="about-story-title">{{ $page['story']['title'] }}</h2>
            </div>
            <div class="about-story__grid">
                <div class="about-story__copy">
                    <p>{{ $page['story']['text'] }}</p>
                    <p class="about-source-note">{{ $page['story']['source_note'] }}</p>
                </div>
                <div class="about-story__artifact" aria-hidden="true">
                    <span class="about-artifact__line"></span>
                    <span class="about-artifact__year">R5</span>
                    <span class="about-artifact__label">A HISTORY OF LOTTERY REFERENCE</span>
                    <span class="about-artifact__line"></span>
                </div>
            </div>
        </section>

        <section class="about-section about-steps" aria-labelledby="about-steps-title">
            <div class="about-section__heading about-section__heading--center">
                <p class="about-eyebrow">{{ trans('public_pages.how_it_works_title') }}</p>
                <h2 id="about-steps-title">Choose <span aria-hidden="true">→</span> Buy <span aria-hidden="true">→</span> Result <span aria-hidden="true">→</span> Claim</h2>
                <p>{{ trans('public_pages.useful_links_text') }}</p>
            </div>
            <ol class="about-step-grid">
                @forelse ($page['how_it_works'] as $step)
                    <li class="about-step-card" data-pp-step="{{ e($step['key']) }}">
                        <span class="about-step-card__number">{{ str_pad((string) $step['index'], 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="about-step-card__icon" aria-hidden="true">
                            @switch($step['key'])
                                @case('choose') ◇ @break
                                @case('buy') ◉ @break
                                @case('result') ◎ @break
                                @default ✓
                            @endswitch
                        </span>
                        <h3>{{ $step['label'] }}</h3>
                        <p>{{ $step['text'] }}</p>
                    </li>
                @empty
                    <li class="about-empty">{{ trans('public_pages.about_timeline_empty') }}</li>
                @endforelse
            </ol>
        </section>

        <section id="about-timeline" class="about-section about-timeline-section" aria-labelledby="about-timeline-title">
            <div class="about-section__heading about-section__heading--center">
                <p class="about-eyebrow">{{ $page['timeline']['eyebrow'] }}</p>
                <h2 id="about-timeline-title">{{ $page['timeline']['title'] }}</h2>
                <p>{{ $page['timeline']['text'] }}</p>
            </div>
            @if (count($page['timeline']['items']) > 0)
                <div class="about-timeline" data-about-timeline role="list" aria-label="{{ $page['timeline']['title'] }}">
                    @foreach ($page['timeline']['items'] as $item)
                        <article class="about-timeline__item" data-timeline-item data-display-order="{{ $item['display_order'] }}" tabindex="0" role="listitem" aria-label="{{ $item['year'] }} {{ $item['title'] }}">
                            <div class="about-timeline__rail" aria-hidden="true">
                                <span class="about-timeline__node">{{ $item['icon'] }}</span>
                            </div>
                            <div class="about-timeline__card">
                                <div class="about-timeline__date"><span>{{ $item['year'] }}</span><small>{{ $item['era'] }}</small></div>
                                <div>
                                    <h3>{{ $item['title'] }}</h3>
                                    <p>{{ $item['description'] }}</p>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
                <p class="about-source-note about-source-note--center"><span aria-hidden="true">◈</span> {{ $page['timeline']['source_label'] }}</p>
            @else
                <div class="about-empty" role="status">{{ $page['timeline']['empty_label'] }}</div>
            @endif
        </section>

        <section class="about-section about-values" aria-labelledby="about-values-title">
            <div class="about-section__heading about-section__heading--center">
                <p class="about-eyebrow">{{ $page['values']['eyebrow'] }}</p>
                <h2 id="about-values-title">{{ $page['values']['title'] }}</h2>
                <p>{{ $page['values']['text'] }}</p>
            </div>
            <div class="about-card-grid">
                @foreach ($page['values']['items'] as $value)
                    <article class="about-glass-card" data-about-value="{{ e($value['key']) }}">
                        <span class="about-glass-card__mark" aria-hidden="true">◆</span>
                        <h3>{{ $value['label'] }}</h3>
                        <p>{{ $value['text'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="about-section about-trust" aria-labelledby="about-trust-title">
            <div class="about-trust__visual" aria-hidden="true">
                <div class="about-shield"><span>✓</span></div>
                <div class="about-shield__ring about-shield__ring--one"></div>
                <div class="about-shield__ring about-shield__ring--two"></div>
            </div>
            <div class="about-trust__copy">
                <p class="about-eyebrow">{{ $page['trust']['eyebrow'] }}</p>
                <h2 id="about-trust-title">{{ $page['trust']['title'] }}</h2>
                <p>{{ $page['trust']['text'] }}</p>
                <ul class="about-trust-list">
                    @foreach ($page['trust']['items'] as $item)
                        <li data-about-trust="{{ e($item['key']) }}"><span aria-hidden="true">✓</span>{{ $item['text'] }}</li>
                    @endforeach
                </ul>
            </div>
        </section>

        <section class="about-section about-links" aria-labelledby="about-links-title">
            <div class="about-section__heading">
                <p class="about-eyebrow">{{ trans('public_pages.useful_links_title') }}</p>
                <h2 id="about-links-title">{{ $page['cta']['title'] }}</h2>
                <p>{{ $page['cta']['text'] }}</p>
            </div>
            <div class="about-link-grid">
                @foreach ($page['useful_links'] as $link)
                    <a class="about-link-card" href="{{ $link['href'] }}"><span>{{ $link['label'] }}</span><b aria-hidden="true">↗</b></a>
                @endforeach
            </div>
        </section>

        <section class="about-contact" data-pp-section="contact" aria-labelledby="about-contact-title">
            <div>
                <p class="about-eyebrow">{{ $page['contact']['title'] }}</p>
                <h2 id="about-contact-title">{{ $page['contact']['text'] }}</h2>
            </div>
            <a class="about-button about-button--gold" href="{{ $page['contact']['href'] }}">{{ trans('public_pages.about_cta_contact') }} <span aria-hidden="true">→</span></a>
        </section>

        <section class="about-cta" aria-labelledby="about-cta-title">
            <div class="about-cta__copy">
                <p class="about-eyebrow">{{ config('app.name') }} · PUBLIC ROUTES</p>
                <h2 id="about-cta-title">{{ $page['cta']['title'] }}</h2>
                <p>{{ $page['cta']['source_note'] }}</p>
            </div>
            <div class="about-cta__links">
                @foreach ($page['cta']['links'] as $link)
                    <a class="about-button {{ $loop->first ? 'about-button--gold' : 'about-button--quiet' }}" href="{{ $link['href'] }}">{{ $link['label'] }}</a>
                @endforeach
            </div>
        </section>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/about.js')
@endpush
