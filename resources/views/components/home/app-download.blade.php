@props([
    'appLinks' => [],
    'text' => [],
])

<section id="app-links" class="py-16 bg-[#0B0904] border-b border-[#D4AF37]/20" aria-labelledby="app-title">
    <div class="max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8">
        <div class="tl-glass-panel p-8 sm:p-12">
            <div class="max-w-3xl">
                <span class="text-xs font-black uppercase tracking-widest text-[#D4AF37] block">
                    {{ trans('home.app.badge') }}
                </span>
                <h2 id="app-title" class="mt-2 text-3xl sm:text-4xl font-black text-white font-['Outfit']">
                    {{ $text['app_title'] ?? trans('home.app.title') }}
                </h2>
                <p class="mt-4 text-sm text-slate-300 leading-relaxed">
                    {{ trans('home.app.subtitle') }}
                </p>
            </div>

            @php
                $configuredLinks = [
                    'ios' => $appLinks['ios'] ?? null,
                    'android' => $appLinks['android'] ?? null,
                    'pwa' => $appLinks['pwa'] ?? null,
                ];
            @endphp

            @if (collect($configuredLinks)->filter()->isNotEmpty())
                <ul class="mt-6 flex flex-wrap gap-3">
                    @if (! empty($configuredLinks['ios']))
                        <li><a href="{{ $configuredLinks['ios'] }}" rel="noopener noreferrer" class="tl-btn-primary inline-flex rounded-xl px-5 py-3 text-xs font-black uppercase tracking-wider">{{ trans('home.app.ios_btn') }}</a></li>
                    @endif
                    @if (! empty($configuredLinks['android']))
                        <li><a href="{{ $configuredLinks['android'] }}" rel="noopener noreferrer" class="inline-flex rounded-xl border border-[#D4AF37]/40 bg-[#1C170E] px-5 py-3 text-xs font-black text-white">{{ trans('home.app.android_btn') }}</a></li>
                    @endif
                    @if (! empty($configuredLinks['pwa']))
                        <li><a href="{{ $configuredLinks['pwa'] }}" rel="noopener noreferrer" class="inline-flex rounded-xl border border-slate-700 px-5 py-3 text-xs font-bold text-slate-200">{{ trans('home.app.pwa_btn') }}</a></li>
                    @endif
                </ul>
            @else
                <p class="mt-6 text-sm text-slate-300" role="status">
                    {{ $appLinks['message'] ?? trans('home.app_links_unavailable') }}
                </p>
            @endif
        </div>
    </div>
</section>
