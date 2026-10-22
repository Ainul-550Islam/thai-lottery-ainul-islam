@extends('layouts.app')

{{--
    PUBLIC HOME PAGE.

    Every section on this page is rendered from HomePageDataService. Blade runs
    no queries, invents no numbers and hardcodes no marketing claim.

    The previous revision of this file was a static marketing mockup: it carried
    a fabricated "Licensed & Provably Verified by Government Lottery Office"
    badge, invented trust copy, and reduced the prize / stats / bonuses blocks to
    empty <div id="..."> stubs that existed only so a landmark assertion would
    pass. It also linked two asset files that do not exist in public/
    (css/pages/home.css and js/pages/home.js).

    Contract honoured here:
      * Each section prints the explicit status string the service produced
        (VERIFIED / CONFIGURED / AVAILABLE / CATALOGUE_ONLY / NO_VERIFIED_RESULT
        / NOT_CONFIGURED / UNAVAILABLE). Nothing relies on truthy / falsey.
      * An unconfigured section renders its honest message, never a placeholder
        that looks like real data.
      * All interpolation uses {{ }}, so configured campaign copy cannot inject
        markup.
      * The countdown is never the only timing information: an absolute <time>
        element and a screen-reader sentence always accompany it.
--}}

@section('title', $home['text']['meta_title'] ?? config('app.name'))
@section('meta_description', $home['text']['meta_description'] ?? '')

@push('styles')
    @vite(['resources/css/home.css', 'resources/js/home/countdown.js', 'resources/js/home/live-draw.js'])
@endpush

@php
    $text = $home['text'] ?? [];
    $hero = $home['hero'] ?? [];
    $nextDraw = $home['next_draw'] ?? [];
    $countdown = $home['countdown'] ?? [];
    $currentResult = $home['current_result'] ?? [];
    $liveDraw = $home['live_draw'] ?? [];
    $laneResults = $home['lane_results'] ?? [];
    $productSummary = $home['products'] ?? [];
    $prize = $home['prize_highlight'] ?? [];
    $stats = $home['stats'] ?? [];
    $bonuses = $home['bonuses'] ?? [];
    $payments = $home['payment_methods'] ?? [];
    $support = $home['support'] ?? [];
    $appLinks = $home['app_links'] ?? [];
    $trust = $home['trust'] ?? [];
    $navigation = $home['navigation'] ?? [];
    $sectionStatusLabels = [
        'AVAILABLE' => trans('home.section_status.available'),
        'UNAVAILABLE' => trans('home.section_status.unavailable'),
        'CATALOGUE_ONLY' => trans('home.section_status.catalogue_only'),
        'CONFIGURED' => trans('home.section_status.configured'),
        'NOT_CONFIGURED' => trans('home.section_status.not_configured'),
    ];

    // The countdown target: the live countdown service first, then the next
    // scheduled draw, then the published official calendar. One of these is
    // always present, so the element never renders an empty data-target.
    $countdownTarget = $countdown['scheduled_at_iso']
        ?? ($nextDraw['scheduled_at_iso'] ?? ($hero['next_draw_iso'] ?? null));
    $countdownZone = $countdown['timezone']
        ?? ($nextDraw['timezone'] ?? ($hero['timezone'] ?? 'Asia/Bangkok'));
    $countdownDisplay = $nextDraw['scheduled_at_display'] ?? null;
@endphp

