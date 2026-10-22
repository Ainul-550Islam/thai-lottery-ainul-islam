@props([
    'drawNumber' => '',
    'drawDate' => '',
    'firstPrize' => '',
    'secondPrize' => [],
    'thirdPrize' => [],
    'bottomTwo' => null,
    'sourceState' => 'UNAVAILABLE',
    'fixtureSample' => false,
    'resultVersion' => null,
    'detailUrl' => null,
])

@php
    $sourceLabel = match ((string) $sourceState) {
        'OFFICIAL_SOURCE_VERIFIED' => trans('results.official_source_verified'),
        'INTERNAL_RECONCILED' => trans('results.internal_reconciled'),
        'FIXTURE_ONLY' => trans('results.fixture_only'),
        'NOT_CONFIGURED' => trans('results.not_configured'),
        default => trans('results.unavailable_source'),
    };
@endphp

<article class="tl-glass-panel p-6 space-y-5 hover:border-slate-700 transition">
    <!-- Header -->
    <div class="flex items-center justify-between flex-wrap gap-2">
        <div class="flex items-center space-x-3">
            <span class="px-3 py-1 rounded-lg text-xs font-black font-mono bg-slate-800 text-emerald-400 border border-slate-700">
                {{ trans('results.card_draw', ['number' => $drawNumber]) }}
            </span>
            <span class="text-xs text-slate-400 font-medium">
                {{ $drawDate ?: trans('results.date_pending') }}
            </span>
        </div>

        <div class="flex items-center space-x-2">
            @if ($fixtureSample)
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-amber-500/10 text-amber-300 border border-amber-500/30">
                    {{ trans('results.fixture_sample') }}
                </span>
            @else
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider {{ $sourceState === 'OFFICIAL_SOURCE_VERIFIED' ? 'bg-emerald-500/10 text-emerald-300 border border-emerald-500/30' : 'bg-slate-800 text-slate-300 border border-slate-700' }}">
                    {{ $sourceLabel }}
                </span>
            @endif
        </div>
    </div>

    <!-- 1st Prize Highlighting -->
    <div class="p-4 rounded-2xl bg-gradient-to-r from-slate-900 to-slate-950 border border-slate-800/80 flex flex-col sm:flex-row items-center justify-between gap-4">
        <div>
            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block">{{ trans('results.first_prize_winner') }}</span>
            <span class="text-xs text-slate-500">{{ trans('results.six_digit_series') }}</span>
        </div>
        <div class="tl-ticket-digits text-3xl sm:text-4xl text-amber-400 tracking-widest font-black">
            {{ $firstPrize ?: '------' }}
        </div>
    </div>

    <!-- Supplementary Prizes Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
        @if ($bottomTwo)
            <div class="p-3 rounded-xl bg-slate-950/60 border border-slate-800 flex items-center justify-between">
                <span class="text-slate-400 font-medium">{{ trans('results.bottom_two_label') }}</span>
                <span class="font-mono font-black text-emerald-400 text-sm">{{ $bottomTwo }}</span>
            </div>
        @endif

        @if (! empty($secondPrize))
            <div class="p-3 rounded-xl bg-slate-950/60 border border-slate-800 flex items-center justify-between">
                <span class="text-slate-400 font-medium">{{ trans('results.second_sample') }}</span>
                <span class="font-mono font-bold text-slate-200">
                    {{ is_array($secondPrize) ? implode(', ', array_slice($secondPrize, 0, 2)) : $secondPrize }}
                </span>
            </div>
        @endif
    </div>

    <!-- Card Footer -->
    <div class="pt-4 border-t border-slate-800/80 flex items-center justify-between text-[11px] text-slate-500">
        <span class="font-mono">{{ trans('results.version') }}: {{ $resultVersion ?: 'v1.0' }}</span>
        @if ($detailUrl)
            <a href="{{ $detailUrl }}" class="font-bold text-emerald-400 hover:text-emerald-300 transition flex items-center space-x-1">
                <span>{{ trans('results.breakdown_link') }}</span>
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </a>
        @endif
    </div>
</article>
