@extends('layouts.app')

@php
    $catalogue = is_array($discounts['catalogue'] ?? null) ? $discounts['catalogue'] : (is_array($catalogue ?? null) ? $catalogue : []);
    $products = is_array($catalogue['products'] ?? null) ? $catalogue['products'] : [];
    $immutable = is_array($catalogue['immutable_products'] ?? null) ? $catalogue['immutable_products'] : [];
    $matrix = is_array($matrix ?? null) ? $matrix : [];
    $matrixLotteries = is_array($matrix['lotteries'] ?? null) ? $matrix['lotteries'] : [];
    $affiliate = is_array($affiliate ?? null) ? $affiliate : [];
    $affiliateBands = is_array($affiliate['bands'] ?? null) ? $affiliate['bands'] : [];
@endphp

@section('title', (string) ($meta['title'] ?? $discounts['meta_title'] ?? trans('discounts_page.brand')))
@section('meta_description', (string) ($meta['description'] ?? $discounts['meta_description'] ?? trans('discounts_page.note')))
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/discounts')))
@section('meta_og_title', (string) ($meta['og_title'] ?? $discounts['meta_title'] ?? trans('discounts_page.brand')))
@section('meta_og_description', (string) ($meta['og_description'] ?? $discounts['meta_description'] ?? ''))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@section('meta_og_url', (string) ($meta['og_url'] ?? url('/discounts')))

@push('styles')
    @vite('resources/css/pages/discounts.css')
@endpush

