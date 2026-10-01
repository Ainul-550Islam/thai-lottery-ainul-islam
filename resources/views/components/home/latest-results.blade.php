@props([
    'result' => [],
    'text' => [],
])

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
                    {{ trans('home.latest_results.draw_date') }}: <strong class="text-[#FFF6D6]">{{ $result['draw_date'] ?? '01 OCT 2026' }}</strong>
                </span>
                <a href="{{ route('results.index') }}" class="text-xs font-extrabold text-[#D4AF37] hover:underline flex items-center gap-1">
                    <span>{{ trans('home.latest_results.btn_all_results') }}</span>
                    <span>&rarr;</span>
                </a>
            </div>
        </div>

        @if (($result['status'] ?? 'AVAILABLE') !== 'NO_VERIFIED_RESULT' && !empty($result['first_prize']))
            <!-- Pedestal Results Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-stretch">
                <!-- 1st Prize Grand Pedestal -->
                <div class="lg:col-span-6 tl-glass-pedestal p-8 flex flex-col justify-between">
                    <div class="flex items-center justify-between border-b border-[#D4AF37]/30 pb-4">
                        <div class="flex items-center gap-2">
                            <span class="text-xl text-[#D4AF37]" aria-hidden="true">👑</span>
                            <span class="text-xs font-black uppercase tracking-wider text-[#FFF6D6]">{{ $text['prize_1st_label'] ?? trans('home.latest_results.first_prize') }}</span>
                        </div>
                        <span class="text-xs font-mono font-extrabold text-amber-400 bg-amber-500/10 px-3 py-1 rounded-full border border-amber-500/30">
                            ฿6,000,000 THB
                        </span>
                    </div>

                    <!-- 6 Digits Gold 3D Balls -->
                    <div class="my-8 flex items-center justify-center gap-2 sm:gap-3 flex-wrap">
                        @php
                            $firstPrizeStr = (string) ($result['first_prize'] ?? '935824');
                            $digits = str_split($firstPrizeStr);
                        @endphp
                        @foreach($digits as $digit)
                            <div class="tl-3d-ball tl-3d-ball--lg" data-ticket-digit>{{ $digit }}</div>
                        @endforeach
                    </div>

                    <div class="flex items-center justify-between text-xs text-slate-400 pt-4 border-t border-[#D4AF37]/20">
                        <span class="flex items-center gap-1.5 text-emerald-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            <span>{{ $result['source_state'] ?? 'Official GLO Verification' }}</span>
                        </span>
                        <span class="font-mono text-[#D4AF37]">{{ trans('home.draw_label') }} #{{ $result['draw_number'] ?? '23' }}</span>
                    </div>
                </div>

                <!-- Secondary Sub-Prizes Pedestal -->
                <div class="lg:col-span-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <!-- First 3 Digits -->
                    <div class="tl-glass-panel p-5 flex flex-col justify-between">
                        <div class="text-xs font-black text-slate-300 uppercase tracking-wider mb-3">
                            {{ trans('home.latest_results.first_3_digits') }}
                        </div>
                        <div class="flex flex-col gap-2 my-auto">
                            <div class="flex justify-center gap-1.5">
                                <span class="tl-3d-ball tl-3d-ball--sm">4</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">8</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">1</span>
                            </div>
                            <div class="flex justify-center gap-1.5">
                                <span class="tl-3d-ball tl-3d-ball--sm">7</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">0</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">9</span>
                            </div>
                        </div>
                        <div class="text-[11px] font-mono font-bold text-amber-400 text-center mt-3 pt-2 border-t border-[#D4AF37]/20">
                            ฿4,000 Each
                        </div>
                    </div>

                    <!-- Last 3 Digits -->
                    <div class="tl-glass-panel p-5 flex flex-col justify-between">
                        <div class="text-xs font-black text-slate-300 uppercase tracking-wider mb-3">
                            {{ trans('home.latest_results.last_3_digits') }}
                        </div>
                        <div class="flex flex-col gap-2 my-auto">
                            <div class="flex justify-center gap-1.5">
                                <span class="tl-3d-ball tl-3d-ball--sm">6</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">3</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">2</span>
                            </div>
                            <div class="flex justify-center gap-1.5">
                                <span class="tl-3d-ball tl-3d-ball--sm">1</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">5</span>
                                <span class="tl-3d-ball tl-3d-ball--sm">8</span>
                            </div>
                        </div>
                        <div class="text-[11px] font-mono font-bold text-amber-400 text-center mt-3 pt-2 border-t border-[#D4AF37]/20">
                            ฿4,000 Each
                        </div>
                    </div>

                    <!-- Last 2 Digits -->
                    <div class="tl-glass-panel p-5 flex flex-col justify-between">
                        <div class="text-xs font-black text-slate-300 uppercase tracking-wider mb-3">
                            {{ $text['prize_last2_label'] ?? trans('home.latest_results.last_2_digits') }}
                        </div>
                        <div class="flex justify-center gap-2 my-auto">
                            <span class="tl-3d-ball">5</span>
                            <span class="tl-3d-ball">6</span>
                        </div>
                        <div class="text-[11px] font-mono font-bold text-amber-400 text-center mt-3 pt-2 border-t border-[#D4AF37]/20">
                            ฿2,000 Each
                        </div>
                    </div>
                </div>
            </div>
        @else
            <div class="tl-glass-panel p-12 text-center" role="status">
                <p class="text-slate-400 text-sm">{{ $result['message'] ?? ($text['current_result_none'] ?? trans('home.current_result_none')) }}</p>
            </div>
        @endif
    </div>
</section>
