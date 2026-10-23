@extends('layouts.app')

{{--
    D5 — a single article.

    TWO THINGS THIS VIEW GETS RIGHT THAT ARE EASY TO GET WRONG
    ----------------------------------------------------------
    1. The body is echoed raw ({!! !!}) because it was sanitised ON WRITE by
       HtmlSanitiser. Sanitising again here would be wasted work on every
       render, and — more importantly — any future code path that forgot to
       sanitise would render raw HTML. Making the stored value safe means the
       rendering path cannot get it wrong.

    2. The call to action is conditional on the category. Player-protection
       content never carries one: an article explaining how to set a spending
       limit that ends in "deposit now" is not player protection, and a
       regulator will read it exactly that way. See
       App\Enums\ArticleCategory::allowsCallToAction().
--}}

@section('title', $article->effectiveTitle())
@section('meta_description', $article->effectiveDescription())
@section('meta_canonical', route('blog.show', ['locale' => $article->locale, 'slug' => $article->slug]))
@section('meta_og_title', $article->effectiveTitle())
@section('meta_og_description', $article->effectiveDescription())
@section('meta_og_type', 'article')
@section('meta_og_url', route('blog.show', ['locale' => $article->locale, 'slug' => $article->slug]))

{{-- Per-draw result coverage is reachable but deliberately not indexed: it is
     worthless after its draw, and thousands of expired thin pages cost a domain
     more than they earn. --}}
@section('meta_robots', $indexable ? 'index, follow' : 'noindex, follow')

@push('head_extras')
    @foreach ($translations as $code => $href)
        <link rel="alternate" hreflang="{{ $code }}" href="{{ $href }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ $translations['en'] ?? url('/blog') }}">
@endpush

@push('structured_data')
    <x-seo.json-ld :data="$structuredData" />
@endpush

@push('styles')
    @vite('resources/css/pages/blog.css')
@endpush

@section('content')
<div class="next-public-page next-public-page--blog" data-next-public-page="blog">
    <a class="pp-skip-link" href="#article-main">{{ __('blog.skip_to_content') }}</a>

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

    <div id="article-main" class="next-shell next-content" tabindex="-1">
        <nav class="blog-breadcrumb" aria-label="{{ __('blog.breadcrumb_label') }}">
            <a href="{{ route('home') }}">{{ __('blog.nav_home') }}</a>
            <span aria-hidden="true">/</span>
            <a href="{{ route('blog.index', ['locale' => $locale]) }}">{{ __('blog.nav_blog') }}</a>
            <span aria-hidden="true">/</span>
            <span aria-current="page">{{ $article->title }}</span>
        </nav>

        <article class="blog-article">
            <header class="blog-article__header">
                <p class="next-eyebrow">{{ $article->category?->label() }}</p>
                <h1>{{ $article->title }}</h1>
                <p class="blog-article__meta">
                    <span>{{ $article->byline() }}</span>
                    <span aria-hidden="true">·</span>
                    <time datetime="{{ $article->published_at?->toIso8601String() }}">{{ $article->published_at?->format('j F Y') }}</time>
                    <span aria-hidden="true">·</span>
                    <span>{{ trans_choice('blog.reading_time', $article->estimatedReadingMinutes(), ['minutes' => $article->estimatedReadingMinutes()]) }}</span>
                </p>
            </header>

            @if (filled($article->excerpt))
                <p class="blog-article__standfirst">{{ $article->excerpt }}</p>
            @endif

            {{-- Sanitised on write; see the docblock at the top of this file. --}}
            <div class="blog-article__body">
                {!! $article->body !!}
            </div>

            @if ($notice !== '')
                <aside class="next-alert blog-notice" role="note" aria-label="{{ __('blog.notice_label') }}">
                    <strong>{{ __('blog.notice_heading') }}</strong>
                    <p>{{ $notice }}</p>
                </aside>
            @endif

            {{-- Only when the category permits it. Player-protection articles
                 never do. --}}
            @if ($showCallToAction)
                <section class="next-bottom">
                    <div>
                        <p class="next-eyebrow">{{ __('blog.cta_eyebrow') }}</p>
                        <h2>{{ __('blog.cta_title') }}</h2>
                        <p>{{ __('blog.cta_body') }}</p>
                    </div>
                    <div class="next-bottom__links">
                        @guest
                            <a class="next-button" href="{{ route('register') }}">{{ __('blog.cta_register') }}</a>
                        @else
                            <a class="next-button" href="{{ route('player.dashboard') }}">{{ __('blog.cta_dashboard') }}</a>
                        @endguest
                    </div>
                </section>
            @endif
        </article>

        @if ($related->isNotEmpty())
            <section class="blog-related" aria-labelledby="related-title">
                <h2 id="related-title">{{ __('blog.related_title') }}</h2>
                <div class="next-card-grid blog-grid">
                    @foreach ($related as $other)
                        <article class="next-card blog-card">
                            <p class="next-eyebrow">{{ $other->category?->label() }}</p>
                            <h3 class="blog-card__title">
                                <a href="{{ route('blog.show', ['locale' => $other->locale, 'slug' => $other->slug]) }}">{{ $other->title }}</a>
                            </h3>
                            <p class="blog-card__meta">
                                <time datetime="{{ $other->published_at?->toIso8601String() }}">{{ $other->published_at?->format('j M Y') }}</time>
                            </p>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/blog.js')
@endpush
