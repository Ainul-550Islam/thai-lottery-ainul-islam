@extends('layouts.app')

@php
    $catalogue = is_array($discounts['catalogue'] ?? null) ? $discounts['catalogue'] : (is_array($catalogue ?? null) ? $catalogue : []);
    $products = is_array($catalogue['products'] ?? null) ? $catalogue['products'] : [];
    $immutable = is_array($catalogue['immutable_products'] ?? null) ? $catalogue['immutable_products'] : [];
    $matrix = is_array($matrix ?? null) ? $matrix : [];
    $matrixLotteries = is_array($matrix['lotteries'] ?? null) ? $matrix['lotteries'] : [];
@endphp

@section('title', (string) ($meta['title'] ?? $discounts['meta_title'] ?? 'Discounts'))
@section('meta_description', (string) ($meta['description'] ?? $discounts['meta_description'] ?? 'Configured discount catalogue.'))
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/discounts')))
@section('meta_og_title', (string) ($meta['og_title'] ?? $discounts['meta_title'] ?? 'Discounts'))
@section('meta_og_description', (string) ($meta['og_description'] ?? $discounts['meta_description'] ?? ''))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@section('meta_og_url', (string) ($meta['og_url'] ?? url('/discounts')))

@push('styles')
    @vite('resources/css/pages/discounts.css')
@endpush

