@props([
    'products' => [],
    'text' => [],
])

<section id="products" class="py-16 bg-[#0B0904] border-b border-[#D4AF37]/20">
    <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col md:flex-row md:items-end justify-between gap-6 mb-10">
            <div>
                <span class="text-xs font-extrabold uppercase tracking-widest text-[#D4AF37] block mb-1">
                    {{ trans('home.games.subtitle') }}
                </span>
                <h2 class="text-3xl sm:text-4xl font-black text-white font-['Outfit']">
                    {{ $text['products_title'] ?? trans('home.games.title') }}
                </h2>
            </div>

            <!-- Market Filter Tabs -->
            <div class="flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none">
                <button type="button" data-market-tab="all" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider bg-[#D4AF37] text-black shadow-lg">
                    {{ trans('home.games.tab_all') }}
                </button>
                <button type="button" data-market-tab="national" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider text-slate-300 hover:text-white hover:bg-[#D4AF37]/10 transition-all">
                    {{ trans('home.games.tab_national') }}
                </button>
                <button type="button" data-market-tab="speed" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider text-slate-300 hover:text-white hover:bg-[#D4AF37]/10 transition-all">
                    {{ trans('home.games.tab_speed') }}
                </button>
                <button type="button" data-market-tab="regional" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider text-slate-300 hover:text-white hover:bg-[#D4AF37]/10 transition-all">
                    {{ trans('home.games.tab_regional') }}
                </button>
                <button type="button" data-market-tab="pcso" class="px-4 py-2 rounded-xl text-xs font-extrabold tracking-wider text-slate-300 hover:text-white hover:bg-[#D4AF37]/10 transition-all">
                    {{ trans('home.games.tab_pcso') }}
                </button>
            </div>
        </div>

        <!-- Games Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <!-- Market Card 1: GLO National -->
            <div data-market-category="national" class="tl-glass-panel p-6 flex flex-col justify-between group">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <span class="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-lg">🇹🇭</span>
                        <span class="text-[10px] font-extrabold uppercase px-2.5 py-1 rounded-full bg-amber-500/15 text-amber-400 border border-amber-500/30">Official GLO</span>
                    </div>
                    <h4 class="text-lg font-black text-white font-['Outfit'] mb-1">Thai GLO L6</h4>
                    <p class="text-xs text-slate-400 mb-4">Bi-monthly official government lottery with 1st to 5th prize tiers.</p>
                    <div class="p-3 rounded-xl bg-[#0B0904] border border-[#D4AF37]/20 flex items-center justify-between mb-4">
                        <span class="text-[11px] font-bold text-slate-400">{{ trans('home.games.max_prize') }}</span>
                        <span class="text-sm font-black text-[#D4AF37] font-mono">฿6,000,000</span>
                    </div>
                </div>
                <a href="{{ route('national-lottery.index') }}" class="tl-btn-primary w-full py-3 rounded-xl text-center text-xs font-black uppercase tracking-wider block">
                    {{ trans('home.games.btn_play') }}
                </a>
            </div>

            <!-- Market Card 2: 88 Rounds Speed -->
            <div data-market-category="speed" class="tl-glass-panel p-6 flex flex-col justify-between group">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <span class="w-10 h-10 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-lg">⚡</span>
                        <span class="text-[10px] font-extrabold uppercase px-2.5 py-1 rounded-full bg-emerald-500/15 text-emerald-400 border border-emerald-500/30">15-Min Rounds</span>
                    </div>
                    <h4 class="text-lg font-black text-white font-['Outfit'] mb-1">Speed Lottery 88</h4>
                    <p class="text-xs text-slate-400 mb-4">88 continuous instant rounds every 15 minutes around the clock.</p>
                    <div class="p-3 rounded-xl bg-[#0B0904] border border-[#D4AF37]/20 flex items-center justify-between mb-4">
                        <span class="text-[11px] font-bold text-slate-400">{{ trans('home.games.max_prize') }}</span>
                        <span class="text-sm font-black text-emerald-400 font-mono">900x Odds</span>
                    </div>
                </div>
                <a href="{{ route('bingo-lottery.index') }}" class="tl-btn-primary w-full py-3 rounded-xl text-center text-xs font-black uppercase tracking-wider block">
                    {{ trans('home.games.btn_play') }}
                </a>
            </div>

            <!-- Market Card 3: Regional 4D -->
            <div data-market-category="regional" class="tl-glass-panel p-6 flex flex-col justify-between group">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <span class="w-10 h-10 rounded-xl bg-blue-500/10 border border-blue-500/30 flex items-center justify-center text-lg">🌏</span>
                        <span class="text-[10px] font-extrabold uppercase px-2.5 py-1 rounded-full bg-blue-500/15 text-blue-400 border border-blue-500/30">Daily 4D</span>
                    </div>
                    <h4 class="text-lg font-black text-white font-['Outfit'] mb-1">Lao & Hanoi 4D</h4>
                    <p class="text-xs text-slate-400 mb-4">Regional sovereign lottery pools with 4-digit and 3-digit wagering.</p>
                    <div class="p-3 rounded-xl bg-[#0B0904] border border-[#D4AF37]/20 flex items-center justify-between mb-4">
                        <span class="text-[11px] font-bold text-slate-400">{{ trans('home.games.max_prize') }}</span>
                        <span class="text-sm font-black text-[#D4AF37] font-mono">฿1,000,000</span>
                    </div>
                </div>
                <a href="{{ route('weekly-lottery.index') }}" class="tl-btn-primary w-full py-3 rounded-xl text-center text-xs font-black uppercase tracking-wider block">
                    {{ trans('home.games.btn_play') }}
                </a>
            </div>

            <!-- Market Card 4: PCSO 6D -->
            <div data-market-category="pcso" class="tl-glass-panel p-6 flex flex-col justify-between group">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <span class="w-10 h-10 rounded-xl bg-purple-500/10 border border-purple-500/30 flex items-center justify-center text-lg">🎰</span>
                        <span class="text-[10px] font-extrabold uppercase px-2.5 py-1 rounded-full bg-purple-500/15 text-purple-400 border border-purple-500/30">Ultra Jackpot</span>
                    </div>
                    <h4 class="text-lg font-black text-white font-['Outfit'] mb-1">PCSO 6/58 & 6D</h4>
                    <p class="text-xs text-slate-400 mb-4">Multi-tier high payout jackpot lottery with progressive prize pools.</p>
                    <div class="p-3 rounded-xl bg-[#0B0904] border border-[#D4AF37]/20 flex items-center justify-between mb-4">
                        <span class="text-[11px] font-bold text-slate-400">{{ trans('home.games.max_prize') }}</span>
                        <span class="text-sm font-black text-purple-400 font-mono">฿50,000,000</span>
                    </div>
                </div>
                <a href="{{ route('pcso-lottery.index') }}" class="tl-btn-primary w-full py-3 rounded-xl text-center text-xs font-black uppercase tracking-wider block">
                    {{ trans('home.games.btn_play') }}
                </a>
            </div>
        </div>
    </div>
</section>
