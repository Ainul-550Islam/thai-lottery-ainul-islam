@props([
    'result' => [],
    'text' => [],
])

@php
    $firstPrize = isset($result['first_prize']) && is_string($result['first_prize'])
        ? $result['first_prize']
        : null;
    $firstThree = is_array($result['first_three'] ?? null) ? $result['first_three'] : [];
    $lastThree = is_array($result['last_three'] ?? null) ? $result['last_three'] : [];
    $lastTwo = is_array($result['last_two'] ?? null) ? $result['last_two'] : [];
    $hasVerifiedResult = ($result['status'] ?? 'NO_VERIFIED_RESULT') !== 'NO_VERIFIED_RESULT'
        && $firstPrize !== null
        && preg_match('/^[0-9]{6}$/', $firstPrize) === 1;
@endphp

<section id="current-result" class="py-16 bg-[#0B0904] border-b border-[#D4AF37]/20" aria-labelledby="current-result-title">
    <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-10">
            <div>
                <span class="text-xs font-extrabold uppercase tracking-widest text-[#D4AF37] block mb-1">
                    {{ trans('home.latest_results.subtitle') }}
                </span>
                <h2 id="current-result-title" class="text-3xl sm:text-4xl font-black text-white font-['Outfit']">
                    {{ $text['current_result_title'] ?? trans('home.latest_results.title') }}
                </h2>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs font-mono text-slate-400 bg-[#141007] px-3.5 py-2 rounded-xl border border-[#D4AF37]/20">
                    {{ trans('home.latest_results.draw_date') }}:
                    <strong class="text-[#FFF6D6]">{{ $result['draw_date'] ?? '—' }}</strong>
                </span>
                <a href="{{ route('results.index') }}" class="text-xs font-extrabold text-[#D4AF37] hover:underline flex items-center gap-1">
                    <span>{{ trans('home.latest_results.btn_all_results') }}</span>
                    <span>&rarr;</span>
                </a>
            </div>
        </div>

        @if ($hasVerifiedResult)
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-stretch">
                <div class="lg:col-span-6 tl-glass-pedestal p-8 flex flex-col justify-between">
                    <div class="flex items-center justify-between border-b border-[#D4AF37]/30 pb-4">
                        <div class="flex items-center gap-2">
                            <span class="text-xl text-[#D4AF37]" aria-hidden="true">👑</span>
                            <span class="text-xs font-black uppercase tracking-wider text-[#FFF6D6]">
                                {{ $text['prize_1st_label'] ?? trans('home.latest_results.first_prize') }}
                            </span>
                        </div>
                    </div>

                    <div class="my-8 flex items-center justify-center gap-2 sm:gap-3 flex-wrap">
                        @foreach (str_split($firstPrize) as $digit)
                            <div class="tl-3d-ball tl-3d-ball--lg" data-ticket-digit>{{ $digit }}</div>
                        @endforeach
                    </div>

                    <div class="flex items-center justify-between text-xs text-slate-400 pt-4 border-t border-[#D4AF37]/20">
                        <span class="flex items-center gap-1.5 text-emerald-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            <span>{{ $result['source_state'] ?? 'UNAVAILABLE' }}</span>
                        </span>
                        <span class="font-mono text-[#D4AF37]">
                            {{ trans('home.draw_label') }} #{{ $result['draw_number'] ?? '—' }}
                        </span>
                    </div>
                </div>

                <div class="lg:col-span-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="tl-glass-panel p-5 flex flex-col justify-between">
                        <div class="text-xs font-black text-slate-300 uppercase tracking-wider mb-3">
                            {{ trans('home.latest_results.first_3_digits') }}
                        </div>
                        <div class="flex flex-col gap-2 my-auto">
                            @forelse ($firstThree as $number)
                                <div class="flex justify-center gap-1.5">
                                    @foreach (str_split((string) $number) as $digit)
                                        <span class="tl-3d-ball tl-3d-ball--sm">{{ $digit }}</span>
                                    @endforeach
                                </div>
                            @empty
                                <span class="text-center text-slate-500">—</span>
                            @endforelse
                        </div>
                    </div>

                    <div class="tl-glass-panel p-5 flex flex-col justify-between">
                        <div class="text-xs font-black text-slate-300 uppercase tracking-wider mb-3">
                            {{ trans('home.latest_results.last_3_digits') }}
                        </div>
                        <div class="flex flex-col gap-2 my-auto">
                            @forelse ($lastThree as $number)
                                <div class="flex justify-center gap-1.5">
                                    @foreach (str_split((string) $number) as $digit)
                                        <span class="tl-3d-ball tl-3d-ball--sm">{{ $digit }}</span>
                                    @endforeach
                                </div>
                            @empty
                                <span class="text-center text-slate-500">—</span>
                            @endforelse
                        </div>
                    </div>

                    <div class="tl-glass-panel p-5 flex flex-col justify-between">
                        <div class="text-xs font-black text-slate-300 uppercase tracking-wider mb-3">
                            {{ $text['prize_last2_label'] ?? trans('home.latest_results.last_2_digits') }}
                        </div>
                        <div class="flex flex-col gap-2 my-auto">
                            @forelse ($lastTwo as $number)
                                <div class="flex justify-center gap-2">
                                    @foreach (str_split((string) $number) as $digit)
                                        <span class="tl-3d-ball">{{ $digit }}</span>
                                    @endforeach
                                </div>
                            @empty
                                <span class="text-center text-slate-500">—</span>
                            @endforelse
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="tl-glass-panel p-12 text-center" role="status">
                <p class="text-slate-400 text-sm">
                    {{ $result['message'] ?? ($text['current_result_none'] ?? trans('home.current_result_none')) }}
                </p>
            </div>
        @endif
    </div>
</section>
