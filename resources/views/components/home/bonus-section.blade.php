@props([
    'bonuses' => [],
    'text' => [],
])

@php
    $campaigns = is_array($bonuses['campaigns'] ?? null) ? $bonuses['campaigns'] : [];
@endphp

<section id="bonuses" class="border-b border-slate-800 bg-slate-950 py-14" aria-labelledby="bonuses-title">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h2 id="bonuses-title" class="text-3xl font-black text-white">
                    {{ $text['bonuses_title'] ?? trans('home.bonuses_title') }}
                </h2>
            </div>
        </div>

        @if ($campaigns !== [])
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($campaigns as $campaign)
                    <article class="rounded-2xl border border-slate-800 bg-slate-900/60 p-6">
                        <p class="text-xs font-bold uppercase tracking-wider text-emerald-300">{{ $campaign['status'] ?? __('Configured') }}</p>
                        <h3 class="mt-3 text-xl font-bold text-white">{{ $campaign['title'] ?? __('Offer') }}</h3>
                        @if (! empty($campaign['description']))
                            <p class="mt-3 text-sm leading-6 text-slate-300">{{ $campaign['description'] }}</p>
                        @endif
                        @if (! empty($campaign['valid_from']) || ! empty($campaign['valid_until']))
                            <p class="mt-4 text-xs text-slate-400">
                                @if (! empty($campaign['valid_from']))
                                    <time datetime="{{ $campaign['valid_from'] }}">{{ $campaign['valid_from'] }}</time>
                                @endif
                                @if (! empty($campaign['valid_until']))
                                    <span aria-hidden="true"> — </span><time datetime="{{ $campaign['valid_until'] }}">{{ $campaign['valid_until'] }}</time>
                                @endif
                            </p>
                        @endif
                        @if (! empty($campaign['cta_label']) && ! empty($campaign['cta_url']))
                            <a href="{{ $campaign['cta_url'] }}" class="mt-5 inline-flex rounded-lg border border-emerald-500/40 px-4 py-2 text-sm font-bold text-emerald-200 hover:bg-emerald-500/10" rel="noopener noreferrer">
                                {{ $campaign['cta_label'] }}
                            </a>
                        @endif
                    </article>
                @endforeach
            </div>
        @else
            <p class="rounded-xl border border-slate-800 bg-slate-900/40 p-5 text-sm text-slate-300" role="status">
                {{ $text['bonuses_empty'] ?? trans('home.bonuses_empty', [], app()->getLocale()) }}
            </p>
        @endif
    </div>
</section>