@section('content')
<div class="home-page flex flex-col gap-12">

    {{-- ----------------------------------------------------------------
         Section navigation. Links come from HomePageDataService::navigation(),
         which resolves each route defensively and omits unregistered routes,
         so the page never publishes empty placeholder destinations.
    ----------------------------------------------------------------- --}}
    <nav class="home-nav flex flex-wrap items-center gap-3 border-b border-slate-800 pb-4"
         aria-label="{{ trans('home.navigation_label') }}">
        @foreach ($navigation as $item)
            <a href="{{ $item['url'] }}"
               class="home-nav__link rounded-lg px-3 py-2 text-sm font-semibold transition-colors {{ $item['active'] ? 'bg-amber-400/15 text-amber-200' : 'text-slate-300 hover:text-amber-200' }}"
               @if ($item['active']) aria-current="page" @endif>
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    {{-- ----------------------------------------------------------------
         Hero
    ----------------------------------------------------------------- --}}
    <section id="hero" aria-labelledby="hero-title" class="home-hero rounded-2xl border border-slate-800 bg-slate-900/50 p-8">
        <h1 id="hero-title" class="text-3xl sm:text-4xl font-black text-white">
            {{ $text['hero_title'] ?? config('app.name') }}
        </h1>
        <p class="mt-3 max-w-3xl text-slate-300">{{ $text['hero_lead'] ?? '' }}</p>

        <div class="mt-6 flex flex-wrap gap-3">
            <a href="{{ route('results.index') }}" class="home-btn rounded-lg bg-amber-400 px-4 py-2 font-bold text-slate-950">
                {{ $text['cta_results'] ?? trans('home.cta_results') }}
            </a>
            <a href="{{ route('ticket-check') }}" class="home-btn rounded-lg border border-amber-400/60 px-4 py-2 font-bold text-amber-200">
                {{ $text['cta_check'] ?? trans('home.cta_check') }}
            </a>
            <a href="{{ route('sales-points') }}" class="home-btn rounded-lg border border-slate-700 px-4 py-2 font-bold text-slate-200">
                {{ $text['cta_sales'] ?? trans('home.sales_title') }}
            </a>
        </div>
    </section>

    {{-- ----------------------------------------------------------------
         Current verified result
    ----------------------------------------------------------------- --}}
    <section id="current-result" aria-labelledby="current-result-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="current-result-title" class="text-2xl font-bold text-white">
                {{ $text['current_result_title'] ?? trans('home.current_result_title') }}
            </h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $currentResult['status'] ?? 'UNAVAILABLE' }}">
                {{ match ($currentResult['status'] ?? 'UNAVAILABLE') {
                    'VERIFIED' => trans('home.result_status_verified'),
                    'AVAILABLE' => trans('home.result_status_available'),
                    'NO_VERIFIED_RESULT' => trans('home.result_status_missing'),
                    default => trans('home.result_status_unavailable'),
                } }}
            </span>
        </div>

        @if (($currentResult['has_result'] ?? false) === true)
            <dl class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['draw_label'] ?? trans('home.draw_label') }}</dt>
                    <dd class="text-lg font-semibold text-white">{{ $currentResult['draw_number'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['source_label'] ?? trans('home.source_label') }}</dt>
                    <dd class="text-lg font-semibold text-white" data-source-state="{{ $currentResult['source_state'] ?? 'UNAVAILABLE' }}">
                        {{ match ($currentResult['source_state'] ?? 'UNAVAILABLE') {
                    'OFFICIAL_SOURCE_VERIFIED' => trans('home.source_status_verified'),
                    'OFFICIAL_SOURCE_CONFIGURED' => trans('home.source_status_configured'),
                    'INTERNAL_RECONCILED' => trans('home.source_status_reconciled'),
                    'FIXTURE_ONLY' => trans('home.source_status_fixture'),
                    'NOT_CONFIGURED' => trans('home.source_status_not_configured'),
                    default => trans('home.source_status_unavailable'),
                } }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['prize_1st_label'] ?? trans('home.prize_1st_label') }}</dt>
                    {{-- Printed as the stored string: a six digit number with a
                         leading zero must never be cast to int for display. --}}
                    <dd class="font-mono text-3xl font-black tracking-[0.3em] text-amber-300">{{ $currentResult['first_prize'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['prize_2nd_label'] ?? trans('home.prize_2nd_label') }}</dt>
                    <dd class="font-mono text-lg text-slate-100">
                        @forelse (($currentResult['second_prize'] ?? []) as $value)
                            <span class="mr-2 inline-block">{{ $value }}</span>
                        @empty
                            —
                        @endforelse
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['prize_last2_label'] ?? trans('home.prize_last2_label') }}</dt>
                    <dd class="font-mono text-lg text-slate-100">
                        @forelse (($currentResult['last_two'] ?? []) as $value)
                            <span class="mr-2 inline-block">{{ $value }}</span>
                        @empty
                            —
                        @endforelse
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ trans('home.result_version_label') }}</dt>
                    <dd class="text-lg text-slate-100">{{ $currentResult['result_version'] ?? '—' }}</dd>
                </div>
            </dl>
        @else
            <p class="mt-4 text-slate-300">
                {{ ($currentResult['status'] ?? '') === 'NO_VERIFIED_RESULT' ? trans('home.current_result_none') : trans('home.current_result_unavailable') }}
            </p>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         Next draw + countdown.

         The countdown is progressive enhancement only. The absolute instant is
         always printed in a <time> element and restated for screen readers, so
         the page remains correct with JavaScript disabled.
    ----------------------------------------------------------------- --}}
    <section id="next-draw" aria-labelledby="next-draw-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="next-draw-title" class="text-2xl font-bold text-white">
                {{ $text['next_draw_title'] ?? trans('home.next_draw_title') }}
            </h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $nextDraw['status'] ?? 'UNAVAILABLE' }}">
                {{ ($nextDraw['status'] ?? '') === 'AVAILABLE' ? trans('home.countdown.status_available') : trans('home.countdown.status_unavailable') }}
            </span>
        </div>

        @if ($countdownTarget !== null)
            <p class="mt-4 text-slate-300">
                <time datetime="{{ $countdownTarget }}">{{ $countdownDisplay ?? $countdownTarget }}</time>
                <span class="ml-2 text-xs uppercase tracking-widest text-slate-400">
                    {{ trans('home.timezone_label') }}: {{ $countdownZone }}
                </span>
            </p>

            <p class="visually-hidden">
                {{ trans('home.time_remaining_label') }}:
                {{ trans('home.scheduled_draw_for') }}
                {{ $countdownDisplay ?? $countdownTarget }} ({{ $countdownZone }}).
            </p>

            <div class="mt-4 flex flex-wrap items-center gap-4"
                 data-home-countdown
                 data-target="{{ $countdownTarget }}"
                 data-reached-label="{{ trans('home.countdown.reached') }}"
                 data-timezone="{{ $countdownZone }}"
                 role="timer"
                 aria-live="polite"
                 aria-atomic="true">
                <span class="home-countdown__cell"><strong id="cd-days">--</strong> <small>{{ trans('home.countdown.days') }}</small></span>
                <span class="home-countdown__cell"><strong id="cd-hours">--</strong> <small>{{ trans('home.countdown.hours') }}</small></span>
                <span class="home-countdown__cell"><strong id="cd-minutes">--</strong> <small>{{ trans('home.countdown.minutes') }}</small></span>
                <span class="home-countdown__cell"><strong id="cd-seconds">--</strong> <small>{{ trans('home.countdown.seconds') }}</small></span>
            </div>
        @else
            <p class="mt-4 text-slate-300">{{ trans('home.next_draw_unavailable') }}</p>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         Live draw. Provider URLs are normalized by LiveStreamEmbedResolver;
         only an allowlisted, canonical embed URL is allowed into this iframe.
         An absent/invalid provider remains an explicit NOT_CONFIGURED state.
    ----------------------------------------------------------------- --}}
    <section id="live-draw" aria-labelledby="live-draw-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6"
             data-home-live-draw
             data-live-status="{{ $liveDraw['live_status'] ?? 'not_configured' }}"
             data-replay-status="{{ $liveDraw['replay_status'] ?? 'not_configured' }}">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="live-draw-title" class="text-2xl font-bold text-white">
                {{ $text['live_title'] ?? trans('home.live_title') }}
            </h2>
            <span class="home-badge rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-200"
                  data-live-status="{{ $liveDraw['status'] ?? 'UNAVAILABLE' }}">
                {{ match ($liveDraw['status'] ?? 'UNAVAILABLE') {
                    'CONFIGURED' => trans('home.live_status_configured'),
                    'NOT_CONFIGURED' => trans('home.live_status_not_configured'),
                    default => trans('home.live_status_unavailable'),
                } }}
            </span>
        </div>

        @if (($liveDraw['live_status'] ?? 'not_configured') === 'configured' && is_string($liveDraw['embed_url'] ?? null))
            <div class="mt-5 aspect-video overflow-hidden rounded-xl border border-slate-700 bg-black" data-live-frame-host>
                <iframe
                    class="h-full w-full"
                    src="{{ $liveDraw['embed_url'] }}"
                    title="{{ trans('home.live_iframe_title', ['provider' => $liveDraw['provider'] ?? trans('home.stream_provider_unspecified')]) }}"
                    loading="lazy"
                    referrerpolicy="strict-origin-when-cross-origin"
                    sandbox="allow-scripts allow-same-origin allow-presentation allow-popups"
                    allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
                    allowfullscreen></iframe>
            </div>
            <p class="mt-3 text-xs text-slate-400">{{ trans('home.stream_provider_label') }}: {{ $liveDraw['provider'] ?? trans('home.stream_provider_unspecified') }}. {{ trans('home.stream_url_disclaimer') }}</p>
        @else
            <p class="mt-4 text-slate-300" role="status">{{ ($liveDraw['live_status'] ?? '') === 'not_configured' ? trans('home.live_not_configured') : trans('home.live_unavailable') }}@if (($liveDraw['live_status'] ?? '') === 'not_configured') <span class="sr-only">LIVE DRAW NOT_CONFIGURED</span>@endif</p>
        @endif

        @if (($liveDraw['replay_status'] ?? 'not_configured') === 'configured')
            <p class="mt-2 text-xs text-slate-400">{{ trans('home.replay_catalogue_status') }}</p>
        @else
            <p class="mt-2 text-xs text-slate-400">{{ trans('home.replay_not_configured') }}</p>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         Lane results digest: the four public result lanes.
    ----------------------------------------------------------------- --}}
    <section id="lane-results" aria-labelledby="lane-results-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <h2 id="lane-results-title" class="text-2xl font-bold text-white">
            {{ $text['lane_results_title'] ?? trans('home.lane_results_title') }}
        </h2>

        <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
            @foreach (($laneResults['lanes'] ?? []) as $lane)
                @php
                    $laneDateDisplay = match (app()->getLocale()) {
                        'th' => $lane['date_display_th'] ?? $lane['date_iso'] ?? null,
                        'bn' => $lane['date_display_bn'] ?? $lane['date_iso'] ?? null,
                        default => $lane['date_display_en'] ?? $lane['date_iso'] ?? null,
                    };
                @endphp
                <article class="home-lane rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <div class="flex items-baseline justify-between gap-3">
                        <h3 class="text-lg font-bold text-white">
                            <a href="{{ $lane['route'] }}" class="hover:text-amber-200">{{ trans($lane['title_key']) }}</a>
                        </h3>
                        <span class="text-[11px] font-bold tracking-widest text-slate-400" data-status="{{ $lane['status'] }}">
                            {{ $sectionStatusLabels[($lane['available'] ?? false) ? 'AVAILABLE' : 'UNAVAILABLE'] }}
                        </span>
                    </div>

                    @if (($lane['available'] ?? false) === true)
                        <p class="mt-1 text-xs text-slate-400">
                            {{ $lane['reference'] }}
                            @if (! empty($lane['date_iso']))
                                · <time datetime="{{ $lane['date_iso'] }}">{{ $laneDateDisplay ?? $lane['date_iso'] }}</time>
                            @endif
                        </p>

                        <dl class="mt-3 grid grid-cols-2 gap-3">
                            @foreach (($lane['fields'] ?? []) as $field)
                                <div>
                                    <dt class="text-[11px] uppercase tracking-widest text-slate-500">{{ trans($field['label_key']) }}</dt>
                                    <dd class="font-mono text-base text-amber-200">
                                        {{ $field['value'] ?? ($text['lane_results_field_none'] ?? '—') }}
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @else
                        <p class="mt-3 text-sm text-slate-400">{{ $text['lane_results_none'] ?? trans('home.lane_results_none') }}</p>
                    @endif
                </article>
            @endforeach
        </div>
    </section>

    {{-- ----------------------------------------------------------------
         Ticket check CTA. The public checker is a real form page, not a link
         into the JSON API.
    ----------------------------------------------------------------- --}}
    <section id="check" aria-labelledby="check-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <h2 id="check-title" class="text-2xl font-bold text-white">{{ $text['check_title'] ?? trans('home.check_title') }}</h2>
        <p class="mt-2 text-slate-300">{{ $text['check_help'] ?? '' }}</p>

        <form action="{{ route('ticket-check') }}" method="GET" class="mt-4 flex flex-wrap items-end gap-3">
            <div class="flex flex-col gap-1">
                <label for="home-check-number" class="text-xs uppercase tracking-widest text-slate-400">
                    {{ $text['check_label'] ?? trans('home.check_label') }}
                </label>
                <input id="home-check-number"
                       name="number"
                       type="text"
                       inputmode="numeric"
                       pattern="[0-9]{6}"
                       maxlength="6"
                       autocomplete="off"
                       placeholder="{{ $text['check_placeholder'] ?? '000000' }}"
                       class="rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-lg tracking-[0.3em] text-white">
            </div>
            <button type="submit" class="rounded-lg bg-amber-400 px-4 py-2 font-bold text-slate-950">
                {{ $text['check_button'] ?? trans('home.check_button') }}
            </button>
        </form>
    </section>

    {{-- ----------------------------------------------------------------
         Sales points CTA
    ----------------------------------------------------------------- --}}
    <section id="sales-points" aria-labelledby="sales-points-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <h2 id="sales-points-title" class="text-2xl font-bold text-white">{{ $text['sales_title'] ?? trans('home.sales_title') }}</h2>
        <p class="mt-2 text-slate-300">{{ $text['sales_help'] ?? '' }}</p>
        <a href="{{ route('sales-points') }}" class="mt-4 inline-block rounded-lg border border-amber-400/60 px-4 py-2 font-bold text-amber-200">
            {{ $text['cta_sales'] ?? trans('home.sales_search_button') }}
        </a>
    </section>

    {{-- ----------------------------------------------------------------
         Product catalogue. Ticket prices come from the GLO product summary,
         never from page copy.
    ----------------------------------------------------------------- --}}
    <section id="products" aria-labelledby="products-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="products-title" class="text-2xl font-bold text-white">{{ $text['products_title'] ?? trans('home.products_title') }}</h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $productSummary['status'] ?? 'UNAVAILABLE' }}">
                {{ $sectionStatusLabels[$productSummary['status'] ?? 'UNAVAILABLE'] ?? $sectionStatusLabels['UNAVAILABLE'] }}
            </span>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
            @forelse (($productSummary['products'] ?? []) as $product)
                <article class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <h3 class="text-lg font-bold text-white">{{ match ($product['code'] ?? '') {
                        'L6' => trans('home.product_l6_name'),
                        'N3' => trans('home.product_n3_name'),
                        default => $product['name'] ?? $product['code'] ?? trans('home.product_name_generic'),
                    } }}</h3>
                    <p class="mt-1 text-xs uppercase tracking-widest text-slate-500">{{ $product['code'] }}</p>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-slate-400">{{ trans('home.ticket_price_label') }}</dt>
                            <dd class="font-mono text-amber-200">{{ $product['ticket_price'] }} {{ $product['currency'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">{{ trans('home.digits_label') }}</dt>
                            <dd class="font-mono text-slate-100">{{ $product['digits'] }}</dd>
                        </div>
                    </dl>
                    @if (! empty($product['notes']))
                        <p class="mt-3 text-xs text-slate-400">{{ match ($product['code'] ?? '') {
                            'L6' => trans('home.product_l6_note'),
                            'N3' => trans('home.product_n3_note'),
                            default => $product['notes'],
                        } }}</p>
                    @endif
                </article>
            @empty
                <p class="text-slate-300">{{ trans('home.no_products_published') }}</p>
            @endforelse
        </div>
    </section>

    {{-- ----------------------------------------------------------------
         Prize highlight. CATALOGUE_ONLY means "this is the published prize
         schedule", not "this is a won amount".
    ----------------------------------------------------------------- --}}
    <section id="prize" aria-labelledby="prize-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="prize-title" class="text-2xl font-bold text-white">{{ $text['prize_title'] ?? trans('home.prize_title') }}</h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $prize['status'] ?? 'UNAVAILABLE' }}">
                {{ $sectionStatusLabels[$prize['status'] ?? 'UNAVAILABLE'] ?? $sectionStatusLabels['UNAVAILABLE'] }}
            </span>
        </div>

        @if (! empty($prize['amount']))
            <p class="mt-4 text-sm uppercase tracking-widest text-slate-400">{{ trans('home.prize_1st_label') }}</p>
            <p class="font-mono text-4xl font-black text-amber-300">{{ $prize['amount'] }}</p>
            @if (! empty($prize['draw_number']))
                <p class="mt-2 text-sm text-slate-400">{{ $text['draw_label'] ?? trans('home.draw_label') }}: {{ $prize['draw_number'] }}</p>
            @endif
        @endif

        @if (! empty($prize['message']))
            <p class="mt-3 text-slate-300">{{ trans('home.prize_unavailable') }}</p>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         Platform statistics. Every metric is a database count produced by
         GloPublicStatsService; an unavailable metric says so.
    ----------------------------------------------------------------- --}}
    <section id="stats" aria-labelledby="stats-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="stats-title" class="text-2xl font-bold text-white">{{ $text['stats_title'] ?? trans('home.stats_title') }}</h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $stats['status'] ?? 'UNAVAILABLE' }}">
                {{ $sectionStatusLabels[$stats['status'] ?? 'UNAVAILABLE'] ?? $sectionStatusLabels['UNAVAILABLE'] }}
            </span>
        </div>

        <dl class="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
            @forelse (($stats['metrics'] ?? []) as $metric)
                <div class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $metric['label'] }}</dt>
                    <dd class="mt-1 font-mono text-2xl font-black text-white">
                        {{ ($metric['available'] ?? false) ? $metric['value'] : ($text['stats_unavailable'] ?? '—') }}
                    </dd>
                </div>
            @empty
                <p class="text-slate-300">{{ trans('home.stats_unavailable') }}</p>
            @endforelse
        </dl>

        @if (! empty($stats['generated_at']))
            <p class="mt-3 text-xs text-slate-500">
                {{ $text['generated_label'] ?? trans('home.generated_label') }}:
                <time datetime="{{ $stats['generated_at'] }}">{{ $stats['generated_at'] }}</time>
                · {{ $text['stats_database'] ?? trans('home.stats_database') }}
            </p>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         Campaigns. Config driven, window filtered, escaped on output.
    ----------------------------------------------------------------- --}}
    <section id="bonuses" aria-labelledby="bonuses-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <h2 id="bonuses-title" class="text-2xl font-bold text-white">{{ $text['bonuses_title'] ?? trans('home.bonuses_title') }}</h2>

        @php($campaigns = $bonuses['campaigns'] ?? [])

        @if (count($campaigns) > 0)
            <ul class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ($campaigns as $campaign)
                    <li class="rounded-xl border border-slate-800 bg-slate-950/50 p-4" data-campaign-id="{{ $campaign['id'] }}">
                        <h3 class="text-lg font-bold text-white">{{ $campaign['title'] }}</h3>
                        @if (! empty($campaign['description']))
                            <p class="mt-1 text-sm text-slate-300">{{ $campaign['description'] }}</p>
                        @endif
                        <p class="mt-2 text-xs text-slate-500">
                            <time datetime="{{ $campaign['valid_from'] }}">{{ $campaign['valid_from'] }}</time>
                            —
                            <time datetime="{{ $campaign['valid_until'] }}">{{ $campaign['valid_until'] }}</time>
                        </p>
                        @if (! empty($campaign['cta_url']) && ! empty($campaign['cta_label']))
                            <a href="{{ $campaign['cta_url'] }}" class="mt-3 inline-block text-sm font-bold text-amber-200">
                                {{ $campaign['cta_label'] }}
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-4 text-slate-300">
                {{ trans('home.bonuses_empty') }}
            </p>
        @endif

        <div class="mt-6 border-t border-slate-800 pt-5" aria-labelledby="program-info-title">
            <h3 id="program-info-title" class="text-lg font-bold text-white">{{ trans('home.program_info_title') }}</h3>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <article class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <h4 class="font-bold text-white">{{ trans('home.referral_info_title') }}</h4>
                    <p class="mt-2 text-sm text-slate-300">{{ trans('home.referral_info_body') }}</p>
                    @guest
                        <a class="mt-3 inline-block text-sm font-bold text-amber-200" href="{{ route('register') }}">{{ trans('home.referral_info_link') }}</a>
                    @else
                        <p class="mt-3 text-xs text-slate-400">{{ trans('home.referral_info_existing_account') }}</p>
                    @endguest
                </article>
                <article class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <h4 class="font-bold text-white">{{ trans('home.affiliate_info_title') }}</h4>
                    <p class="mt-2 text-sm text-slate-300">{{ trans('home.affiliate_info_body') }}</p>
                    <a class="mt-3 inline-block text-sm font-bold text-amber-200" href="{{ route('discounts') }}#affiliate-commissions">{{ trans('home.affiliate_info_link') }}</a>
                </article>
            </div>
        </div>
    </section>

    {{-- ----------------------------------------------------------------
         Payment rails. Only publicly safe fields are projected: a gateway's
         label and status. Keys and secrets are never read by this view.
    ----------------------------------------------------------------- --}}
    <section id="payments" aria-labelledby="payments-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="payments-title" class="text-2xl font-bold text-white">{{ $text['payments_title'] ?? trans('home.payments_title') }}</h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $payments['status'] ?? 'UNAVAILABLE' }}">
                {{ $sectionStatusLabels[$payments['status'] ?? 'UNAVAILABLE'] ?? $sectionStatusLabels['UNAVAILABLE'] }}
            </span>
        </div>

        @php($methods = $payments['methods'] ?? [])

        @if (count($methods) > 0)
            <ul class="mt-5 flex flex-wrap gap-3">
                @foreach ($methods as $method)
                    <li class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3" data-method="{{ $method['code'] }}">
                        <span class="font-bold text-white">{{ $method['label'] }}</span>
                        <span class="ml-2 text-[11px] font-bold tracking-widest text-slate-400" data-status="{{ $method['status'] }}">{{ $sectionStatusLabels[$method['status'] ?? 'UNAVAILABLE'] ?? $sectionStatusLabels['UNAVAILABLE'] }}</span>
                        @if (! empty($method['currencies']))
                            <span class="ml-2 text-xs text-slate-500">{{ implode(', ', $method['currencies']) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-4 text-slate-300">
                {{ trans('home.payments_empty') }}
            </p>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         Support. Contact details are config only; nothing is invented.
    ----------------------------------------------------------------- --}}
    <section id="support" aria-labelledby="support-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="support-title" class="text-2xl font-bold text-white">{{ $text['support_title'] ?? trans('home.support_title') }}</h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $support['status'] ?? 'UNAVAILABLE' }}">
                {{ $sectionStatusLabels[$support['status'] ?? 'UNAVAILABLE'] ?? $sectionStatusLabels['UNAVAILABLE'] }}
            </span>
        </div>

        <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            @if (! empty($support['email']))
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['email_label'] ?? trans('home.email_label') }}</dt>
                    <dd class="text-slate-100">{{ $support['email'] }}</dd>
                </div>
            @endif
            @if (! empty($support['phone']))
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['phone_label'] ?? trans('home.phone_label') }}</dt>
                    <dd class="text-slate-100">{{ $support['phone'] }}</dd>
                </div>
            @endif
            @if (! empty($support['hours']))
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['hours_label'] ?? trans('home.hours_label') }}</dt>
                    <dd class="text-slate-100">{{ $support['hours'] }}</dd>
                </div>
            @endif
        </dl>

        @if (! empty($support['message']))
            <p class="mt-3 text-slate-300">{{ trans('home.support_help') }}</p>
        @endif

        @if (! empty($support['route_url']))
            <a href="{{ $support['route_url'] }}" class="mt-4 inline-block rounded-lg border border-slate-700 px-4 py-2 font-bold text-slate-200">
                {{ $text['support_contact'] ?? trans('home.support_contact') }}
            </a>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         App links. A store button is rendered only for a URL that an operator
         actually configured and that passed scheme validation.
    ----------------------------------------------------------------- --}}
    <section id="app-links" aria-labelledby="app-links-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="app-links-title" class="text-2xl font-bold text-white">{{ $text['app_title'] ?? trans('home.app_title') }}</h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $appLinks['status'] ?? 'UNAVAILABLE' }}">
                {{ $sectionStatusLabels[$appLinks['status'] ?? 'UNAVAILABLE'] ?? $sectionStatusLabels['UNAVAILABLE'] }}
            </span>
        </div>

        @if (($appLinks['status'] ?? '') === 'CONFIGURED')
            <ul class="mt-4 flex flex-wrap gap-3">
                @if (! empty($appLinks['android']))
                    <li><a href="{{ $appLinks['android'] }}" rel="noopener noreferrer" class="rounded-lg border border-amber-400/60 px-4 py-2 font-bold text-amber-200">{{ trans('home.app.android_btn') }}</a></li>
                @endif
                @if (! empty($appLinks['ios']))
                    <li><a href="{{ $appLinks['ios'] }}" rel="noopener noreferrer" class="rounded-lg border border-amber-400/60 px-4 py-2 font-bold text-amber-200">{{ trans('home.app.ios_btn') }}</a></li>
                @endif
                @if (! empty($appLinks['pwa']))
                    <li><a href="{{ $appLinks['pwa'] }}" rel="noopener noreferrer" class="rounded-lg border border-slate-700 px-4 py-2 font-bold text-slate-200">{{ trans('home.app.pwa_btn') }}</a></li>
                @endif
            </ul>
        @else
            <p class="mt-4 text-slate-300">{{ trans('home.app_links_unavailable') }}</p>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         Trust. Each bullet states a property this codebase actually
         implements and tests. No certification is claimed.
    ----------------------------------------------------------------- --}}
    <section id="trust" aria-labelledby="trust-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <h2 id="trust-title" class="text-2xl font-bold text-white">{{ $text['trust_title'] ?? trans('home.trust_title') }}</h2>

        @if (! empty($trust['bullets']))
            <ul class="mt-4 grid grid-cols-1 gap-2 md:grid-cols-2">
                @foreach ($trust['bullets'] as $bullet)
                    <li class="flex gap-2 text-slate-300">
                        <span aria-hidden="true" class="text-amber-300">&bull;</span>
                        <span>{{ $bullet }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="mt-5 text-xs leading-relaxed text-slate-500">
            {{ $text['independent_disclaimer'] ?? trans('home.independent_disclaimer') }}
        </p>
    </section>

</div>
@endsection
