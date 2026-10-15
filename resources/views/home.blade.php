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
         which resolves each route defensively, so an unregistered route
         degrades to "#" instead of throwing a RouteNotFoundException.
    ----------------------------------------------------------------- --}}
    <nav class="home-nav flex flex-wrap items-center gap-3 border-b border-slate-800 pb-4"
         aria-label="{{ __('Primary') }}">
        <button type="button"
                id="btn-open-mobile-menu"
                class="home-nav__toggle sm:hidden rounded-lg border border-slate-700 px-3 py-2 text-sm text-slate-200"
                aria-controls="mobile-menu-drawer"
                aria-expanded="false">
            {{ __('Menu') }}
        </button>

        @foreach ($navigation as $item)
            <a href="{{ $item['url'] }}"
               class="home-nav__link rounded-lg px-3 py-2 text-sm font-semibold transition-colors {{ $item['active'] ? 'bg-amber-400/15 text-amber-200' : 'text-slate-300 hover:text-amber-200' }}"
               @if ($item['active']) aria-current="page" @endif>
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    <div id="mobile-menu-drawer"
         class="home-nav__drawer hidden"
         role="dialog"
         aria-modal="true"
         aria-label="{{ __('Menu') }}">
        <button type="button" id="btn-close-mobile-menu" class="home-nav__close">{{ __('Close') }}</button>
        <ul>
            @foreach ($navigation as $item)
                <li><a href="{{ $item['url'] }}">{{ $item['label'] }}</a></li>
            @endforeach
        </ul>
    </div>

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
                {{ $text['cta_results'] ?? __('Results') }}
            </a>
            <a href="{{ route('ticket-check') }}" class="home-btn rounded-lg border border-amber-400/60 px-4 py-2 font-bold text-amber-200">
                {{ $text['cta_check'] ?? __('Check ticket') }}
            </a>
            <a href="{{ route('sales-points') }}" class="home-btn rounded-lg border border-slate-700 px-4 py-2 font-bold text-slate-200">
                {{ $text['cta_sales'] ?? __('Sales points') }}
            </a>
        </div>
    </section>

    {{-- ----------------------------------------------------------------
         Current verified result
    ----------------------------------------------------------------- --}}
    <section id="current-result" aria-labelledby="current-result-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="current-result-title" class="text-2xl font-bold text-white">
                {{ $text['current_result_title'] ?? __('Latest verified result') }}
            </h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $currentResult['status'] ?? 'UNAVAILABLE' }}">
                {{ $currentResult['status'] ?? 'UNAVAILABLE' }}
            </span>
        </div>

        @if (($currentResult['has_result'] ?? false) === true)
            <dl class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['draw_label'] ?? __('Draw') }}</dt>
                    <dd class="text-lg font-semibold text-white">{{ $currentResult['draw_number'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['source_label'] ?? __('Source') }}</dt>
                    <dd class="text-lg font-semibold text-white" data-source-state="{{ $currentResult['source_state'] ?? 'UNAVAILABLE' }}">
                        {{ $currentResult['source_state'] ?? 'UNAVAILABLE' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['prize_1st_label'] ?? __('First prize') }}</dt>
                    {{-- Printed as the stored string: a six digit number with a
                         leading zero must never be cast to int for display. --}}
                    <dd class="font-mono text-3xl font-black tracking-[0.3em] text-amber-300">{{ $currentResult['first_prize'] ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['prize_2nd_label'] ?? __('Second prize') }}</dt>
                    <dd class="font-mono text-lg text-slate-100">
                        @forelse (($currentResult['second_prize'] ?? []) as $value)
                            <span class="mr-2 inline-block">{{ $value }}</span>
                        @empty
                            —
                        @endforelse
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['prize_last2_label'] ?? __('Last two digits') }}</dt>
                    <dd class="font-mono text-lg text-slate-100">
                        @forelse (($currentResult['last_two'] ?? []) as $value)
                            <span class="mr-2 inline-block">{{ $value }}</span>
                        @empty
                            —
                        @endforelse
                    </dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ __('Result version') }}</dt>
                    <dd class="text-lg text-slate-100">{{ $currentResult['result_version'] ?? '—' }}</dd>
                </div>
            </dl>
        @else
            <p class="mt-4 text-slate-300">
                {{ $currentResult['message'] ?? ($text['current_result_none'] ?? 'No verified result available') }}
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
                {{ $text['next_draw_title'] ?? __('Next draw') }}
            </h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $nextDraw['status'] ?? 'UNAVAILABLE' }}">
                {{ $nextDraw['status'] ?? 'UNAVAILABLE' }}
            </span>
        </div>

        @if ($countdownTarget !== null)
            <p class="mt-4 text-slate-300">
                <time datetime="{{ $countdownTarget }}">{{ $countdownDisplay ?? $countdownTarget }}</time>
                <span class="ml-2 text-xs uppercase tracking-widest text-slate-400">
                    {{ $text['timezone_label'] ?? __('Timezone') }}: {{ $countdownZone }}
                </span>
            </p>

            <p class="visually-hidden">
                {{ $text['time_remaining_label'] ?? __('Time remaining') }}:
                {{ __('the draw is scheduled for') }}
                {{ $countdownDisplay ?? $countdownTarget }} ({{ $countdownZone }}).
            </p>

            <div class="mt-4 flex flex-wrap items-center gap-4"
                 data-home-countdown
                 data-target="{{ $countdownTarget }}"
                 data-timezone="{{ $countdownZone }}"
                 role="timer"
                 aria-live="polite"
                 aria-atomic="true">
                <span class="home-countdown__cell"><strong id="cd-days">--</strong> <small>{{ __('days') }}</small></span>
                <span class="home-countdown__cell"><strong id="cd-hours">--</strong> <small>{{ __('hours') }}</small></span>
                <span class="home-countdown__cell"><strong id="cd-minutes">--</strong> <small>{{ __('minutes') }}</small></span>
                <span class="home-countdown__cell"><strong id="cd-seconds">--</strong> <small>{{ __('seconds') }}</small></span>
            </div>
        @else
            <p class="mt-4 text-slate-300">{{ $nextDraw['message'] ?? ($text['next_draw_none'] ?? __('No scheduled draw.')) }}</p>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         Live draw.

         No embed is ever rendered from an unverified source: when no authorised
         stream is configured the card states that plainly. There is deliberately
         no <iframe> fallback to a third party player.
    ----------------------------------------------------------------- --}}
    <section id="live-draw" aria-labelledby="live-draw-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <h2 id="live-draw-title" class="text-2xl font-bold text-white">
            {{ $text['live_title'] ?? __('Live draw') }}
        </h2>

        <p class="mt-3 font-mono text-sm font-bold tracking-widest text-slate-200"
           data-live-status="{{ $liveDraw['status'] ?? 'UNAVAILABLE' }}">
            LIVE DRAW {{ $liveDraw['status'] ?? 'UNAVAILABLE' }}
        </p>

        @if (! empty($liveDraw['message']))
            <p class="mt-2 text-slate-300">{{ $liveDraw['message'] }}</p>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         Lane results digest: the four public result lanes.
    ----------------------------------------------------------------- --}}
    <section id="lane-results" aria-labelledby="lane-results-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <h2 id="lane-results-title" class="text-2xl font-bold text-white">
            {{ $text['lane_results_title'] ?? __('Latest Official Lottery Results') }}
        </h2>

        <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
            @foreach (($laneResults['lanes'] ?? []) as $lane)
                <article class="home-lane rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <div class="flex items-baseline justify-between gap-3">
                        <h3 class="text-lg font-bold text-white">
                            <a href="{{ $lane['route'] }}" class="hover:text-amber-200">{{ trans($lane['title_key']) }}</a>
                        </h3>
                        <span class="text-[11px] font-bold tracking-widest text-slate-400" data-status="{{ $lane['status'] }}">
                            {{ $lane['status'] }}
                        </span>
                    </div>

                    @if (($lane['available'] ?? false) === true)
                        <p class="mt-1 text-xs text-slate-400">
                            {{ $lane['reference'] }}
                            @if (! empty($lane['date_iso']))
                                · <time datetime="{{ $lane['date_iso'] }}">{{ $lane['date_display_en'] ?? $lane['date_iso'] }}</time>
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
                        <p class="mt-3 text-sm text-slate-400">{{ $text['lane_results_none'] ?? __('No published result yet.') }}</p>
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
        <h2 id="check-title" class="text-2xl font-bold text-white">{{ $text['check_title'] ?? __('Check your ticket') }}</h2>
        <p class="mt-2 text-slate-300">{{ $text['check_help'] ?? '' }}</p>

        <form action="{{ route('ticket-check') }}" method="GET" class="mt-4 flex flex-wrap items-end gap-3">
            <div class="flex flex-col gap-1">
                <label for="home-check-number" class="text-xs uppercase tracking-widest text-slate-400">
                    {{ $text['check_label'] ?? __('Ticket number') }}
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
                {{ $text['check_button'] ?? __('Check') }}
            </button>
        </form>
    </section>

    {{-- ----------------------------------------------------------------
         Sales points CTA
    ----------------------------------------------------------------- --}}
    <section id="sales-points" aria-labelledby="sales-points-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <h2 id="sales-points-title" class="text-2xl font-bold text-white">{{ $text['sales_title'] ?? __('Sales points') }}</h2>
        <p class="mt-2 text-slate-300">{{ $text['sales_help'] ?? '' }}</p>
        <a href="{{ route('sales-points') }}" class="mt-4 inline-block rounded-lg border border-amber-400/60 px-4 py-2 font-bold text-amber-200">
            {{ $text['cta_sales'] ?? __('Search sales points') }}
        </a>
    </section>

    {{-- ----------------------------------------------------------------
         Product catalogue. Ticket prices come from the GLO product summary,
         never from page copy.
    ----------------------------------------------------------------- --}}
    <section id="products" aria-labelledby="products-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="products-title" class="text-2xl font-bold text-white">{{ $text['products_title'] ?? __('Products') }}</h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $productSummary['status'] ?? 'UNAVAILABLE' }}">
                {{ $productSummary['status'] ?? 'UNAVAILABLE' }}
            </span>
        </div>

        <div class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
            @forelse (($productSummary['products'] ?? []) as $product)
                <article class="rounded-xl border border-slate-800 bg-slate-950/50 p-4">
                    <h3 class="text-lg font-bold text-white">{{ $product['name'] }}</h3>
                    <p class="mt-1 text-xs uppercase tracking-widest text-slate-500">{{ $product['code'] }}</p>
                    <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt class="text-slate-400">{{ __('Ticket price') }}</dt>
                            <dd class="font-mono text-amber-200">{{ $product['ticket_price'] }} {{ $product['currency'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-400">{{ __('Digits') }}</dt>
                            <dd class="font-mono text-slate-100">{{ $product['digits'] }}</dd>
                        </div>
                    </dl>
                    @if (! empty($product['notes']))
                        <p class="mt-3 text-xs text-slate-400">{{ $product['notes'] }}</p>
                    @endif
                </article>
            @empty
                <p class="text-slate-300">{{ $productSummary['message'] ?? __('No products are published.') }}</p>
            @endforelse
        </div>
    </section>

    {{-- ----------------------------------------------------------------
         Prize highlight. CATALOGUE_ONLY means "this is the published prize
         schedule", not "this is a won amount".
    ----------------------------------------------------------------- --}}
    <section id="prize" aria-labelledby="prize-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="prize-title" class="text-2xl font-bold text-white">{{ $text['prize_title'] ?? __('Prize highlight') }}</h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $prize['status'] ?? 'UNAVAILABLE' }}">
                {{ $prize['status'] ?? 'UNAVAILABLE' }}
            </span>
        </div>

        @if (! empty($prize['amount']))
            <p class="mt-4 text-sm uppercase tracking-widest text-slate-400">{{ $prize['label'] ?? __('Prize') }}</p>
            <p class="font-mono text-4xl font-black text-amber-300">{{ $prize['amount'] }}</p>
            @if (! empty($prize['draw_number']))
                <p class="mt-2 text-sm text-slate-400">{{ $text['draw_label'] ?? __('Draw') }}: {{ $prize['draw_number'] }}</p>
            @endif
        @endif

        @if (! empty($prize['message']))
            <p class="mt-3 text-slate-300">{{ $prize['message'] }}</p>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         Platform statistics. Every metric is a database count produced by
         GloPublicStatsService; an unavailable metric says so.
    ----------------------------------------------------------------- --}}
    <section id="stats" aria-labelledby="stats-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="stats-title" class="text-2xl font-bold text-white">{{ $text['stats_title'] ?? __('Platform statistics') }}</h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $stats['status'] ?? 'UNAVAILABLE' }}">
                {{ $stats['status'] ?? 'UNAVAILABLE' }}
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
                <p class="text-slate-300">{{ $stats['message'] ?? ($text['stats_unavailable'] ?? __('Statistics are unavailable.')) }}</p>
            @endforelse
        </dl>

        @if (! empty($stats['generated_at']))
            <p class="mt-3 text-xs text-slate-500">
                {{ $text['generated_label'] ?? __('Generated') }}:
                <time datetime="{{ $stats['generated_at'] }}">{{ $stats['generated_at'] }}</time>
                · {{ $text['stats_database'] ?? __('Source: application database') }}
            </p>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         Campaigns. Config driven, window filtered, escaped on output.
    ----------------------------------------------------------------- --}}
    <section id="bonuses" aria-labelledby="bonuses-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <h2 id="bonuses-title" class="text-2xl font-bold text-white">{{ $text['bonuses_title'] ?? __('Promotions') }}</h2>

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
                {{ ($bonuses['message'] ?? '') !== '' ? $bonuses['message'] : ($text['bonuses_empty'] ?? 'No active promotions') }}
            </p>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         Payment rails. Only publicly safe fields are projected: a gateway's
         label and status. Keys and secrets are never read by this view.
    ----------------------------------------------------------------- --}}
    <section id="payments" aria-labelledby="payments-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="payments-title" class="text-2xl font-bold text-white">{{ $text['payments_title'] ?? __('Payment methods') }}</h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $payments['status'] ?? 'UNAVAILABLE' }}">
                {{ $payments['status'] ?? 'UNAVAILABLE' }}
            </span>
        </div>

        @php($methods = $payments['methods'] ?? [])

        @if (count($methods) > 0)
            <ul class="mt-5 flex flex-wrap gap-3">
                @foreach ($methods as $method)
                    <li class="rounded-xl border border-slate-800 bg-slate-950/50 px-4 py-3" data-method="{{ $method['code'] }}">
                        <span class="font-bold text-white">{{ $method['label'] }}</span>
                        <span class="ml-2 text-[11px] font-bold tracking-widest text-slate-400">{{ $method['status'] }}</span>
                        @if (! empty($method['currencies']))
                            <span class="ml-2 text-xs text-slate-500">{{ implode(', ', $method['currencies']) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-4 text-slate-300">
                {{ ($payments['message'] ?? '') !== '' ? $payments['message'] : ($text['payments_empty'] ?? __('No payment methods are publicly available yet.')) }}
            </p>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         Support. Contact details are config only; nothing is invented.
    ----------------------------------------------------------------- --}}
    <section id="support" aria-labelledby="support-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="support-title" class="text-2xl font-bold text-white">{{ $text['support_title'] ?? __('Support') }}</h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $support['status'] ?? 'UNAVAILABLE' }}">
                {{ $support['status'] ?? 'UNAVAILABLE' }}
            </span>
        </div>

        <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            @if (! empty($support['email']))
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['email_label'] ?? __('Email') }}</dt>
                    <dd class="text-slate-100">{{ $support['email'] }}</dd>
                </div>
            @endif
            @if (! empty($support['phone']))
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['phone_label'] ?? __('Phone') }}</dt>
                    <dd class="text-slate-100">{{ $support['phone'] }}</dd>
                </div>
            @endif
            @if (! empty($support['hours']))
                <div>
                    <dt class="text-xs uppercase tracking-widest text-slate-400">{{ $text['hours_label'] ?? __('Hours') }}</dt>
                    <dd class="text-slate-100">{{ $support['hours'] }}</dd>
                </div>
            @endif
        </dl>

        @if (! empty($support['message']))
            <p class="mt-3 text-slate-300">{{ $support['message'] }}</p>
        @endif

        @if (! empty($support['route_url']))
            <a href="{{ $support['route_url'] }}" class="mt-4 inline-block rounded-lg border border-slate-700 px-4 py-2 font-bold text-slate-200">
                {{ $text['support_contact'] ?? __('Contact us') }}
            </a>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         App links. A store button is rendered only for a URL that an operator
         actually configured and that passed scheme validation.
    ----------------------------------------------------------------- --}}
    <section id="app-links" aria-labelledby="app-links-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 id="app-links-title" class="text-2xl font-bold text-white">{{ $text['app_title'] ?? __('Mobile app') }}</h2>
            <span class="home-status rounded-full border border-slate-700 px-3 py-1 text-xs font-bold tracking-widest text-slate-300"
                  data-status="{{ $appLinks['status'] ?? 'UNAVAILABLE' }}">
                {{ $appLinks['status'] ?? 'UNAVAILABLE' }}
            </span>
        </div>

        @if (($appLinks['status'] ?? '') === 'CONFIGURED')
            <ul class="mt-4 flex flex-wrap gap-3">
                @if (! empty($appLinks['android']))
                    <li><a href="{{ $appLinks['android'] }}" rel="noopener noreferrer" class="rounded-lg border border-amber-400/60 px-4 py-2 font-bold text-amber-200">{{ __('Android download') }}</a></li>
                @endif
                @if (! empty($appLinks['ios']))
                    <li><a href="{{ $appLinks['ios'] }}" rel="noopener noreferrer" class="rounded-lg border border-amber-400/60 px-4 py-2 font-bold text-amber-200">{{ __('iOS download') }}</a></li>
                @endif
                @if (! empty($appLinks['pwa']))
                    <li><a href="{{ $appLinks['pwa'] }}" rel="noopener noreferrer" class="rounded-lg border border-slate-700 px-4 py-2 font-bold text-slate-200">{{ __('Install web app') }}</a></li>
                @endif
            </ul>
        @else
            <p class="mt-4 text-slate-300">{{ $appLinks['message'] ?? 'App download links are not configured.' }}</p>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         Trust. Each bullet states a property this codebase actually
         implements and tests. No certification is claimed.
    ----------------------------------------------------------------- --}}
    <section id="trust" aria-labelledby="trust-title" class="home-section rounded-2xl border border-slate-800 bg-slate-900/40 p-6">
        <h2 id="trust-title" class="text-2xl font-bold text-white">{{ $text['trust_title'] ?? __('How this platform protects you') }}</h2>

        <ul class="mt-4 grid grid-cols-1 gap-2 md:grid-cols-2">
            @foreach (($trust['bullets'] ?? []) as $bullet)
                <li class="flex gap-2 text-slate-300">
                    <span aria-hidden="true" class="text-amber-300">&bull;</span>
                    <span>{{ $bullet }}</span>
                </li>
            @endforeach
        </ul>

        <p class="mt-5 text-xs leading-relaxed text-slate-500">
            {{ __('Draw rules and prize tables follow Government Lottery Office publications; this site is not the official GLO website and is not affiliated with it.') }}
        </p>
    </section>

</div>
@endsection
