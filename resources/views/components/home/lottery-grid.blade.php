@props([
    'text' => [],
    'products' => [],
])

@php
    $catalogueProducts = array_values(array_filter(
        (array) $products,
        static fn (mixed $product): bool => is_array($product),
    ));
@endphp

<section class="space-y-6" aria-labelledby="lottery-grid-title" id="lottery-products">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h2 id="lottery-grid-title" class="text-2xl font-black text-white tracking-tight">
                {{ $text['products_title'] ?? __('Lottery catalogue') }}
            </h2>
            <p class="mt-1 text-sm text-slate-400">
                {{ $text['products_note'] ?? trans('home.products_note') }}
            </p>
        </div>
        <span class="rounded-full border border-slate-700 px-3 py-1 text-xs font-bold uppercase tracking-wider text-slate-300">
            {{ __('Catalogue information') }}
        </span>
    </div>

    @if ($catalogueProducts === [])
        <p class="rounded-xl border border-slate-800 bg-slate-950/50 p-4 text-sm text-slate-300" role="status">
            {{ __('No lottery products are currently configured for this catalogue.') }}
        </p>
    @else
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4">
            @foreach ($catalogueProducts as $product)
                <article class="tl-ticket-3d flex flex-col justify-between rounded-xl border border-slate-800 bg-slate-950/50 p-5">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">
                            {{ $product['code'] ?? __('Product') }}
                        </p>
                        <h3 class="mt-2 text-lg font-black text-white">
                            {{ $product['name'] ?? __('Lottery product') }}
                        </h3>
                        @if (isset($product['ticket_price'], $product['currency']))
                            <p class="mt-4 text-sm text-slate-300">
                                {{ __('Configured ticket price') }}:
                                <span class="font-mono font-bold text-amber-200">{{ $product['ticket_price'] }} {{ $product['currency'] }}</span>
                            </p>
                        @endif
                        @if (! empty($product['digits']))
                            <p class="mt-2 text-sm text-slate-300">
                                {{ __('Number of digits') }}: <span class="font-mono">{{ $product['digits'] }}</span>
                            </p>
                        @endif
                        @if (! empty($product['notes']))
                            <p class="mt-3 text-xs leading-5 text-slate-400">{{ $product['notes'] }}</p>
                        @endif
                    </div>
                    @if (($product['code'] ?? null) === 'L6' || ($product['code'] ?? null) === 'N3')
                        <a href="{{ route('national-lottery.index') }}" class="mt-5 inline-flex items-center justify-center rounded-lg border border-slate-700 px-4 py-2.5 text-sm font-bold text-slate-200 hover:border-emerald-500 hover:text-white">
                            {{ __('View lottery information') }}
                        </a>
                    @endif
                </article>
            @endforeach
        </div>
    @endif

    <p class="text-xs leading-5 text-slate-500">
        {{ __('This independent platform is not operated by or endorsed by a government lottery authority.') }}
    </p>
</section>
