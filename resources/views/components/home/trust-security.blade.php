@props([
    'trust' => [],
    'text' => [],
])

<section id="trust" class="py-16 bg-[#141007]/60 border-b border-[#D4AF37]/20" aria-labelledby="trust-title">
    <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center max-w-3xl mx-auto mb-12">
            <span class="text-xs font-extrabold uppercase tracking-widest text-[#D4AF37] block mb-1">
                {{ trans('home.trust.subtitle') }}
            </span>
            <h2 id="trust-title" class="text-3xl sm:text-4xl font-black text-white font-['Outfit']">
                {{ $text['trust_title'] ?? trans('home.trust.title') }}
            </h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div class="tl-glass-panel p-6">
                <div class="w-12 h-12 rounded-xl bg-amber-500/10 border border-amber-500/30 flex items-center justify-center text-xl text-[#D4AF37] mb-4">🛡️</div>
                <h4 class="text-base font-black text-white mb-2">{{ trans('home.trust.feature_1_title') }}</h4>
                <p class="text-xs text-slate-400 leading-relaxed">{{ trans('home.trust.feature_1_desc') }}</p>
            </div>
            <div class="tl-glass-panel p-6">
                <div class="w-12 h-12 rounded-xl bg-emerald-500/10 border border-emerald-500/30 flex items-center justify-center text-xl text-emerald-400 mb-4">⚡</div>
                <h4 class="text-base font-black text-white mb-2">{{ trans('home.trust.feature_2_title') }}</h4>
                <p class="text-xs text-slate-400 leading-relaxed">{{ trans('home.trust.feature_2_desc') }}</p>
            </div>
            <div class="tl-glass-panel p-6">
                <div class="w-12 h-12 rounded-xl bg-blue-500/10 border border-blue-500/30 flex items-center justify-center text-xl text-blue-400 mb-4">🔒</div>
                <h4 class="text-base font-black text-white mb-2">{{ trans('home.trust.feature_3_title') }}</h4>
                <p class="text-xs text-slate-400 leading-relaxed">{{ trans('home.trust.feature_3_desc') }}</p>
            </div>
            <div class="tl-glass-panel p-6">
                <div class="w-12 h-12 rounded-xl bg-purple-500/10 border border-purple-500/30 flex items-center justify-center text-xl text-purple-400 mb-4">👑</div>
                <h4 class="text-base font-black text-white mb-2">{{ trans('home.trust.feature_4_title') }}</h4>
                <p class="text-xs text-slate-400 leading-relaxed">{{ trans('home.trust.feature_4_desc') }}</p>
            </div>
        </div>
    </div>
</section>