@section('content')
<div class="next-public-page next-public-page--discount" data-next-public-page="discounts" data-pd-page="discounts">
    <a class="pp-skip-link" href="#discount-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header"><div class="next-shell next-page-header__inner"><a class="next-brand" href="{{ route('home') }}" aria-label="Home"><span class="next-brand__mark">TL</span><span>THAILOTTO<small>DISCOUNT CATALOGUE</small></span></a><nav class="next-nav" aria-label="Primary navigation"><a href="{{ route('home') }}">HOME</a><a href="{{ route('results.index') }}">RESULTS</a><a class="is-active" href="{{ route('discounts') }}" aria-current="page">DISCOUNTS</a><a href="{{ route('fees') }}">FEES</a><a href="{{ route('contact') }}">CONTACT</a></nav>@guest<a class="next-button next-button--gold" href="{{ route('login') }}">LOGIN</a>@else<a class="next-button next-button--gold" href="{{ route('player.dashboard') }}">DASHBOARD</a>@endguest</div></header>
    <main id="discount-main" class="next-shell next-content" tabindex="-1">
        <section class="next-hero" aria-labelledby="discount-title"><div><p class="next-eyebrow">10 · SERVER-AUTHORITATIVE PRICING</p><h1 id="discount-title">{{ trans('prize_discount.discount_heading') }}</h1><p>{{ $discounts['meta_description'] ?? 'Configured product, market and discount rules.' }}</p><p class="next-note">The displayed catalogue is descriptive. Quotes are calculated server-side from the same rule source; the browser cannot supply a discount rate or final amount.</p></div><div class="next-hero-object next-hero-object--discount" aria-hidden="true"><span>%</span></div></section>
        <section class="next-meta-strip" aria-label="Discount catalogue metadata"><div><span>CATALOGUE VERSION</span><strong>{{ $catalogue['catalogue_version'] ?? 'NOT_CONFIGURED' }}</strong></div><div><span>CURRENCY</span><strong>{{ $catalogue['currency'] ?? 'NOT_CONFIGURED' }}</strong></div><div><span>PRODUCTS</span><strong>{{ count($products) }}</strong></div><div><span>GENERATED</span><strong>{{ $catalogue['generated_at'] ?? 'NOT_CONFIGURED' }}</strong></div></section>
        <div class="next-layout"><aside class="next-sidebar" aria-label="Discount contents"><p class="next-eyebrow">CATALOGUE MAP</p>@foreach ($products as $product)<a href="#discount-{{ $product['product'] ?? $loop->iteration }}" data-content-link="discount-{{ $product['product'] ?? $loop->iteration }}">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} · {{ $product['product'] ?? 'Product' }}</a>@endforeach<a href="#discount-immutable" data-content-link="discount-immutable">Fixed-price items</a></aside><div>
            <div class="next-search" role="search"><label for="discount-search">Search the catalogue</label><div class="next-search__row"><input id="discount-search" type="search" data-local-search placeholder="Search product, market or rule" autocomplete="off"><button class="next-button" type="button" data-clear-search>CLEAR</button></div><p class="next-search__status" data-search-status aria-live="polite">Showing all catalogue items.</p></div>
            @forelse ($products as $product)
                @php $productId = 'discount-'.($product['product'] ?? $loop->iteration); $productLabel = (string) trans($product['label_key'] ?? ''); $productLabel = $productLabel !== (string) ($product['label_key'] ?? '') ? $productLabel : ucfirst(str_replace('_', ' ', (string) ($product['product'] ?? 'Product'))); @endphp
                <section class="next-section" id="{{ $productId }}" data-content-section data-search-item data-search-text="{{ ($productLabel).' '.implode(' ', (array) ($product['markets'] ?? [])).' '.json_encode($product['rules'] ?? []) }}" aria-labelledby="{{ $productId }}-title"><div class="next-section__heading"><span class="next-section__number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><div><p class="next-eyebrow">CONFIGURED PRODUCT</p><h2 id="{{ $productId }}-title">{{ $productLabel }}</h2></div></div><p class="next-muted">Markets: {{ $product['markets'] === [] ? 'NOT_CONFIGURED' : implode(', ', (array) $product['markets']) }}</p><div class="next-table-wrap"><table class="next-table"><caption class="sr-only">Discount rules for {{ $productLabel }}</caption><thead><tr><th scope="col">Market</th><th scope="col">Type</th><th scope="col">Value</th><th scope="col">Minimum</th><th scope="col">Maximum</th><th scope="col">Stackable</th></tr></thead><tbody>@forelse ((array) ($product['rules'] ?? []) as $rule)<tr><td>{{ $rule['market'] ?? 'NOT_CONFIGURED' }}</td><td>{{ $rule['discount_type'] ?? 'NOT_CONFIGURED' }}</td><td>{{ $rule['discount_value'] ?? 'NOT_CONFIGURED' }}</td><td>{{ $rule['minimum_amount'] ?? '—' }}</td><td>{{ $rule['maximum_discount'] ?? '—' }}</td><td>{{ !empty($rule['stackable']) ? 'YES' : 'NO' }}</td></tr>@empty<tr><td colspan="6">No public rules configured for this product.</td></tr>@endforelse</tbody></table></div></section>
            @empty
                <section class="next-panel" role="status"><h2>Discount catalogue unavailable</h2><p>No public products are currently configured.</p></section>
            @endforelse
            <section class="next-section" id="discount-matrix" data-content-section aria-labelledby="discount-matrix-title"><div class="next-section__heading"><span class="next-section__number">Σ</span><div><p class="next-eyebrow">PUBLIC GAME MATRIX</p><h2 id="discount-matrix-title">{{ trans('prize_discount.matrix_col_discount') }}</h2></div></div>@if ($matrixLotteries === [])<p class="next-empty">NOT_CONFIGURED</p>@else<div class="next-table-wrap"><table class="next-table"><thead><tr><th>Lottery</th><th>{{ trans('prize_discount.matrix_col_game') }}</th><th>{{ trans('prize_discount.matrix_col_discount') }}</th><th>{{ trans('prize_discount.matrix_col_win') }}</th></tr></thead><tbody>@foreach ($matrixLotteries as $lottery)<tr data-pd-matrix-lottery="{{ $lottery['key'] ?? '' }}"><th colspan="4" scope="rowgroup">{{ $lottery['label'] ?? 'NOT_CONFIGURED' }}</th></tr>@foreach ((array) ($lottery['games'] ?? []) as $game)<tr><td>{{ $lottery['label'] ?? 'NOT_CONFIGURED' }}</td><td>{{ $game['label'] ?? 'NOT_CONFIGURED' }}</td><td>{{ $game['discount_display'] ?? 'NOT_CONFIGURED' }}</td><td>{{ $game['d_multiplier'] ?? $game['multiplier'] ?? 'NOT_CONFIGURED' }} × {{ $game['base_stake'] ?? 'NOT_CONFIGURED' }} {{ $game['currency'] ?? '' }}</td></tr>@endforeach @endforeach</tbody></table></div>@endif</section>
            <section class="next-section" id="discount-immutable" data-content-section aria-labelledby="discount-immutable-title"><div class="next-section__heading"><span class="next-section__number">FIX</span><div><p class="next-eyebrow">PROTECTED PRICES</p><h2 id="discount-immutable-title">Immutable product prices</h2></div></div><div class="next-section__body">@if ($immutable === [])<p class="next-empty">NOT_CONFIGURED</p>@else<p>These prices are displayed from the configured protected-price audit and are not rewritten by public discount rules.</p><ul>@foreach ($immutable as $item)<li>{{ $item['product'] ?? 'Product' }}: {{ $item['price'] ?? 'NOT_CONFIGURED' }} {{ $item['currency'] ?? '' }}</li>@endforeach</ul>@endif<p class="next-muted">Personal account-grade pricing is resolved inside the authenticated quote context and is not disclosed here.</p></div></section>
        </div></div>
        <section class="next-bottom"><div><p class="next-eyebrow">CALCULATE SAFELY</p><h2>Use the server quote</h2><p>Only published product and amount inputs are accepted. Client-provided rates are ignored.</p></div><div class="next-bottom__links"><button class="next-button next-button--gold" type="button" data-print-page>PRINT / SAVE</button><a class="next-button" href="{{ route('contact') }}">CONTACT SUPPORT</a></div></section>
    </main>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/discounts.js')
@endpush
