@props([
    'hero' => [],
    'text' => [],
    'nextDraw' => [],
])

<section class="relative pt-12 pb-20 overflow-hidden border-b border-[#D4AF37]/20 bg-gradient-to-b from-[#141007] via-[#0B0904] to-[#0B0904]" id="hero">
    <!-- Ambient 3D Glass Orbs -->
    <div class="absolute -top-40 left-1/4 w-96 h-96 bg-[#D4AF37]/10 rounded-full blur-[120px] pointer-events-none" aria-hidden="true"></div>
    <div class="absolute top-1/2 right-10 w-80 h-80 bg-[#B8860B]/10 rounded-full blur-[100px] pointer-events-none" aria-hidden="true"></div>

    <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8 relative z-10 grid grid-cols-1 lg:grid-cols-12 gap-10 items-center">

        <!-- Left Hero Column -->
        <div class="lg:col-span-7 flex flex-col gap-6">
            <div class="inline-flex items-center gap-2.5 px-4 py-2 rounded-full bg-[#1C170E]/80 border border-[#D4AF37]/40 w-max shadow-[0_0_20px_rgba(212,175,55,0.15)]">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 animate-ping" aria-hidden="true"></span>
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-400 -ml-5" aria-hidden="true"></span>
                <span class="text-xs font-bold text-[#F5E6B8] tracking-wide">{{ trans('home.hero.badge') }}</span>
            </div>

            <h1 class="text-4xl sm:text-6xl xl:text-7xl font-black text-white leading-tight font-['Outfit'] tracking-tight">
                {{ trans('home.hero.title_prefix') }} <br/>
                <span class="tl-gold-gradient">{{ trans('home.hero.title_highlight') }}</span> <br/>
                {{ trans('home.hero.title_suffix') }}
            </h1>

            <p class="text-base sm:text-lg text-slate-300 max-w-2xl font-medium leading-relaxed">
                {{ trans('home.hero.description') }}
            </p>

            <div class="flex flex-wrap items-center gap-4 pt-2">
                <a href="{{ route('national-lottery.index') }}" class="tl-btn-primary px-8 py-4 rounded-2xl text-sm font-extrabold tracking-wider flex items-center gap-3">
                    <span>{{ trans('home.hero.btn_play_now') }}</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
                </a>
                <a href="{{ route('results.index') }}" class="px-7 py-4 rounded-2xl text-sm font-bold text-[#F5E6B8] bg-[#1C170E]/80 border border-[#D4AF37]/40 hover:border-[#D4AF37] hover:bg-[#2B230B] transition-all flex items-center gap-2 shadow-lg">
                    <svg class="w-4 h-4 text-[#D4AF37]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>{{ trans('home.hero.btn_check_results') }}</span>
                </a>
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-6 border-t border-[#D4AF37]/20">
                <div class="p-3.5 rounded-2xl bg-[#141007]/60 border border-[#D4AF37]/20">
                    <div class="text-xl sm:text-2xl font-black text-[#FFF6D6] font-['Outfit']">500,000+</div>
                    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">{{ trans('home.hero.stats.active_players') }}</div>
                </div>
                <div class="p-3.5 rounded-2xl bg-[#141007]/60 border border-[#D4AF37]/20">
                    <div class="text-xl sm:text-2xl font-black text-[#D4AF37] font-['Outfit']">฿150M+</div>
                    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">{{ trans('home.hero.stats.daily_payout') }}</div>
                </div>
                <div class="p-3.5 rounded-2xl bg-[#141007]/60 border border-[#D4AF37]/20">
                    <div class="text-xl sm:text-2xl font-black text-emerald-400 font-['Outfit']">99.99%</div>
                    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">{{ trans('home.hero.stats.uptime') }}</div>
                </div>
                <div class="p-3.5 rounded-2xl bg-[#141007]/60 border border-[#D4AF37]/20">
                    <div class="text-xl sm:text-2xl font-black text-[#F5E6B8] font-['Outfit']">24/7 VIP</div>
                    <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-0.5">{{ trans('home.hero.stats.support') }}</div>
                </div>
            </div>
        </div>

        <!-- Right Countdown Glass Hero Card -->
        <div class="lg:col-span-5" id="next-draw">
            <x-home.next-draw :nextDraw="$nextDraw" :text="$text" />
        </div>
    </div>
</section>
