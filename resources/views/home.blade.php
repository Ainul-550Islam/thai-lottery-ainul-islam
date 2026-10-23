@extends('layouts.app')

{{--
    FLAGSHIP PUBLIC HOME PAGE (Premium Frontend Design V2).

    Every section on this page is rendered from HomePageDataService. Blade runs
    no queries, invents no numbers and hardcodes no marketing claim.

    Contract honoured here:
      * Each section prints the explicit status string the service produced
        (VERIFIED / CONFIGURED / AVAILABLE / CATALOGUE_ONLY / NO_VERIFIED_RESULT
        / NOT_CONFIGURED / UNAVAILABLE). Nothing relies on truthy / falsey.
      * An unconfigured section renders its honest message, never a placeholder
        that looks like real data.
      * All interpolation uses {{ }}, so configured campaign copy cannot inject
        markup.
      * Six-digit and multi-digit lottery numbers are rendered strictly as
        strings so leading zeroes (e.g. 000001, 012345) are never stripped.
      * The countdown is progressive enhancement only: an absolute <time>
        element, explicit timezone, and screen-reader sentence always accompany it.
--}}

@section('title', $home['text']['meta_title'] ?? config('app.name'))
@section('meta_description', $home['text']['meta_description'] ?? '')

@push('styles')
    @php
        $homeViteEntries = ['resources/css/home.css', 'resources/js/home/countdown.js', 'resources/js/home/live-draw.js'];
        $homeViteHtml = (string) app(\Illuminate\Foundation\Vite::class)($homeViteEntries);
        if ($homeViteHtml === '' && is_file(public_path('build/manifest.json'))) {
            $homeViteHtml = (string) (new \Illuminate\Foundation\Vite)($homeViteEntries);
        }
    @endphp
    {!! $homeViteHtml !!}
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

    $countdownTarget = $countdown['scheduled_at_iso']
        ?? ($nextDraw['scheduled_at_iso'] ?? ($hero['next_draw_iso'] ?? null));
    $countdownZone = $countdown['timezone']
        ?? ($nextDraw['timezone'] ?? ($hero['timezone'] ?? 'Asia/Bangkok'));
    $countdownDisplay = $nextDraw['scheduled_at_display'] ?? null;
    if (is_string($countdownTarget) && $countdownTarget !== '') {
        try {
            $parsedTarget = \Carbon\CarbonImmutable::parse($countdownTarget)->setTimezone($countdownZone);
            if (app()->getLocale() === 'th') {
                $thaiMonths = [
                    1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
                    5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
                    9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม',
                ];
                $countdownDisplay = sprintf(
                    '%d %s %d, %s %s',
                    $parsedTarget->day,
                    $thaiMonths[$parsedTarget->month] ?? $parsedTarget->format('F'),
                    $parsedTarget->year + 543,
                    $parsedTarget->format('H:i'),
                    $parsedTarget->format('T')
                );
            } elseif (app()->getLocale() === 'en') {
                $countdownDisplay = $parsedTarget->format('j F Y, H:i T');
            }
        } catch (\Throwable) {
            // Preserve upstream display string if target cannot be parsed.
        }
    }

    $firstPrizeRaw = is_string($currentResult['first_prize'] ?? null) ? trim($currentResult['first_prize']) : '';
    $firstPrizeChars = ($firstPrizeRaw !== '' && preg_match('/^[0-9]+$/', $firstPrizeRaw) === 1)
        ? str_split($firstPrizeRaw)
        : [];
@endphp

