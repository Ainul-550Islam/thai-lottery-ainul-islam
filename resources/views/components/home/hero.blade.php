@props([
    'hero' => [],
    'text' => [],
    'nextDraw' => [],
])

<section id="hero" class="relative overflow-hidden border-b border-slate-800 bg-slate-950 py-12 sm:py-16">
    <div class="mx-auto grid max-w-7xl grid-cols-1 items-center gap-10 px-4 sm:px-6 lg:grid-cols-12 lg:px-8">
        <div class="flex flex-col gap-5 lg:col-span-7">
            <p class="w-fit rounded-full border border-emerald-500/30 bg-emerald-500/10 px-4 py-2 text-xs font-bold uppercase tracking-wide text-emerald-200">
                {{ $text['meta_title'] ?? trans('home.meta_title') }}
            </p>
            <h1 class="text-4xl font-black leading-tight text-white sm:text-6xl">
                {{ $text['hero_title'] ?? trans('home.hero_title') }}
            </h1>
            <p class="max-w-2xl text-base leading-7 text-slate-300 sm:text-lg">
                {{ $text['hero_lead'] ?? trans('home.hero_lead') }}
            </p>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('ticket-check') }}" class="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-bold text-white hover:bg-emerald-500">
                    {{ $text['cta_check'] ?? trans('home.cta_check') }}
                </a>
                <a href="{{ route('results.index') }}" class="rounded-xl border border-slate-700 px-5 py-3 text-sm font-bold text-slate-100 hover:border-emerald-500">
                    {{ $text['cta_results'] ?? trans('home.cta_results') }}
                </a>
            </div>
        </div>
        <div class="lg:col-span-5">
            <x-home.next-draw :next-draw="$nextDraw" :text="$text" />
        </div>
    </div>
</section>
