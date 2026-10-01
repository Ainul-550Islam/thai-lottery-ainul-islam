@extends('layouts.app')

@section('title', (string) ($page['title'] ?? trans('public_pages.vision_meta_title', [], app()->getLocale())))
@section('meta_description', (string) ($meta['description'] ?? trans('public_pages.vision_meta_description', [], app()->getLocale())))
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/vision')))
@section('meta_og_title', (string) ($meta['og_title'] ?? $page['title'] ?? 'Vision & Mission'))
@section('meta_og_description', (string) ($meta['og_description'] ?? $page['lead'] ?? ''))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@section('meta_og_url', (string) ($meta['og_url'] ?? url('/vision')))

@push('styles')
    @vite('resources/css/pages/vision.css')
@endpush

@section('content')
<div class="vision-page" data-vision-page data-locale="{{ e((string) ($page['locale'] ?? app()->getLocale())) }}">
    <a class="pp-skip-link" href="#vision-main">{{ trans('public_pages.skip_to_content') }}</a>

    <header class="vision-header" role="banner">
        <div class="vision-shell vision-header__inner">
            <a class="vision-brand" href="{{ route('home') }}" aria-label="ThaiLotto home">
                <span class="vision-brand__mark" aria-hidden="true"><span>TL</span></span>
                <span class="vision-brand__name">THAILOTTO <small>STRATEGY &amp; TRUST</small></span>
            </a>
            <nav class="vision-nav" aria-label="Primary navigation">
                <a href="{{ route('home') }}">HOME</a>
                <a href="{{ route('results.index') }}">RESULTS</a>
                <a href="{{ route('about') }}">ABOUT US</a>
                <a class="is-active" href="{{ route('vision') }}" aria-current="page">VISION &amp; MISSION</a>
                <a href="{{ route('contact') }}">CONTACT</a>
            </nav>
            <div class="vision-header__actions">
                @auth
                    <a class="vision-button vision-button--gold" href="{{ route('player.dashboard') }}">DASHBOARD</a>
                @else
                    <a class="vision-button vision-button--quiet" href="{{ route('login') }}">LOGIN</a>
                    <a class="vision-button vision-button--gold" href="{{ route('register') }}">JOIN NOW</a>
                @endauth
            </div>
        </div>
    </header>

    <div id="vision-main" class="vision-shell vision-content" tabindex="-1">
        <section class="vision-hero" aria-labelledby="vision-hero-title">
            <div class="vision-hero__copy">
                <p class="vision-eyebrow">{{ $page['hero']['eyebrow'] }}</p>
                <h1 id="vision-hero-title">{{ $page['hero']['title'] }}</h1>
                <p class="vision-hero__lead">{{ $page['hero']['text'] }}</p>
                <div class="vision-hero__notice" role="note">
                    <span aria-hidden="true">i</span>
                    <p>{{ $page['hero']['disclaimer'] }}</p>
                </div>
                <div class="vision-hero__links">
                    <a class="vision-button vision-button--gold" href="{{ route('about') }}">{{ trans('public_pages.vision_cta_about') }} <span aria-hidden="true">→</span></a>
                    <a class="vision-button vision-button--quiet" href="{{ route('results.index') }}">{{ trans('public_pages.vision_cta_results') }} <span aria-hidden="true">↗</span></a>
                </div>
            </div>
            <div class="vision-hero__visual" role="img" aria-label="{{ $page['hero']['visual_label'] }}">
                <div class="vision-orbit vision-orbit--outer" aria-hidden="true"></div>
                <div class="vision-orbit vision-orbit--inner" aria-hidden="true"></div>
                <div class="vision-orb" aria-hidden="true">
                    <span class="vision-orb__core">∞</span>
                    <span class="vision-orb__line vision-orb__line--one"></span>
                    <span class="vision-orb__line vision-orb__line--two"></span>
                    <span class="vision-orb__line vision-orb__line--three"></span>
                </div>
                <div class="vision-float-card vision-float-card--top"><span>01</span><small>CLARITY</small></div>
                <div class="vision-float-card vision-float-card--bottom"><span>02</span><small>RESPONSIBILITY</small></div>
                <span class="vision-hero__visual-label">{{ $page['hero']['visual_label'] }}</span>
            </div>
        </section>

        <section class="vision-section vision-pillars" data-pp-section="vision-mission" aria-labelledby="vision-pillars-title">
            <div class="vision-section__heading vision-section__heading--center">
                <p class="vision-eyebrow">VISION + MISSION</p>
                <h2 id="vision-pillars-title">A platform direction built for understanding</h2>
                <p>{{ $page['lead'] }}</p>
            </div>
            <div class="vision-pillar-grid">
                <article class="vision-pillar vision-pillar--vision">
                    <div class="vision-pillar__top"><span class="vision-pillar__index">01</span><span class="vision-pillar__icon" aria-hidden="true">◎</span></div>
                    <p class="vision-eyebrow">{{ $page['vision']['title'] }}</p>
                    <h3>{{ $page['vision']['title'] }}</h3>
                    <p class="vision-pillar__body">{{ $page['vision']['text'] }}</p>
                    <h4>{{ $page['vision']['principles_title'] }}</h4>
                    <ul class="vision-principle-list">
                        @foreach ($page['vision']['principles'] as $principle)
                            <li><span aria-hidden="true">◇</span><div><strong>{{ $principle['title'] }}</strong><p>{{ $principle['text'] }}</p></div></li>
                        @endforeach
                    </ul>
                </article>
                <article class="vision-pillar vision-pillar--mission">
                    <div class="vision-pillar__top"><span class="vision-pillar__index">02</span><span class="vision-pillar__icon" aria-hidden="true">◈</span></div>
                    <p class="vision-eyebrow">{{ $page['mission']['eyebrow'] }}</p>
                    <h3>{{ $page['mission']['title'] }}</h3>
                    <p class="vision-pillar__body">{{ $page['mission']['text'] }}</p>
                    <h4>{{ $page['mission']['principles_title'] }}</h4>
                    <ul class="vision-principle-list">
                        @foreach ($page['mission']['principles'] as $principle)
                            <li><span aria-hidden="true">◇</span><div><strong>{{ $principle['title'] }}</strong><p>{{ $principle['text'] }}</p></div></li>
                        @endforeach
                    </ul>
                </article>
            </div>
        </section>

        <section class="vision-section vision-clear" data-pp-section="clear-values" aria-labelledby="vision-clear-title">
            <div class="vision-section__heading vision-section__heading--center">
                <p class="vision-eyebrow">{{ $page['clear']['eyebrow'] }}</p>
                <h2 id="vision-clear-title">{{ $page['clear']['title'] }}</h2>
                <p>{{ $page['clear']['text'] }}</p>
            </div>
            <div class="vision-clear-grid" data-value-grid>
                @foreach ($page['clear']['items'] as $item)
                    <article class="vision-clear-card" data-clear-value="{{ e($item['key']) }}" tabindex="0" aria-label="{{ $item['letter'] }} — {{ $item['label'] }}">
                        <span class="vision-clear-card__letter" aria-hidden="true">{{ $item['letter'] }}</span>
                        <span class="vision-clear-card__glyph" aria-hidden="true">
                            @switch($item['key'])
                                @case('collaboration') ◌ @break
                                @case('learning') ↗ @break
                                @case('ethics') ◇ @break
                                @case('accountability') ✓ @break
                                @default ◎
                            @endswitch
                        </span>
                        <h3>{{ $item['label'] }}</h3>
                        <p>{{ $item['text'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="vision-section vision-values" data-pp-section="core-values" aria-labelledby="vision-values-title">
            <div class="vision-section__heading">
                <p class="vision-eyebrow">PLATFORM VALUE SYSTEM</p>
                <h2 id="vision-values-title">{{ trans('public_pages.core_values_title') }}</h2>
                <p>These are the application’s own content and engineering principles. They are not a regulatory badge, government endorsement, or promise of a particular outcome.</p>
            </div>
            <div class="vision-value-grid">
                @foreach ($page['core_values'] as $value)
                    <article class="vision-value-card" data-pp-value="{{ e($value['key']) }}" tabindex="0">
                        <span class="vision-value-card__mark" aria-hidden="true">◆</span>
                        <h3>{{ $value['label'] }}</h3>
                        <p>{{ $value['text'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="vision-section vision-governance" data-pp-section="governance" aria-labelledby="vision-governance-title">
            <div class="vision-governance__visual" aria-hidden="true">
                <div class="vision-shield"><span>✓</span></div>
                <div class="vision-shield__ring vision-shield__ring--one"></div>
                <div class="vision-shield__ring vision-shield__ring--two"></div>
            </div>
            <div class="vision-governance__copy">
                <p class="vision-eyebrow">{{ $page['governance']['eyebrow'] }}</p>
                <h2 id="vision-governance-title">{{ $page['governance']['title'] }}</h2>
                <p>{{ $page['governance']['text'] }}</p>
                <ul class="vision-control-list">
                    @foreach ($page['governance']['controls'] as $control)
                        <li data-pp-control="{{ e($control['key']) }}"><span aria-hidden="true">✓</span>{{ $control['text'] }}</li>
                    @endforeach
                </ul>
                <p class="vision-disclaimer">{{ $page['governance']['disclaimer'] }}</p>
            </div>
        </section>

        <section class="vision-section vision-journey" aria-labelledby="vision-journey-title">
            <div class="vision-section__heading vision-section__heading--center">
                <p class="vision-eyebrow">{{ $page['journey']['eyebrow'] }}</p>
                <h2 id="vision-journey-title">{{ $page['journey']['title'] }}</h2>
                <p>{{ $page['journey']['text'] }}</p>
            </div>
            <div class="vision-journey-grid">
                @foreach ($page['journey']['items'] as $item)
                    <article class="vision-journey-card" data-journey-stage="{{ e($item['key']) }}" tabindex="0">
                        <span class="vision-journey-card__number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                        <span class="vision-journey-card__label">{{ $item['label'] }}</span>
                        <p>{{ $item['text'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="vision-cta" data-pp-section="contact" aria-labelledby="vision-cta-title">
            <div class="vision-cta__copy">
                <p class="vision-eyebrow">PUBLIC ROUTES · REAL DESTINATIONS</p>
                <h2 id="vision-cta-title">{{ $page['cta']['title'] }}</h2>
                <p>{{ $page['cta']['text'] }}</p>
                <p class="vision-source-note">{{ $page['cta']['source_note'] }}</p>
            </div>
            <div class="vision-cta__links">
                @foreach ($page['cta']['links'] as $link)
                    <a class="vision-button {{ $loop->first ? 'vision-button--gold' : 'vision-button--quiet' }}" href="{{ $link['href'] }}">{{ $link['label'] }} <span aria-hidden="true">→</span></a>
                @endforeach
            </div>
        </section>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/vision.js')
@endpush