@section('content')
<div class="home-page">

    {{-- ----------------------------------------------------------------
         1. Section Sub-Navigation (resolved defensively by HomePageDataService)
    ----------------------------------------------------------------- --}}
    <nav class="home-nav" aria-label="{{ trans('home.navigation_label') }}">
        @foreach ($navigation as $item)
            <a href="{{ $item['url'] }}"
               class="home-nav__link"
               @if ($item['active']) aria-current="page" @endif>
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    {{-- ----------------------------------------------------------------
         2. Flagship Luxury Dark-Gold Hero Showcase
    ----------------------------------------------------------------- --}}
    <section id="hero" aria-labelledby="hero-title" class="home-hero">
        <div class="grid grid-cols-1 gap-8 lg:grid-cols-12 lg:items-center">
            <div class="lg:col-span-7 space-y-5">
                <div class="inline-flex flex-wrap items-center gap-2">
                    <span class="tl-v2-pill tl-v2-pill--configured">
                        {{ config('app.name', 'Thai Lottery') }}
                    </span>
                    <span class="tl-v2-pill tl-v2-pill--neutral font-mono">
                        {{ trans('home.timezone_label') }}: {{ $countdownZone }}
                    </span>
                </div>

                <h1 id="hero-title" class="text-3xl sm:text-4xl lg:text-5xl font-black tracking-tight text-[#FFF6D6] leading-tight">
                    {{ $text['hero_title'] ?? config('app.name') }}
                </h1>

                <p class="max-w-2xl text-base sm:text-lg text-[#D4CAB3] leading-relaxed">
                    {{ $text['hero_lead'] ?? '' }}
                </p>

                <div class="pt-1 flex flex-wrap items-center gap-3">
                    <a href="{{ route('results.index') }}" class="tl-v2-btn-gold">
                        <span>{{ $text['cta_results'] ?? trans('home.cta_results') }}</span>
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                    </a>
                    <a href="{{ route('ticket-check') }}" class="tl-v2-btn-outline">
                        <span>{{ $text['cta_check'] ?? trans('home.cta_check') }}</span>
                    </a>
                    <a href="{{ route('sales-points') }}" class="tl-v2-btn-outline">
                        <span>{{ $text['cta_sales'] ?? trans('home.sales_title') }}</span>
                    </a>
                    @auth
                        <a href="{{ route('player.dashboard') }}" class="tl-v2-btn-outline">
                            <span>{{ __('player.nav_dashboard') }}</span>
                        </a>
                    @else
                        <a href="{{ route('login') }}" class="tl-v2-btn-outline">
                            <span>{{ $text['cta_sign_in'] ?? __('player.sign_in') }}</span>
                        </a>
                    @endauth
                </div>
            </div>

            {{-- Hero Telemetry Vault (Grounded strictly in server draw & prize state) --}}
            <div class="lg:col-span-5">
                <div class="tl-v2-subcard space-y-4 border-[#D4AF37]/35 bg-[#0B0904]/85 p-5 sm:p-6">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[#D4AF37]/15 pb-3">
                        <span class="tl-v2-eyebrow">{{ $text['next_draw_title'] ?? trans('home.next_draw_title') }}</span>
                        <span class="home-status" data-status="{{ $nextDraw['status'] ?? 'UNAVAILABLE' }}">
                            {{ ($nextDraw['status'] ?? '') === 'AVAILABLE' ? trans('home.countdown.status_available') : trans('home.countdown.status_unavailable') }}
                        </span>
                    </div>

                    @if ($countdownTarget !== null)
                        <div>
                            <p class="text-sm font-semibold text-[#FFF6D6]">
                                <time datetime="{{ $countdownTarget }}">{{ $countdownDisplay ?? $countdownTarget }}</time>
                            </p>
                            <p class="mt-1 text-xs text-[#9D937D]">
                                {{ trans('home.timezone_label') }}: {{ $countdownZone }}
                            </p>
                        </div>
                    @else
                        <p class="text-sm text-[#D4CAB3]">{{ trans('home.next_draw_unavailable') }}</p>
                    @endif

                    <div class="border-t border-[#D4AF37]/15 pt-3 flex items-center justify-between gap-3">
                        <div>
                            <span class="block text-xs text-[#9D937D]">{{ $text['prize_title'] ?? trans('home.prize_title') }}</span>
                            @if (! empty($prize['amount']))
                                <strong class="font-mono text-xl font-black text-[#FFF6D6]" data-tabular-nums>{{ $prize['amount'] }}</strong>
                            @else
                                <span class="text-sm text-[#D4CAB3]">{{ $sectionStatusLabels[$prize['status'] ?? 'UNAVAILABLE'] ?? $sectionStatusLabels['UNAVAILABLE'] }}</span>
                            @endif
                        </div>
                        <a href="{{ route('lotteries.index') }}" class="text-xs font-extrabold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4">
                            {{ __('player.nav_lotteries') }} &rarr;
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ----------------------------------------------------------------
         3. Current Verified Result & Upcoming Draw Countdown Grid
    ----------------------------------------------------------------- --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        {{-- Current verified result --}}
        <section id="current-result" aria-labelledby="current-result-title" class="home-section lg:col-span-7">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#D4AF37]/15 pb-4">
                <div>
                    <span class="tl-v2-eyebrow">{{ __('results.published_results') }}</span>
                    <h2 id="current-result-title" class="mt-1 text-2xl font-black text-[#FFF6D6]">
                        {{ $text['current_result_title'] ?? trans('home.current_result_title') }}
                    </h2>
                </div>
                <span class="home-status" data-status="{{ $currentResult['status'] ?? 'UNAVAILABLE' }}">
                    {{ match ($currentResult['status'] ?? 'UNAVAILABLE') {
                        'VERIFIED' => trans('home.result_status_verified'),
                        'AVAILABLE' => trans('home.result_status_available'),
                        'NO_VERIFIED_RESULT' => trans('home.result_status_missing'),
                        default => trans('home.result_status_unavailable'),
                    } }}
                </span>
            </div>

            @if (($currentResult['has_result'] ?? false) === true)
                <div class="mt-5 tl-v2-subcard border-[#D4AF37]/30">
                    <span class="block text-xs font-bold uppercase tracking-wider text-[#D4AF37]">
                        {{ $text['prize_1st_label'] ?? trans('home.prize_1st_label') }}
                    </span>
                    {{-- Contiguous string preserved verbatim so leading zeroes (e.g. 012345) remain copyable & screen-readable --}}
                    <p class="mt-2 font-mono text-3xl sm:text-4xl font-black tracking-[0.26em] text-[#FFF6D6]" data-tabular-nums>{{ $currentResult['first_prize'] ?? '—' }}</p>
                    @if ($firstPrizeChars !== [])
                        <div class="mt-3 tl-digit-strip" aria-hidden="true">
                            @foreach ($firstPrizeChars as $digitChar)
                                <span class="tl-lottery-ball-3d">{{ $digitChar }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="tl-v2-subcard">
                        <dt class="text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ $text['draw_label'] ?? trans('home.draw_label') }}</dt>
                        <dd class="mt-1 font-mono text-base font-bold text-[#FFF6D6]">{{ $currentResult['draw_number'] ?? '—' }}</dd>
                    </div>
                    <div class="tl-v2-subcard">
                        <dt class="text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ $text['source_label'] ?? trans('home.source_label') }}</dt>
                        <dd class="mt-1 text-sm font-bold text-[#F5E6B8]" data-source-state="{{ $currentResult['source_state'] ?? 'UNAVAILABLE' }}">
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
                    <div class="tl-v2-subcard">
                        <dt class="text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ $text['prize_2nd_label'] ?? trans('home.prize_2nd_label') }}</dt>
                        <dd class="mt-1 font-mono text-base font-semibold text-[#FAF6EC]" data-tabular-nums>
                            @forelse (($currentResult['second_prize'] ?? []) as $value)
                                <span class="mr-2 inline-block">{{ $value }}</span>
                            @empty
                                —
                            @endforelse
                        </dd>
                    </div>
                    <div class="tl-v2-subcard">
                        <dt class="text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ $text['prize_last2_label'] ?? trans('home.prize_last2_label') }}</dt>
                        <dd class="mt-1 font-mono text-base font-semibold text-[#FAF6EC]" data-tabular-nums>
                            @forelse (($currentResult['last_two'] ?? []) as $value)
                                <span class="mr-2 inline-block">{{ $value }}</span>
                            @empty
                                —
                            @endforelse
                        </dd>
                    </div>
                    <div class="tl-v2-subcard sm:col-span-2">
                        <dt class="text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ trans('home.result_version_label') }}</dt>
                        <dd class="mt-1 font-mono text-xs text-[#D4CAB3] break-all">{{ $currentResult['result_version'] ?? '—' }}</dd>
                    </div>
                </dl>
            @else
                <div class="mt-5 tl-v2-subcard border-dashed text-sm text-[#D4CAB3]" role="status">
                    {{ ($currentResult['status'] ?? '') === 'NO_VERIFIED_RESULT' ? trans('home.current_result_none') : trans('home.current_result_unavailable') }}
                </div>
            @endif
        </section>

        {{-- Next draw + countdown --}}
        <section id="next-draw" aria-labelledby="next-draw-title" class="home-section lg:col-span-5 flex flex-col justify-between">
            <div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#D4AF37]/15 pb-4">
                    <div>
                        <span class="tl-v2-eyebrow">{{ trans('home.timezone_label') }} · {{ $countdownZone }}</span>
                        <h2 id="next-draw-title" class="mt-1 text-2xl font-black text-[#FFF6D6]">
                            {{ $text['next_draw_title'] ?? trans('home.next_draw_title') }}
                        </h2>
                    </div>
                    <span class="home-status" data-status="{{ $nextDraw['status'] ?? 'UNAVAILABLE' }}">
                        {{ ($nextDraw['status'] ?? '') === 'AVAILABLE' ? trans('home.countdown.status_available') : trans('home.countdown.status_unavailable') }}
                    </span>
                </div>

                @if ($countdownTarget !== null)
                    <div class="mt-5 tl-v2-subcard">
                        <p class="text-sm font-semibold text-[#FFF6D6]">
                            <time datetime="{{ $countdownTarget }}">{{ $countdownDisplay ?? $countdownTarget }}</time>
                        </p>
                        <p class="mt-1 text-xs text-[#9D937D]">
                            {{ trans('home.timezone_label') }}: {{ $countdownZone }}
                        </p>
                    </div>

                    <p class="visually-hidden">
                        {{ trans('home.time_remaining_label') }}:
                        {{ trans('home.scheduled_draw_for') }}
                        {{ $countdownDisplay ?? $countdownTarget }} ({{ $countdownZone }}).
                    </p>

                    <div class="mt-5 home-countdown__grid"
                         data-home-countdown
                         data-target="{{ $countdownTarget }}"
                         data-reached-label="{{ trans('home.countdown.reached') }}"
                         data-timezone="{{ $countdownZone }}"
                         role="timer"
                         aria-live="off"
                         aria-atomic="true">
                        <span class="home-countdown__cell"><strong id="cd-days">--</strong> <small>{{ trans('home.countdown.days') }}</small></span>
                        <span class="home-countdown__cell"><strong id="cd-hours">--</strong> <small>{{ trans('home.countdown.hours') }}</small></span>
                        <span class="home-countdown__cell"><strong id="cd-minutes">--</strong> <small>{{ trans('home.countdown.minutes') }}</small></span>
                        <span class="home-countdown__cell"><strong id="cd-seconds">--</strong> <small>{{ trans('home.countdown.seconds') }}</small></span>
                        <span class="visually-hidden" role="status" aria-live="polite" data-countdown-status></span>
                    </div>
                @else
                    <div class="mt-5 tl-v2-subcard border-dashed text-sm text-[#D4CAB3]">
                        {{ trans('home.next_draw_unavailable') }}
                    </div>
                @endif
            </div>

            <div class="mt-6 pt-4 border-t border-[#D4AF37]/15 flex flex-wrap items-center justify-between gap-3">
                <a href="{{ route('how-to-play') }}" class="text-xs font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4">
                    {{ __('public_pages.footer_how_to_play') }}
                </a>
                <a href="{{ route('results.index') }}" class="text-xs font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4">
                    {{ $text['cta_results'] ?? trans('home.cta_results') }} &rarr;
                </a>
            </div>
        </section>
    </div>

    {{-- ----------------------------------------------------------------
         4. Four Public Result Lanes Digest
    ----------------------------------------------------------------- --}}
    <section id="lane-results" aria-labelledby="lane-results-title" class="home-section">
        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-[#D4AF37]/15 pb-4">
            <div>
                <span class="tl-v2-eyebrow">{{ __('public_pages.footer_lanes_heading') }}</span>
                <h2 id="lane-results-title" class="mt-1 text-2xl font-black text-[#FFF6D6]">
                    {{ $text['lane_results_title'] ?? trans('home.lane_results_title') }}
                </h2>
            </div>
            <a href="{{ route('lotteries.index') }}" class="tl-v2-btn-outline text-xs">
                {{ __('player.nav_lotteries') }} &rarr;
            </a>
        </div>

        <div class="mt-6 grid grid-cols-1 gap-4 md:grid-cols-2">
            @foreach (($laneResults['lanes'] ?? []) as $lane)
                @php
                    $laneDateDisplay = match (app()->getLocale()) {
                        'th' => $lane['date_display_th'] ?? $lane['date_iso'] ?? null,
                        'bn' => $lane['date_display_bn'] ?? $lane['date_iso'] ?? null,
                        default => $lane['date_display_en'] ?? $lane['date_iso'] ?? null,
                    };
                @endphp
                <article class="home-lane">
                    <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-[#D4AF37]/12 pb-3">
                        <h3 class="text-lg font-extrabold text-[#FFF6D6]">
                            <a href="{{ $lane['route'] }}" class="hover:text-[#D4AF37] transition">{{ trans($lane['title_key']) }}</a>
                        </h3>
                        <span class="home-status" data-status="{{ $lane['status'] }}">
                            {{ $sectionStatusLabels[($lane['available'] ?? false) ? 'AVAILABLE' : 'UNAVAILABLE'] }}
                        </span>
                    </div>

                    @if (($lane['available'] ?? false) === true)
                        <p class="mt-2.5 text-xs text-[#9D937D] font-mono">
                            {{ $lane['reference'] }}
                            @if (! empty($lane['date_iso']))
                                · <time datetime="{{ $lane['date_iso'] }}">{{ $laneDateDisplay ?? $lane['date_iso'] }}</time>
                            @endif
                        </p>

                        <dl class="mt-3.5 grid grid-cols-2 gap-3">
                            @foreach (($lane['fields'] ?? []) as $field)
                                <div class="rounded-xl border border-[#D4AF37]/15 bg-[#0B0904]/70 px-3 py-2.5">
                                    <dt class="text-[11px] font-bold uppercase tracking-wider text-[#9D937D]">{{ trans($field['label_key']) }}</dt>
                                    <dd class="mt-1 font-mono text-base font-extrabold text-[#F5E6B8]" data-tabular-nums>
                                        {{ $field['value'] ?? ($text['lane_results_field_none'] ?? '—') }}
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @else
                        <p class="mt-3 text-sm text-[#D4CAB3]" role="status">{{ $text['lane_results_none'] ?? trans('home.lane_results_none') }}</p>
                        @if (! empty($lane['fields']))
                            <dl class="mt-3.5 grid grid-cols-2 gap-3">
                                @foreach ($lane['fields'] as $field)
                                    <div class="rounded-xl border border-[#D4AF37]/15 bg-[#0B0904]/70 px-3 py-2.5">
                                        <dt class="text-[11px] font-bold uppercase tracking-wider text-[#9D937D]">{{ trans($field['label_key']) }}</dt>
                                        <dd class="mt-1 font-mono text-xs font-semibold text-[#D4CAB3]">
                                            {{ $text['lane_results_field_none'] ?? trans('home.lane_results_field_none') }}
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    @endif
                </article>
            @endforeach
        </div>
    </section>

    {{-- ----------------------------------------------------------------
         5. Quick 6-Digit Ticket Check + Official Sales Points Lookup
    ----------------------------------------------------------------- --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <section id="check" aria-labelledby="check-title" class="home-section lg:col-span-7">
            <span class="tl-v2-eyebrow">{{ __('public_pages.footer_ticket_verification') }}</span>
            <h2 id="check-title" class="mt-1 text-2xl font-black text-[#FFF6D6]">{{ $text['check_title'] ?? trans('home.check_title') }}</h2>
            <p class="mt-2 text-sm text-[#D4CAB3]">{{ $text['check_help'] ?? '' }}</p>

            <form action="{{ route('ticket-check') }}" method="GET" class="mt-5 flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[13rem] flex flex-col gap-1.5">
                    <label for="home-check-number" class="text-xs font-bold uppercase tracking-wider text-[#F5E6B8]">
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
                           class="home-input home-input--digits">
                </div>
                <button type="submit" class="tl-v2-btn-gold">
                    {{ $text['check_button'] ?? trans('home.check_button') }}
                </button>
            </form>
        </section>

        <section id="sales-points" aria-labelledby="sales-points-title" class="home-section lg:col-span-5 flex flex-col justify-between">
            <div>
                <span class="tl-v2-eyebrow">{{ __('public_pages.footer_sales_points') }}</span>
                <h2 id="sales-points-title" class="mt-1 text-2xl font-black text-[#FFF6D6]">{{ $text['sales_title'] ?? trans('home.sales_title') }}</h2>
                <p class="mt-2 text-sm text-[#D4CAB3] leading-relaxed">{{ $text['sales_help'] ?? '' }}</p>
            </div>
            <div class="mt-5">
                <a href="{{ route('sales-points') }}" class="tl-v2-btn-outline w-full sm:w-auto">
                    <span>{{ $text['cta_sales'] ?? trans('home.sales_search_button') }}</span>
                    <span aria-hidden="true">&rarr;</span>
                </a>
            </div>
        </section>
    </div>

    {{-- ----------------------------------------------------------------
         6. Live Draw Broadcast Surface (Honest Configured vs Not-Configured)
    ----------------------------------------------------------------- --}}
    <section id="live-draw" aria-labelledby="live-draw-title" class="home-section"
             data-home-live-draw
             data-live-status="{{ $liveDraw['live_status'] ?? 'not_configured' }}"
             data-replay-status="{{ $liveDraw['replay_status'] ?? 'not_configured' }}">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#D4AF37]/15 pb-4">
            <h2 id="live-draw-title" class="text-2xl font-black text-[#FFF6D6]">
                {{ $text['live_title'] ?? trans('home.live_title') }}
            </h2>
            <span class="home-badge" data-live-status="{{ $liveDraw['status'] ?? 'UNAVAILABLE' }}">
                {{ match ($liveDraw['status'] ?? 'UNAVAILABLE') {
                    'CONFIGURED' => trans('home.live_status_configured'),
                    'NOT_CONFIGURED' => trans('home.live_status_not_configured'),
                    default => trans('home.live_status_unavailable'),
                } }}
            </span>
        </div>

        @if (($liveDraw['live_status'] ?? 'not_configured') === 'configured' && is_string($liveDraw['embed_url'] ?? null))
            <div class="mt-5 aspect-video overflow-hidden rounded-2xl border border-[#D4AF37]/30 bg-black" data-live-frame-host>
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
            <p class="mt-3 text-xs text-[#9D937D]">{{ trans('home.stream_provider_label') }}: {{ $liveDraw['provider'] ?? trans('home.stream_provider_unspecified') }}. {{ trans('home.stream_url_disclaimer') }}</p>
        @else
            <div class="mt-5 tl-v2-subcard border-dashed">
                <p class="text-sm text-[#D4CAB3]" role="status">{{ ($liveDraw['live_status'] ?? '') === 'not_configured' ? trans('home.live_not_configured') : trans('home.live_unavailable') }}@if (($liveDraw['live_status'] ?? '') === 'not_configured') <span class="sr-only">LIVE DRAW NOT_CONFIGURED</span>@endif</p>
                <p class="mt-2 text-xs text-[#9D937D]">
                    {{ ($liveDraw['replay_status'] ?? 'not_configured') === 'configured' ? trans('home.replay_catalogue_status') : trans('home.replay_not_configured') }}
                </p>
            </div>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         7. Product Catalogue & Published Prize Schedule
    ----------------------------------------------------------------- --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <section id="products" aria-labelledby="products-title" class="home-section lg:col-span-8">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#D4AF37]/15 pb-4">
                <h2 id="products-title" class="text-2xl font-black text-[#FFF6D6]">{{ $text['products_title'] ?? trans('home.products_title') }}</h2>
                <span class="home-status" data-status="{{ $productSummary['status'] ?? 'UNAVAILABLE' }}">
                    {{ $sectionStatusLabels[$productSummary['status'] ?? 'UNAVAILABLE'] ?? $sectionStatusLabels['UNAVAILABLE'] }}
                </span>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                @forelse (($productSummary['products'] ?? []) as $product)
                    <article class="home-lane flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="text-lg font-extrabold text-[#FFF6D6]">{{ match ($product['code'] ?? '') {
                                    'L6' => trans('home.product_l6_name'),
                                    'N3' => trans('home.product_n3_name'),
                                    default => $product['name'] ?? $product['code'] ?? trans('home.product_name_generic'),
                                } }}</h3>
                                <span class="rounded-lg border border-[#D4AF37]/30 bg-[#141007] px-2.5 py-1 font-mono text-xs font-black text-[#D4AF37]">{{ $product['code'] }}</span>
                            </div>

                            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <div class="rounded-xl border border-[#D4AF37]/15 bg-[#0B0904]/70 p-3">
                                    <dt class="text-xs text-[#9D937D]">{{ trans('home.ticket_price_label') }}</dt>
                                    <dd class="mt-1 font-mono text-base font-black text-[#F5E6B8]" data-tabular-nums>{{ $product['ticket_price'] }} {{ $product['currency'] }}</dd>
                                </div>
                                <div class="rounded-xl border border-[#D4AF37]/15 bg-[#0B0904]/70 p-3">
                                    <dt class="text-xs text-[#9D937D]">{{ trans('home.digits_label') }}</dt>
                                    <dd class="mt-1 font-mono text-base font-black text-[#FFF6D6]" data-tabular-nums>{{ $product['digits'] }}</dd>
                                </div>
                            </dl>

                            @if (! empty($product['notes']))
                                <p class="mt-3 text-xs text-[#D4CAB3] leading-relaxed">{{ match ($product['code'] ?? '') {
                                    'L6' => trans('home.product_l6_note'),
                                    'N3' => trans('home.product_n3_note'),
                                    default => $product['notes'],
                                } }}</p>
                            @endif
                        </div>
                    </article>
                @empty
                    <p class="text-sm text-[#D4CAB3]">{{ trans('home.no_products_published') }}</p>
                @endforelse
            </div>
        </section>

        {{-- Prize highlight --}}
        <section id="prize" aria-labelledby="prize-title" class="home-section lg:col-span-4 flex flex-col justify-between">
            <div>
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-[#D4AF37]/15 pb-4">
                    <h2 id="prize-title" class="text-xl font-black text-[#FFF6D6]">{{ $text['prize_title'] ?? trans('home.prize_title') }}</h2>
                    <span class="home-status" data-status="{{ $prize['status'] ?? 'UNAVAILABLE' }}">
                        {{ $sectionStatusLabels[$prize['status'] ?? 'UNAVAILABLE'] ?? $sectionStatusLabels['UNAVAILABLE'] }}
                    </span>
                </div>

                @if (! empty($prize['amount']))
                    <div class="mt-5 tl-v2-subcard border-[#D4AF37]/30">
                        <p class="text-xs font-bold uppercase tracking-wider text-[#D4AF37]">{{ trans('home.prize_1st_label') }}</p>
                        <p class="mt-2 font-mono text-3xl font-black text-[#FFF6D6]" data-tabular-nums>{{ $prize['amount'] }}</p>
                        @if (! empty($prize['draw_number']))
                            <p class="mt-2 text-xs text-[#9D937D] font-mono">{{ $text['draw_label'] ?? trans('home.draw_label') }}: {{ $prize['draw_number'] }}</p>
                        @endif
                    </div>
                @endif

                @if (! empty($prize['message']))
                    <p class="mt-4 text-sm text-[#D4CAB3]">{{ trans('home.prize_unavailable') }}</p>
                @endif
            </div>

            <div class="mt-5 pt-4 border-t border-[#D4AF37]/15">
                <a href="{{ route('prize-verification') }}" class="text-xs font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4">
                    {{ __('public_pages.footer_prize_verification') }} &rarr;
                </a>
            </div>
        </section>
    </div>

    {{-- ----------------------------------------------------------------
         8. Database-Backed Platform Telemetry
    ----------------------------------------------------------------- --}}
    <section id="stats" aria-labelledby="stats-title" class="home-section">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#D4AF37]/15 pb-4">
            <h2 id="stats-title" class="text-2xl font-black text-[#FFF6D6]">{{ $text['stats_title'] ?? trans('home.stats_title') }}</h2>
            <span class="home-status" data-status="{{ $stats['status'] ?? 'UNAVAILABLE' }}">
                {{ $sectionStatusLabels[$stats['status'] ?? 'UNAVAILABLE'] ?? $sectionStatusLabels['UNAVAILABLE'] }}
            </span>
        </div>

        <dl class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @forelse (($stats['metrics'] ?? []) as $metric)
                <div class="tl-v2-subcard">
                    <dt class="text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ $metric['label'] }}</dt>
                    <dd class="mt-2 font-mono text-2xl font-black text-[#FFF6D6]" data-tabular-nums>
                        {{ ($metric['available'] ?? false) ? $metric['value'] : ($text['stats_unavailable'] ?? '—') }}
                    </dd>
                </div>
            @empty
                <p class="text-sm text-[#D4CAB3]">{{ trans('home.stats_unavailable') }}</p>
            @endforelse
        </dl>

        @if (! empty($stats['generated_at']))
            <p class="mt-4 text-xs text-[#9D937D]">
                {{ $text['generated_label'] ?? trans('home.generated_label') }}:
                <time datetime="{{ $stats['generated_at'] }}">{{ $stats['generated_at'] }}</time>
                · {{ $text['stats_database'] ?? trans('home.stats_database') }}
            </p>
        @endif
    </section>

    {{-- ----------------------------------------------------------------
         9. Active Campaigns, Referral, Affiliate & Account Grade Transparency
    ----------------------------------------------------------------- --}}
    <section id="bonuses" aria-labelledby="bonuses-title" class="home-section">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#D4AF37]/15 pb-4">
            <h2 id="bonuses-title" class="text-2xl font-black text-[#FFF6D6]">{{ $text['bonuses_title'] ?? trans('home.bonuses_title') }}</h2>
            <a href="{{ route('account-grades') }}" class="text-xs font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4">
                {{ __('public_pages.footer_account_grades') }} &rarr;
            </a>
        </div>

        @php($campaigns = $bonuses['campaigns'] ?? [])

        @if (count($campaigns) > 0)
            <ul class="mt-5 grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ($campaigns as $campaign)
                    <li class="home-lane" data-campaign-id="{{ $campaign['id'] }}">
                        <h3 class="text-lg font-extrabold text-[#FFF6D6]">{{ $campaign['title'] }}</h3>
                        @if (! empty($campaign['description']))
                            <p class="mt-1.5 text-sm text-[#D4CAB3]">{{ $campaign['description'] }}</p>
                        @endif
                        <p class="mt-2.5 text-xs text-[#9D937D] font-mono">
                            <time datetime="{{ $campaign['valid_from'] }}">{{ $campaign['valid_from'] }}</time>
                            —
                            <time datetime="{{ $campaign['valid_until'] }}">{{ $campaign['valid_until'] }}</time>
                        </p>
                        @if (! empty($campaign['cta_url']) && ! empty($campaign['cta_label']))
                            <a href="{{ $campaign['cta_url'] }}" class="mt-3 inline-flex items-center text-sm font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4">
                                {{ $campaign['cta_label'] }}
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-4 text-sm text-[#D4CAB3]">
                {{ trans('home.bonuses_empty') }}
            </p>
        @endif

        <div class="mt-6 border-t border-[#D4AF37]/15 pt-5" aria-labelledby="program-info-title">
            <h3 id="program-info-title" class="text-lg font-extrabold text-[#FFF6D6]">{{ trans('home.program_info_title') }}</h3>
            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                <article class="tl-v2-subcard">
                    <h4 class="font-bold text-[#FFF6D6]">{{ trans('home.referral_info_title') }}</h4>
                    <p class="mt-2 text-sm text-[#D4CAB3] leading-relaxed">{{ trans('home.referral_info_body') }}</p>
                    @guest
                        <a class="mt-3 inline-flex items-center text-sm font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('register') }}">{{ trans('home.referral_info_link') }}</a>
                    @else
                        <p class="mt-3 text-xs text-[#9D937D]">{{ trans('home.referral_info_existing_account') }}</p>
                    @endguest
                </article>
                <article class="tl-v2-subcard">
                    <h4 class="font-bold text-[#FFF6D6]">{{ trans('home.affiliate_info_title') }}</h4>
                    <p class="mt-2 text-sm text-[#D4CAB3] leading-relaxed">{{ trans('home.affiliate_info_body') }}</p>
                    <a class="mt-3 inline-flex items-center text-sm font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('discounts') }}#affiliate-commissions">{{ trans('home.affiliate_info_link') }}</a>
                </article>
            </div>
        </div>
    </section>

    {{-- ----------------------------------------------------------------
         10. Payment Channels, Support, Mobile App Access & Factual Trust
    ----------------------------------------------------------------- --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        {{-- Payment rails --}}
        <section id="payments" aria-labelledby="payments-title" class="home-section lg:col-span-6 flex flex-col justify-between">
            <div>
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#D4AF37]/15 pb-4">
                    <h2 id="payments-title" class="text-2xl font-black text-[#FFF6D6]">{{ $text['payments_title'] ?? trans('home.payments_title') }}</h2>
                    <span class="home-status" data-status="{{ $payments['status'] ?? 'UNAVAILABLE' }}">
                        {{ $sectionStatusLabels[$payments['status'] ?? 'UNAVAILABLE'] ?? $sectionStatusLabels['UNAVAILABLE'] }}
                    </span>
                </div>

                @php($methods = $payments['methods'] ?? [])

                @if (count($methods) > 0)
                    <ul class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($methods as $method)
                            <li class="tl-v2-subcard flex flex-wrap items-center justify-between gap-2" data-method="{{ $method['code'] }}">
                                <div>
                                    <span class="font-bold text-[#FFF6D6] block">{{ $method['label'] }}</span>
                                    @if (! empty($method['currencies']))
                                        <span class="text-xs text-[#9D937D] font-mono">{{ implode(', ', $method['currencies']) }}</span>
                                    @endif
                                </div>
                                <span class="home-status" data-status="{{ $method['status'] }}">{{ $sectionStatusLabels[$method['status'] ?? 'UNAVAILABLE'] ?? $sectionStatusLabels['UNAVAILABLE'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="mt-4 text-sm text-[#D4CAB3]">
                        {{ trans('home.payments_empty') }}
                    </p>
                @endif
            </div>

            <div class="mt-5 pt-4 border-t border-[#D4AF37]/15 flex flex-wrap items-center justify-between gap-3">
                <a href="{{ route('fees') }}" class="text-xs font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4">
                    {{ __('public_pages.footer_fees') }} &rarr;
                </a>
                <a href="{{ route('account-verification-guide') }}" class="text-xs font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4">
                    {{ __('public_pages.footer_kyc_guide') }}
                </a>
            </div>
        </section>

        {{-- Support + App links --}}
        <div class="lg:col-span-6 flex flex-col gap-6">
            <section id="support" aria-labelledby="support-title" class="home-section">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#D4AF37]/15 pb-4">
                    <h2 id="support-title" class="text-2xl font-black text-[#FFF6D6]">{{ $text['support_title'] ?? trans('home.support_title') }}</h2>
                    <span class="home-status" data-status="{{ $support['status'] ?? 'UNAVAILABLE' }}">
                        {{ $sectionStatusLabels[$support['status'] ?? 'UNAVAILABLE'] ?? $sectionStatusLabels['UNAVAILABLE'] }}
                    </span>
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    @if (! empty($support['email']))
                        <div class="tl-v2-subcard">
                            <dt class="text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ $text['email_label'] ?? trans('home.email_label') }}</dt>
                            <dd class="mt-1 text-sm text-[#FFF6D6] break-all">{{ $support['email'] }}</dd>
                        </div>
                    @endif
                    @if (! empty($support['phone']))
                        <div class="tl-v2-subcard">
                            <dt class="text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ $text['phone_label'] ?? trans('home.phone_label') }}</dt>
                            <dd class="mt-1 text-sm text-[#FFF6D6] font-mono">{{ $support['phone'] }}</dd>
                        </div>
                    @endif
                    @if (! empty($support['hours']))
                        <div class="tl-v2-subcard">
                            <dt class="text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ $text['hours_label'] ?? trans('home.hours_label') }}</dt>
                            <dd class="mt-1 text-sm text-[#FFF6D6]">{{ $support['hours'] }}</dd>
                        </div>
                    @endif
                </dl>

                @if (! empty($support['message']))
                    <p class="mt-4 text-sm text-[#D4CAB3]">{{ trans('home.support_help') }}</p>
                @endif

                <div class="mt-5 flex flex-wrap gap-3">
                    @if (! empty($support['route_url']))
                        <a href="{{ $support['route_url'] }}" class="tl-v2-btn-outline text-xs">
                            {{ $text['support_contact'] ?? trans('home.support_contact') }}
                        </a>
                    @endif
                    <a href="{{ route('faq') }}" class="tl-v2-btn-outline text-xs">
                        {{ __('public_pages.footer_faq') }}
                    </a>
                </div>
            </section>

            <section id="app-links" aria-labelledby="app-links-title" class="home-section">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#D4AF37]/15 pb-4">
                    <h2 id="app-links-title" class="text-xl font-black text-[#FFF6D6]">{{ $text['app_title'] ?? trans('home.app_title') }}</h2>
                    <span class="home-status" data-status="{{ $appLinks['status'] ?? 'UNAVAILABLE' }}">
                        {{ $sectionStatusLabels[$appLinks['status'] ?? 'UNAVAILABLE'] ?? $sectionStatusLabels['UNAVAILABLE'] }}
                    </span>
                </div>

                @if (($appLinks['status'] ?? '') === 'CONFIGURED')
                    <ul class="mt-4 flex flex-wrap gap-3">
                        @if (! empty($appLinks['android']))
                            <li><a href="{{ $appLinks['android'] }}" rel="noopener noreferrer" class="tl-v2-btn-outline text-xs">{{ trans('home.app.android_btn') }}</a></li>
                        @endif
                        @if (! empty($appLinks['ios']))
                            <li><a href="{{ $appLinks['ios'] }}" rel="noopener noreferrer" class="tl-v2-btn-outline text-xs">{{ trans('home.app.ios_btn') }}</a></li>
                        @endif
                        @if (! empty($appLinks['pwa']))
                            <li><a href="{{ $appLinks['pwa'] }}" rel="noopener noreferrer" class="tl-v2-btn-outline text-xs">{{ trans('home.app.pwa_btn') }}</a></li>
                        @endif
                    </ul>
                @else
                    <p class="mt-4 text-sm text-[#D4CAB3]">{{ trans('home.app_links_unavailable') }}</p>
                @endif
            </section>
        </div>
    </div>

    {{-- ----------------------------------------------------------------
         Historical Lottery Context (Neutral Educational Reference)
    ----------------------------------------------------------------- --}}
    <section id="heritage" aria-labelledby="heritage-title" class="home-section">
        <span class="tl-v2-eyebrow" data-i18n="heritage_eyebrow">{{ trans('home.heritage_eyebrow') }}</span>
        <h2 id="heritage-title" class="mt-1 text-2xl font-black text-[#FFF6D6]">{{ trans('home.heritage_title') }}</h2>
        <p class="mt-3 text-sm leading-relaxed text-[#D4CAB3]">
            {{ trans('home.heritage_body') }}
        </p>
    </section>

    {{-- ----------------------------------------------------------------
         Factual Platform Safeguards & Independent Non-Affiliation Notice
    ----------------------------------------------------------------- --}}
    <section id="trust" aria-labelledby="trust-title" class="home-section">
        <h2 id="trust-title" class="text-2xl font-black text-[#FFF6D6]">{{ $text['trust_title'] ?? trans('home.trust_title') }}</h2>

        @if (! empty($trust['bullets']))
            <ul class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2">
                @foreach ($trust['bullets'] as $bullet)
                    <li class="tl-v2-subcard flex items-start gap-3 text-sm text-[#D4CAB3]">
                        <span aria-hidden="true" class="mt-1 inline-block h-2 w-2 rounded-full bg-[#D4AF37] shrink-0"></span>
                        <span>{{ $bullet }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <p class="mt-5 text-xs leading-relaxed text-[#9D937D] border-t border-[#D4AF37]/15 pt-4">
            {{ $text['independent_disclaimer'] ?? trans('home.independent_disclaimer') }}
        </p>
    </section>

</div>
@endsection
