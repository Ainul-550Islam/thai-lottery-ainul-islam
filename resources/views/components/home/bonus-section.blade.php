@props([
    'bonuses' => [],
    'text' => [],
])

<section id="bonuses" class="py-16 bg-[#0B0904] border-b border-[#D4AF37]/20" aria-labelledby="bonuses-title">
    <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-6 mb-10">
            <div>
                <span class="text-xs font-extrabold uppercase tracking-widest text-[#D4AF37] block mb-1">
                    Special Rewards & Privileges
                </span>
                <h2 id="bonuses-title" class="text-3xl sm:text-4xl font-black text-white font-['Outfit']">
                    {{ $text['bonuses_title'] ?? trans('home.bonuses_title') }}
                </h2>
            </div>
            <a href="{{ route('account.grade') }}" class="text-xs font-extrabold text-[#D4AF37] hover:underline flex items-center gap-1">
                <span>View VIP Programme</span>
                <span>&rarr;</span>
            </a>
        </div>

        @if (!empty($bonuses['items']))
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                @foreach ($bonuses['items'] as $campaign)
                    <div class="tl-glass-panel p-6 flex flex-col justify-between">
                        <div>
                            <span class="text-[10px] font-extrabold uppercase px-2.5 py-1 rounded-full bg-amber-500/15 text-amber-400 border border-amber-500/30 inline-block mb-3">
                                {{ $campaign['badge'] ?? 'VIP PROMOTION' }}
                            </span>
                            <h4 class="text-lg font-black text-white font-['Outfit'] mb-2">{{ $campaign['title'] ?? 'Welcome Bonus' }}</h4>
                            <p class="text-xs text-slate-400 leading-relaxed">{{ $campaign['description'] ?? 'Exclusive deposit bonus for newly verified accounts.' }}</p>
                        </div>
                        <div class="mt-6 pt-4 border-t border-[#D4AF37]/20 flex items-center justify-between">
                            <span class="text-xs font-mono font-bold text-[#D4AF37]">{{ $campaign['reward'] ?? '100% Match' }}</span>
                            <a href="{{ route('register') }}" class="tl-btn-primary px-4 py-2 rounded-xl text-xs font-black uppercase tracking-wider">Claim</a>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="tl-glass-panel p-8 text-center" role="status">
                <p class="text-slate-400 text-sm">{{ $bonuses['message'] ?? ($text['bonuses_empty'] ?? trans('home.bonuses_empty')) }}</p>
            </div>
        @endif
    </div>
</section>
