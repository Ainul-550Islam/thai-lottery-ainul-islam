@props([
    'nextDraw' => [],
    'text' => [],
])

<div class="tl-glass-panel p-8 relative overflow-hidden" id="next-draw" aria-labelledby="next-draw-title">
    <!-- Top Ribbon Header -->
    <div class="flex items-center justify-between border-b border-[#D4AF37]/20 pb-5">
        <div class="flex items-center gap-2">
            <span class="text-lg text-[#D4AF37]" aria-hidden="true">❖</span>
            <span id="next-draw-title" class="text-xs font-black uppercase tracking-widest text-[#D4AF37]">
                {{ $text['next_draw_title'] ?? trans('home.countdown.title') }}
            </span>
        </div>
        <span data-countdown-status class="px-3 py-1 rounded-full text-[10px] font-extrabold uppercase tracking-wider bg-amber-500/15 text-amber-400 border border-amber-500/30">
            {{ $nextDraw['status'] ?? trans('home.countdown.status_open') }}
        </span>
    </div>

    @if (($nextDraw['status'] ?? 'AVAILABLE') !== 'UNAVAILABLE' && !empty($nextDraw['scheduled_at_iso']))
        <!-- Scheduled Date Display -->
        <div class="text-center my-6">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-widest block mb-1">
                {{ $nextDraw['draw_name'] ?? 'THAI GOVERNMENT LOTTERY GLO L6' }}
            </span>
            <h2 class="text-3xl font-black text-white font-['Outfit']">
                <time datetime="{{ $nextDraw['scheduled_at_iso'] }}" data-draw-datetime>
                    {{ $nextDraw['scheduled_at_display'] ?? '16 OCTOBER 2026' }}
                </time>
            </h2>
            <span class="text-xs font-mono font-bold text-[#D4AF37] mt-1 block">
                {{ $text['draw_label'] ?? trans('home.draw_label') }} #{{ $nextDraw['draw_number'] ?? '24' }} • 14:30 {{ $nextDraw['timezone'] ?? 'Asia/Bangkok' }}
            </span>
        </div>

        <!-- 4 Live Digit Countdown Slots -->
        <div class="grid grid-cols-4 gap-3 my-6"
             data-home-countdown
             data-target-iso="{{ $nextDraw['scheduled_at_iso'] }}"
             data-target="{{ $nextDraw['scheduled_at_iso'] }}"
             data-timezone="{{ $nextDraw['timezone'] ?? 'Asia/Bangkok' }}"
             role="timer"
             aria-live="polite">
            <div class="tl-countdown-box p-3 text-center">
                <div class="text-2xl sm:text-3xl font-black text-white font-mono" id="cd-days">01</div>
                <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 mt-1">{{ trans('home.countdown.days') }}</div>
            </div>
            <div class="tl-countdown-box p-3 text-center">
                <div class="text-2xl sm:text-3xl font-black text-white font-mono" id="cd-hours">14</div>
                <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 mt-1">{{ trans('home.countdown.hours') }}</div>
            </div>
            <div class="tl-countdown-box p-3 text-center">
                <div class="text-2xl sm:text-3xl font-black text-white font-mono" id="cd-minutes">32</div>
                <div class="text-[10px] font-extrabold uppercase tracking-wider text-slate-400 mt-1">{{ trans('home.countdown.minutes') }}</div>
            </div>
            <div class="tl-countdown-box p-3 text-center border-amber-500/50">
                <div class="text-2xl sm:text-3xl font-black text-[#D4AF37] font-mono animate-pulse" id="cd-seconds" data-countdown-output>48</div>
                <div class="text-[10px] font-extrabold uppercase tracking-wider text-amber-400 mt-1">{{ trans('home.countdown.seconds') }}</div>
            </div>
        </div>

        <!-- Action Button -->
        <a href="{{ route('national-lottery.index') }}" class="tl-btn-primary w-full py-4 rounded-xl text-center text-xs tracking-wider uppercase font-black block">
            {{ trans('home.countdown.btn_bet_now') }} &rarr;
        </a>
    @else
        <div class="py-12 text-center" role="status">
            <p class="text-slate-400 text-sm">{{ $nextDraw['message'] ?? ($text['next_draw_none'] ?? trans('home.next_draw_none')) }}</p>
        </div>
    @endif
</div>
