{{-- Next draw: server-provided ISO target only. JS counts down for display; no draw calendar in JS. --}}
<section class="home-card" aria-labelledby="next-draw-title" id="next-draw">
    <div class="home-card__head">
        <h2 id="next-draw-title">{{ $text['next_draw_title'] ?? 'Next draw' }}</h2>
        @if (!empty($nextDraw['status']))
            <span class="home-badge home-badge--{{ \Illuminate\Support\Str::slug($nextDraw['status'], '-') }}">{{ $nextDraw['status'] }}</span>
        @endif
    </div>

    @if (($nextDraw['status'] ?? 'UNAVAILABLE') === 'AVAILABLE' && !empty($nextDraw['scheduled_at_iso']))
        <p class="home-nextdraw">
            <time datetime="{{ $nextDraw['scheduled_at_iso'] }}" data-draw-datetime>
                {{ $nextDraw['scheduled_at_display'] ?? $nextDraw['scheduled_at_iso'] }}
            </time>
        </p>

        @if (!empty($nextDraw['draw_number']))
            <p class="home-muted">Draw {{ $nextDraw['draw_number'] }}
                @if (!empty($nextDraw['draw_status'])) · {{ $nextDraw['draw_status'] }} @endif
            </p>
        @endif

        <div class="home-countdown home-countdown--card"
             data-home-countdown
             data-target="{{ $nextDraw['scheduled_at_iso'] }}"
             data-timezone="{{ $nextDraw['timezone'] ?? 'Asia/Bangkok' }}"
             role="timer"
             aria-live="polite"
             aria-atomic="true">
            <span class="home-countdown__label">Time remaining</span>
            <span class="home-countdown__display" data-countdown-output>
                {{ $nextDraw['scheduled_at_display'] ?? '' }}
            </span>
        </div>
        {{-- Countdown is never the sole timing information: the absolute date/time is above. --}}
        <p class="home-muted">Timezone: {{ $nextDraw['timezone'] ?? 'Asia/Bangkok' }}
            @if (!empty($nextDraw['source'])) · source: {{ $nextDraw['source'] }} @endif
        </p>
    @else
        <p class="home-empty" role="status">{{ $nextDraw['message'] ?? ($text['next_draw_none'] ?? 'No draw scheduled yet') }}</p>
    @endif
</section>
