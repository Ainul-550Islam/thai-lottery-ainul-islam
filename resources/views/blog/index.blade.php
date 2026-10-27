@extends('layouts.app')

{{--
    D5 — public blog index.

    The audit recorded `blog: 0 · cms: 0 · testimonial: 0`. This is the listing
    page of the fix.

    WHY THE NOTICE IS RENDERED HERE AND NOT ONLY ON AN ARTICLE
    The index is where a reader arrives from search. A responsible-gambling
    notice that only appears once someone clicks through has already missed the
    person who read the excerpt and left.
--}}

@section('title', __('blog.title'))
@section('meta_description', __('blog.subtitle'))
@section('meta_canonical', route('blog.index', ['locale' => $locale]))
@section('meta_og_title', __('blog.title'))
@section('meta_og_description', __('blog.subtitle'))
@section('meta_og_type', 'website')
@section('meta_og_url', route('blog.index', ['locale' => $locale]))

{{-- hreflang: without these, three language versions of this page look to a
     crawler like three sites duplicating each other. --}}
@push('head_extras')
    @foreach ($alternates as $code => $href)
        <link rel="alternate" hreflang="{{ $code }}" href="{{ $href }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ $alternates['en'] ?? url('/blog') }}">
@endpush

@push('structured_data')
    <x-seo.json-ld :data="$structuredData" />
@endpush

@push('styles')
    @vite('resources/css/pages/blog.css')
@endpush

@section('content')
<div class="next-public-page next-public-page--blog" data-next-public-page="blog">
    <a class="pp-skip-link" href="#blog-main">{{ __('blog.skip_to_content') }}</a>

    <header class="next-page-header"><div class="next-page-header__inner next-shell">
        <a class="next-brand" href="{{ route('home') }}" aria-label="Home">
            <span class="next-brand__mark">TL</span>
            <span>{{ config('app.name') }}<small>{{ __('blog.brand_tagline') }}</small></span>
        </a>
        <nav class="next-nav" aria-label="Primary navigation">
            <a href="{{ route('home') }}">{{ __('blog.nav_home') }}</a>
            <a href="{{ route('results.index') }}">{{ __('blog.nav_results') }}</a>
            <a href="{{ route('how-to-play') }}">{{ __('blog.nav_how_to_play') }}</a>
            <a href="{{ route('faq') }}">{{ __('blog.nav_faq') }}</a>
            <a class="is-active" href="{{ route('blog.index', ['locale' => $locale]) }}" aria-current="page">{{ __('blog.nav_blog') }}</a>
        </nav>
        @guest
            <a class="next-button next-button--gold" href="{{ route('login') }}">{{ __('blog.nav_login') }}</a>
        @else
            <a class="next-button next-button--gold" href="{{ route('player.dashboard') }}">{{ __('blog.nav_dashboard') }}</a>
        @endguest
    </div></header>

    <main id="blog-main" class="next-shell next-content" tabindex="-1">
        <section class="next-hero" aria-labelledby="blog-title">
            <div>
                <p class="next-eyebrow">{{ __('blog.eyebrow') }}</p>
                <h1 id="blog-title">{{ __('blog.title') }}</h1>
                <p>{{ __('blog.subtitle') }}</p>
                <p class="next-note">{{ __('blog.hero_note') }}</p>
            </div>
            <div class="next-hero-object next-hero-object--blog" aria-hidden="true"><span>{{ __('blog.hero_mark') }}</span></div>
        </section>

        {{-- Category filter. A closed list, because each category carries a
             different editorial obligation — see App\Enums\ArticleCategory. --}}
        <nav class="blog-filters" aria-label="{{ __('blog.filter_label') }}">
            <a class="blog-chip{{ $category === null ? ' is-active' : '' }}"
               href="{{ route('blog.index', ['locale' => $locale]) }}"
               @if ($category === null) aria-current="page" @endif>{{ __('blog.filter_all') }}</a>
            @foreach ($categories as $option)
                <a class="blog-chip{{ $category === $option ? ' is-active' : '' }}"
                   href="{{ route('blog.index', ['locale' => $locale, 'category' => $option->value]) }}"
                   @if ($category === $option) aria-current="page" @endif>{{ $option->label() }}</a>
            @endforeach
        </nav>

        @forelse ($articles as $article)
            @if ($loop->first)
                <div class="next-card-grid blog-grid">
            @endif
                <article class="next-card blog-card">
                    <p class="next-eyebrow">{{ $article->category?->label() }}</p>
                    <h2 class="blog-card__title">
                        <a href="{{ route('blog.show', ['locale' => $article->locale, 'slug' => $article->slug]) }}">{{ $article->title }}</a>
                    </h2>
                    <p class="blog-card__excerpt">{{ $article->effectiveDescription() }}</p>
                    <p class="blog-card__meta">
                        <time datetime="{{ $article->published_at?->toIso8601String() }}">{{ $article->published_at?->format('j M Y') }}</time>
                        <span aria-hidden="true">·</span>
                        <span>{{ trans_choice('blog.reading_time', $article->estimatedReadingMinutes(), ['minutes' => $article->estimatedReadingMinutes()]) }}</span>
                    </p>
                </article>
            @if ($loop->last)
                </div>
            @endif
        @empty
            <section class="next-panel next-empty" role="status">
                <h2>{{ __('blog.empty_title') }}</h2>
                <p>{{ __('blog.empty_body') }}</p>
            </section>
        @endforelse

        @if ($articles->hasPages())
            <nav class="blog-pagination" aria-label="{{ __('blog.pagination_label') }}">
                {{ $articles->links() }}
            </nav>
        @endif

        @if ($notice !== '')
            <aside class="next-alert blog-notice" role="note" aria-label="{{ __('blog.notice_label') }}">
                <strong>{{ __('blog.notice_heading') }}</strong>
                <p>{{ $notice }}</p>
            </aside>
        @endif
    </main>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/blog.js')
@endpush
