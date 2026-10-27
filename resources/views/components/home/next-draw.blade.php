@props([
    'nextDraw' => [],
    'text' => [],
])

@php
    $scheduledAt = $nextDraw['scheduled_at_iso'] ?? null;
    $isScheduled = is_string($scheduledAt) && $scheduledAt !== '' && ($nextDraw['status'] ?? 'UNAVAILABLE') !== 'UNAVAILABLE';
@endphp

<section class="tl-glass-panel relative overflow-hidden p-8" id="next-draw" aria-labelledby="next-draw-title">
    <div class="flex items-center justify-between border-b border-amber-500/20 pb-5">
        <div class="flex items-center gap-2">
            <span class="text-lg text-amber-300" aria-hidden="true">❖</span>
            <h2 id="next-draw-title" class="text-xs font-black uppercase tracking-widest text-amber-300">
                {{ $text['next_draw_title'] ?? trans('home.countdown.title') }}
            </h2>
        </div>
        @if (! empty($nextDraw['status']))
            <span data-countdown-status class="rounded-full border border-slate-700 px-3 py-1 text-[10px] font-extrabold uppercase tracking-wider text-slate-300">
                {{ ($nextDraw['status'] ?? '') === 'AVAILABLE' ? trans('home.countdown.status_available') : trans('home.countdown.status_unavailable') }}
            </span>
        @endif
    </div>

    @if ($isScheduled)
        <div class="my-6 text-center">
            @if (! empty($nextDraw['draw_name']))
                <span class="mb-1 block text-xs font-bold uppercase tracking-widest text-slate-400">{{ $nextDraw['draw_name'] }}</span>
            @endif
            <p class="text-3xl font-black text-white">
                <time datetime="{{ $scheduledAt }}" data-draw-datetime>{{ $nextDraw['scheduled_at_display'] ?? $scheduledAt }}</time>
            </p>
            @if (! empty($nextDraw['draw_number']))
                <span class="mt-2 block font-mono text-xs font-bold text-amber-300">
                    {{ $text['draw_label'] ?? trans('home.draw_label') }} #{{ $nextDraw['draw_number'] }}
                </span>
            @endif
            @if (! empty($nextDraw['timezone']))
                <span class="mt-1 block text-xs text-slate-400">{{ $nextDraw['timezone'] }}</span>
            @endif
        </div>

        <div class="my-6 rounded-xl border border-slate-800 bg-slate-950/60 p-4 text-center"
             data-home-countdown
             data-target-iso="{{ $scheduledAt }}"
             data-target="{{ $scheduledAt }}"
             data-reached-label="{{ trans('home.countdown.reached') }}"
             @if (! empty($nextDraw['timezone'])) data-timezone="{{ $nextDraw['timezone'] }}" @endif
             role="timer"
             aria-live="polite"
             aria-label="{{ $text['countdown_aria'] ?? trans('home.countdown.aria_label') }}">
            <span class="block text-xs font-bold uppercase tracking-widest text-slate-400">{{ $text['countdown_label'] ?? trans('home.countdown.label') }}</span>
            <span class="mt-2 block font-mono text-2xl font-black text-amber-300" data-countdown-output>—</span>
        </div>

        <a href="{{ route('national-lottery.index') }}" class="tl-btn-primary block w-full rounded-xl py-4 text-center text-xs font-black uppercase tracking-wider">
            {{ $text['cta_results'] ?? trans('home.cta_results') }} &rarr;
        </a>
    @else
        <div class="py-12 text-center" role="status">
            <p class="text-sm text-slate-400">{{ trans('home.next_draw_unavailable') }}</p>
        </div>
    @endif
</section>
