{{-- Hero: server-driven draw status + countdown (no client draw calendar). --}}
<section class="home-hero" aria-labelledby="hero-title">
    <div class="home-hero__inner">
        <p class="home-eyebrow">{{ $text['hero_title'] ?? 'Government Lottery results & tickets' }}</p>
        <h1 id="hero-title" class="home-hero__title">{{ $text['hero_title'] ?? 'Government Lottery results & tickets' }}</h1>
        <p class="home-hero__lead">{{ $text['hero_lead'] ?? '' }}</p>

        <div class="home-hero__actions">
            <a class="home-btn home-btn--primary" href="{{ route('results.index') }}">{{ $text['cta_results'] ?? 'Latest results' }}</a>
            <a class="home-btn home-btn--secondary" href="{{ route('ticket-check') }}">{{ $text['cta_check'] ?? 'Check your ticket' }}</a>
            <a class="home-btn home-btn--ghost" href="{{ route('sales-points') }}">{{ $text['cta_sales'] ?? 'Find a Sales Point' }}</a>
        </div>

        @if (!empty($nextDraw['status']) && $nextDraw['status'] === 'AVAILABLE' && !empty($nextDraw['scheduled_at_iso']))
            <div class="home-countdown" data-home-countdown
                 data-target="{{ $nextDraw['scheduled_at_iso'] }}"
                 data-timezone="{{ $nextDraw['timezone'] ?? 'Asia/Bangkok' }}"
                 role="timer" aria-live="polite" aria-atomic="true">
                <span class="home-countdown__label">{{ $text['next_draw_title'] ?? 'Next draw' }}:</span>
                <time class="home-countdown__display" datetime="{{ $nextDraw['scheduled_at_iso'] }}">
                    {{ $nextDraw['scheduled_at_display'] ?? $nextDraw['scheduled_at_iso'] }}
                </time>
            </div>
            <p class="home-visually-hidden">
                {{ $text['next_draw_title'] ?? 'Next draw' }} is scheduled for
                {{ $nextDraw['scheduled_at_display'] ?? $nextDraw['scheduled_at_iso'] }}
                ({{ $nextDraw['timezone'] ?? 'Asia/Bangkok' }}).
            </p>
        @else
            <p class="home-muted">{{ $text['next_draw_none'] ?? 'No draw scheduled yet' }}</p>
        @endif

        @if (!empty($hero['draw_status']))
            <p class="home-badge home-badge--neutral">Draw status: {{ $hero['draw_status'] }}</p>
        @endif
    </div>
</section>
