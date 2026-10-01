@props([
    'text' => [],
    'products' => [],
])

<section class="space-y-6" aria-labelledby="lottery-grid-title" id="lottery-products">
    <div class="flex items-center justify-between">
        <div>
            <h2 id="lottery-grid-title" class="text-2xl font-black text-white tracking-tight">Featured Lottery Markets</h2>
            <p class="text-xs text-slate-400 mt-1">Authentic draw schedules, verified rules, and instant prize verification.</p>
        </div>
        <span class="px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
            Multi-Lane Wagering
        </span>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- National Lottery L6 -->
        <article class="tl-ticket-3d flex flex-col justify-between group">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="px-2.5 py-1 rounded-lg text-[11px] font-black uppercase tracking-wider bg-amber-500/10 text-amber-400 border border-amber-500/20">
                        GLO Official
                    </span>
                    <span class="text-xs font-mono text-slate-400 font-bold">1st &amp; 16th</span>
                </div>

                <div>
                    <h3 class="text-lg font-black text-white group-hover:text-emerald-400 transition">Government Lottery L6</h3>
                    <p class="text-xs text-slate-400 mt-1">6-digit national lottery with full proportional prize structure.</p>
                </div>

                <div class="flex items-baseline space-x-2 pt-2 border-t border-slate-800">
                    <span class="text-2xl font-black text-amber-400 font-mono">฿80.00</span>
                    <span class="text-xs text-slate-500 font-medium">/ ticket</span>
                </div>
            </div>

            <div class="pt-6">
                <a href="{{ route('national-lottery.index') }}"
                   class="w-full py-2.5 px-4 bg-slate-800 hover:bg-emerald-600 text-slate-200 hover:text-white font-bold text-xs rounded-xl flex items-center justify-center space-x-2 transition shadow-md">
                    <span>View Draws &amp; Rules</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>
        </article>

        <!-- Weekly Lottery -->
        <article class="tl-ticket-3d flex flex-col justify-between group">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="px-2.5 py-1 rounded-lg text-[11px] font-black uppercase tracking-wider bg-teal-500/10 text-teal-400 border border-teal-500/20">
                        Weekly Draw
                    </span>
                    <span class="text-xs font-mono text-slate-400 font-bold">Every Monday</span>
                </div>

                <div>
                    <h3 class="text-lg font-black text-white group-hover:text-teal-400 transition">Weekly Lottery</h3>
                    <p class="text-xs text-slate-400 mt-1">Fixed weekly draw schedule with fast settlement rounds.</p>
                </div>

                <div class="flex items-baseline space-x-2 pt-2 border-t border-slate-800">
                    <span class="text-2xl font-black text-teal-400 font-mono">฿50.00</span>
                    <span class="text-xs text-slate-500 font-medium">/ ticket</span>
                </div>
            </div>

            <div class="pt-6">
                <a href="{{ route('weekly-lottery.index') }}"
                   class="w-full py-2.5 px-4 bg-slate-800 hover:bg-teal-600 text-slate-200 hover:text-white font-bold text-xs rounded-xl flex items-center justify-center space-x-2 transition shadow-md">
                    <span>View Draws &amp; Rules</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>
        </article>

        <!-- Mega / Bingo Lottery -->
        <article class="tl-ticket-3d flex flex-col justify-between group">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="px-2.5 py-1 rounded-lg text-[11px] font-black uppercase tracking-wider bg-purple-500/10 text-purple-400 border border-purple-500/20">
                        Mega Pool
                    </span>
                    <span class="text-xs font-mono text-slate-400 font-bold">Bi-Weekly</span>
                </div>

                <div>
                    <h3 class="text-lg font-black text-white group-hover:text-purple-400 transition">Mega Lottery</h3>
                    <p class="text-xs text-slate-400 mt-1">High-tier prize pool with multi-number pattern matches.</p>
                </div>

                <div class="flex items-baseline space-x-2 pt-2 border-t border-slate-800">
                    <span class="text-2xl font-black text-purple-400 font-mono">฿100.00</span>
                    <span class="text-xs text-slate-500 font-medium">/ ticket</span>
                </div>
            </div>

            <div class="pt-6">
                <a href="{{ route('bingo-lottery.index') }}"
                   class="w-full py-2.5 px-4 bg-slate-800 hover:bg-purple-600 text-slate-200 hover:text-white font-bold text-xs rounded-xl flex items-center justify-center space-x-2 transition shadow-md">
                    <span>View Draws &amp; Rules</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>
        </article>

        <!-- PCSO Lottery -->
        <article class="tl-ticket-3d flex flex-col justify-between group">
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <span class="px-2.5 py-1 rounded-lg text-[11px] font-black uppercase tracking-wider bg-sky-500/10 text-sky-400 border border-sky-500/20">
                        International
                    </span>
                    <span class="text-xs font-mono text-slate-400 font-bold">Daily Draws</span>
                </div>

                <div>
                    <h3 class="text-lg font-black text-white group-hover:text-sky-400 transition">PCSO Lottery</h3>
                    <p class="text-xs text-slate-400 mt-1">International benchmark lottery feeds with verified result imports.</p>
                </div>

                <div class="flex items-baseline space-x-2 pt-2 border-t border-slate-800">
                    <span class="text-2xl font-black text-sky-400 font-mono">฿40.00</span>
                    <span class="text-xs text-slate-500 font-medium">/ ticket</span>
                </div>
            </div>

            <div class="pt-6">
                <a href="{{ route('pcso-lottery.index') }}"
                   class="w-full py-2.5 px-4 bg-slate-800 hover:bg-sky-600 text-slate-200 hover:text-white font-bold text-xs rounded-xl flex items-center justify-center space-x-2 transition shadow-md">
                    <span>View Draws &amp; Rules</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>
        </article>
    </div>
</section>
