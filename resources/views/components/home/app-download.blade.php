@props([
    'appLinks' => [],
    'text' => [],
])

<section id="app-links" class="py-16 bg-[#0B0904] border-b border-[#D4AF37]/20" aria-labelledby="app-title">
    <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8">
        <div class="tl-glass-panel p-8 sm:p-12 grid grid-cols-1 lg:grid-cols-12 gap-8 items-center">
            <div class="lg:col-span-8 flex flex-col gap-4">
                <span class="text-xs font-black uppercase tracking-widest text-[#D4AF37] block">
                    {{ trans('home.app.badge') }}
                </span>
                <h2 id="app-title" class="text-3xl sm:text-4xl font-black text-white font-['Outfit']">
                    {{ $text['app_title'] ?? trans('home.app.title') }}
                </h2>
                <p class="text-sm text-slate-300 max-w-xl leading-relaxed">
                    {{ trans('home.app.subtitle') }}
                </p>
                <div class="flex flex-wrap items-center gap-4 pt-2">
                    <a href="{{ $appLinks['ios'] ?? route('public.download') }}" class="tl-btn-primary px-6 py-3.5 rounded-xl text-xs font-black uppercase tracking-wider flex items-center gap-2">
                        <span>🍏</span>
                        <span>{{ trans('home.app.ios_btn') }}</span>
                    </a>
                    <a href="{{ $appLinks['android'] ?? route('public.download') }}" class="px-6 py-3.5 rounded-xl bg-[#1C170E] border border-[#D4AF37]/40 text-xs font-black text-white hover:border-[#D4AF37] transition-all flex items-center gap-2">
                        <span>🤖</span>
                        <span>{{ trans('home.app.android_btn') }}</span>
                    </a>
                </div>
            </div>
            <div class="lg:col-span-4 flex justify-center">
                <div class="w-48 h-48 rounded-2xl bg-[#141007] border border-[#D4AF37]/40 p-3 shadow-[0_0_30px_rgba(212,175,55,0.2)] flex flex-col items-center justify-center text-center">
                    <div class="w-32 h-32 bg-white rounded-xl p-1 mb-2 flex items-center justify-center">
                        <svg class="w-full h-full text-black" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M2 2h8v8H2V2zm2 2v4h4V4H4zm-2 10h8v8H2v-8zm2 2v4h4v-4H4zm10-14h8v8h-8V2zm2 2v4h4V4h-4zm2 10h2v2h-2v-2zm-2 2h2v2h-2v-2zm4 0h2v2h-2v-2zm-2 2h2v2h-2v-2zm4-4h2v2h-2v-2zm-2 4h2v2h-2v-2z"/>
                        </svg>
                    </div>
                    <span class="text-[10px] font-bold text-[#F5E6B8] uppercase tracking-wider">{{ trans('home.app.pwa_btn') }}</span>
                </div>
            </div>
        </div>
    </div>
</section>