@section('content')
<div class="next-public-page next-public-page--discount" data-next-public-page="discounts" data-pd-page="discounts">
    <a class="pp-skip-link" href="#discount-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header"><div class="next-shell next-page-header__inner"><a class="next-brand" href="{{ route('home') }}" aria-label="{{ trans('discounts_page.home_aria') }}"><span class="next-brand__mark">TL</span><span>{{ config('app.name') }}<small>{{ trans('discounts_page.brand') }}</small></span></a><nav class="next-nav" aria-label="{{ trans('discounts_page.nav_aria') }}"><a href="{{ route('home') }}">{{ trans('discounts_page.nav_home') }}</a><a href="{{ route('results.index') }}">{{ trans('discounts_page.nav_results') }}</a><a class="is-active" href="{{ route('discounts') }}" aria-current="page">{{ trans('discounts_page.nav_discounts') }}</a><a href="{{ route('fees') }}">{{ trans('discounts_page.nav_fees') }}</a><a href="{{ route('contact') }}">{{ trans('discounts_page.nav_contact') }}</a></nav>@guest<a class="next-button next-button--gold" href="{{ route('login') }}">{{ trans('discounts_page.nav_login') }}</a>@else<a class="next-button next-button--gold" href="{{ route('player.dashboard') }}">{{ trans('discounts_page.nav_dashboard') }}</a>@endguest</div></header>
    <main id="discount-main" class="next-shell next-content" tabindex="-1">
        <section class="next-hero" aria-labelledby="discount-title"><div><p class="next-eyebrow">{{ trans('discounts_page.eyebrow') }}</p><h1 id="discount-title">{{ trans('prize_discount.discount_heading') }}</h1><p>{{ $discounts['meta_description'] ?? trans('discounts_page.note') }}</p><p class="next-note">{{ trans('discounts_page.note') }}</p></div><div class="next-hero-object next-hero-object--discount" aria-hidden="true"><span>%</span></div></section>
        <section class="next-meta-strip" aria-label="{{ trans('discounts_page.metadata') }}"><div><span>{{ trans('discounts_page.catalogue_version') }}</span><strong>{{ $catalogue['catalogue_version'] ?? 'NOT_CONFIGURED' }}</strong></div><div><span>{{ trans('discounts_page.currency') }}</span><strong>{{ $catalogue['currency'] ?? 'NOT_CONFIGURED' }}</strong></div><div><span>{{ trans('discounts_page.products') }}</span><strong>{{ count($products) }}</strong></div><div><span>{{ trans('discounts_page.generated') }}</span><strong>{{ $catalogue['generated_at'] ?? 'NOT_CONFIGURED' }}</strong></div></section>
        <div class="next-layout"><aside class="next-sidebar" aria-label="{{ trans('discounts_page.contents_aria') }}"><p class="next-eyebrow">{{ trans('discounts_page.catalogue_map') }}</p>@foreach ($products as $product)<a href="#discount-{{ $product['product'] ?? $loop->iteration }}" data-content-link="discount-{{ $product['product'] ?? $loop->iteration }}">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} · {{ $product['product'] ?? trans('discounts_page.product_fallback') }}</a>@endforeach<a href="#discount-matrix" data-content-link="discount-matrix">{{ trans('discounts_page.matrix') }}</a><a href="#affiliate-commissions" data-content-link="affiliate-commissions">{{ trans('prize_discount.affiliate_heading') }}</a><a href="#discount-immutable" data-content-link="discount-immutable">{{ trans('discounts_page.fixed_items') }}</a></aside><div>
            <div class="next-search" role="search"><label for="discount-search">{{ trans('discounts_page.search_label') }}</label><div class="next-search__row"><input id="discount-search" type="search" data-local-search placeholder="{{ trans('discounts_page.search_placeholder') }}" autocomplete="off"><button class="next-button" type="button" data-clear-search>{{ trans('discounts_page.clear') }}</button></div><p class="next-search__status" data-search-status aria-live="polite">{{ trans('discounts_page.showing_all') }}</p></div>
            @forelse ($products as $product)
                @php $productId = 'discount-'.($product['product'] ?? $loop->iteration); $productLabel = (string) trans($product['label_key'] ?? ''); $productLabel = $productLabel !== (string) ($product['label_key'] ?? '') ? $productLabel : ucfirst(str_replace('_', ' ', (string) ($product['product'] ?? trans('discounts_page.product_fallback')))); @endphp
                <section class="next-section" id="{{ $productId }}" data-content-section data-search-item data-search-text="{{ ($productLabel).' '.implode(' ', (array) ($product['markets'] ?? [])).' '.json_encode($product['rules'] ?? []) }}" aria-labelledby="{{ $productId }}-title"><div class="next-section__heading"><span class="next-section__number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><div><p class="next-eyebrow">{{ trans('discounts_page.configured_product') }}</p><h2 id="{{ $productId }}-title">{{ $productLabel }}</h2></div></div><p class="next-muted">{{ trans('discounts_page.markets') }}: {{ $product['markets'] === [] ? 'NOT_CONFIGURED' : implode(', ', (array) $product['markets']) }}</p><div class="next-table-wrap"><table class="next-table"><caption class="sr-only">{{ trans('discounts_page.caption_rules', ['product' => $productLabel]) }}</caption><thead><tr><th scope="col">{{ trans('discounts_page.market') }}</th><th scope="col">{{ trans('discounts_page.type') }}</th><th scope="col">{{ trans('discounts_page.value') }}</th><th scope="col">{{ trans('discounts_page.minimum') }}</th><th scope="col">{{ trans('discounts_page.maximum') }}</th><th scope="col">{{ trans('discounts_page.stackable') }}</th></tr></thead><tbody>@forelse ((array) ($product['rules'] ?? []) as $rule)<tr><td>{{ $rule['market'] ?? 'NOT_CONFIGURED' }}</td><td>{{ $rule['discount_type'] ?? 'NOT_CONFIGURED' }}</td><td>{{ $rule['discount_value'] ?? 'NOT_CONFIGURED' }}</td><td>{{ $rule['minimum_amount'] ?? '—' }}</td><td>{{ $rule['maximum_discount'] ?? '—' }}</td><td>{{ !empty($rule['stackable']) ? trans('discounts_page.yes') : trans('discounts_page.no') }}</td></tr>@empty<tr><td colspan="6">{{ trans('discounts_page.no_rules') }}</td></tr>@endforelse</tbody></table></div></section>
            @empty
                <section class="next-panel" role="status"><h2>{{ trans('discounts_page.empty_title') }}</h2><p>{{ trans('discounts_page.empty_body') }}</p></section>
            @endforelse
            <section class="next-section" id="discount-matrix" data-content-section aria-labelledby="discount-matrix-title">
                <div class="next-section__heading"><span class="next-section__number">Σ</span><div><p class="next-eyebrow">{{ trans('discounts_page.matrix') }}</p><h2 id="discount-matrix-title">{{ trans('prize_discount.matrix_col_discount') }}</h2></div></div>
                @if ($matrixLotteries === [])
                    <p class="next-empty">NOT_CONFIGURED</p>
                @else
                    <div class="next-table-wrap" data-rates-source="server-projection"><table class="next-table">
                        <thead><tr><th>{{ trans('discounts_page.lottery') }}</th><th>{{ trans('prize_discount.matrix_col_game') }}</th><th>{{ trans('prize_discount.matrix_col_discount') }}</th><th>{{ trans('prize_discount.matrix_col_win') }}</th></tr></thead><tbody>
                        @foreach ($matrixLotteries as $lottery)
                            <tr data-pd-matrix-lottery="{{ $lottery['key'] ?? '' }}"><th colspan="4" scope="rowgroup">{{ $lottery['label'] ?? 'NOT_CONFIGURED' }}</th></tr>
                            @foreach ((array) ($lottery['games'] ?? []) as $game)
                                <tr data-pd-matrix-game="{{ $game['game'] ?? '' }}"><td>{{ $lottery['label'] ?? 'NOT_CONFIGURED' }}</td><td>{{ $game['label'] ?? 'NOT_CONFIGURED' }}</td><td>{{ $game['discount_display'] ?? 'NOT_CONFIGURED' }}</td><td>
                                    @if ($game['has_modes'] ?? false)
                                        D: {{ $game['base_stake'] ?? 'NOT_CONFIGURED' }} × {{ $game['d_multiplier'] ?? 'NOT_CONFIGURED' }} {{ $game['currency'] ?? '' }}<br>
                                        R: {{ $game['base_stake'] ?? 'NOT_CONFIGURED' }} × {{ $game['r_multiplier'] ?? 'NOT_CONFIGURED' }} {{ $game['currency'] ?? '' }}
                                    @else
                                        {{ $game['base_stake'] ?? 'NOT_CONFIGURED' }} × {{ $game['multiplier'] ?? 'NOT_CONFIGURED' }} {{ $game['currency'] ?? '' }}
                                    @endif
                                </td></tr>
                            @endforeach
                        @endforeach
                        </tbody></table></div>
                @endif
            </section>
            <section class="next-section" id="affiliate-commissions" data-content-section aria-labelledby="affiliate-commissions-title"><div class="next-section__heading"><span class="next-section__number">A</span><div><p class="next-eyebrow">{{ trans('prize_discount.affiliate_band') }}</p><h2 id="affiliate-commissions-title">{{ trans('prize_discount.affiliate_heading') }}</h2></div></div>@if (($affiliate['published'] ?? false) !== true || $affiliateBands === [])<p class="next-empty" role="status">{{ trans('prize_discount.affiliate_not_published') }}</p>@else<p class="next-muted">{{ trans('prize_discount.affiliate_note') }}</p><div class="next-table-wrap"><table class="next-table"><thead><tr><th scope="col">{{ trans('prize_discount.affiliate_band') }}</th><th scope="col">{{ trans('prize_discount.affiliate_rate') }}</th><th scope="col">{{ trans('prize_discount.affiliate_eligibility') }}</th></tr></thead><tbody>@foreach ($affiliateBands as $band)<tr><th scope="row">{{ trans($band['label_key']) }}</th><td>{{ number_format((float) ($band['rate_percentage'] ?? 0), 2, '.', '') }}%</td><td>{{ trans($band['eligibility_key']) }}</td></tr>@endforeach</tbody></table></div>@endif<p class="mt-4"><a class="next-button" href="{{ route('contact') }}">{{ trans('prize_discount.affiliate_contact') }}</a></p></section>
            <section class="next-section" id="discount-immutable" data-content-section aria-labelledby="discount-immutable-title"><div class="next-section__heading"><span class="next-section__number">{{ trans('discounts_page.fix') }}</span><div><p class="next-eyebrow">{{ trans('discounts_page.protected_prices') }}</p><h2 id="discount-immutable-title">{{ trans('discounts_page.immutable_title') }}</h2></div></div><div class="next-section__body">@if ($immutable === [])<p class="next-empty">NOT_CONFIGURED</p>@else<p>{{ trans('discounts_page.immutable_body') }}</p><ul>@foreach ($immutable as $item)<li>{{ $item['product'] ?? trans('discounts_page.product_fallback') }}: {{ $item['price'] ?? 'NOT_CONFIGURED' }} {{ $item['currency'] ?? '' }}</li>@endforeach</ul>@endif<p class="next-muted">{{ trans('discounts_page.personal_pricing_note') }}</p></div></section>
        </div></div>
        <section class="next-bottom"><div><p class="next-eyebrow">{{ trans('discounts_page.calculate_safely') }}</p><h2>{{ trans('discounts_page.server_quote') }}</h2><p>{{ trans('discounts_page.quote_note') }}</p></div><div class="next-bottom__links"><button class="next-button next-button--gold" type="button" data-print-page>{{ trans('discounts_page.print_save') }}</button><a class="next-button" href="{{ route('contact') }}">{{ trans('discounts_page.contact_support') }}</a></div></section>
    </main>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/discounts.js')
@endpush
