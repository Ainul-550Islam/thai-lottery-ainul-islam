@extends('layouts.app')

@section('title', $product_title)
@section('meta_description', $product_description)
@section('meta_canonical', $canonical)
@section('meta_robots', 'noindex,follow')
@section('meta_og_title', $product_title)
@section('meta_og_description', $product_description)
@section('meta_og_type', 'website')
@section('meta_og_url', $canonical)

@push('styles')
    @vite(['resources/css/bingo-lottery.css'])
@endpush

@section('content')
<div class="wl-page" data-wl-page="bingo-lottery-buy">
    <a class="wl-skip-link" href="#wl-main">{{ trans('bingo_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('bingo_lottery.public_name') }}</p>
            <h1 class="wl-title">{{ trans('bingo_lottery.buy_title') }}</h1>
            <p class="wl-disclaimer">{{ trans('bingo_lottery.not_official_notice') }}</p>
        </header>
        <section class="wl-section wl-section--empty" role="status" aria-labelledby="mega-purchase-status">
            <h2 id="mega-purchase-status" class="wl-section__heading">{{ trans('bingo_lottery.purchase_status_heading') }}</h2>
            <p class="wl-empty">{{ trans('bingo_lottery.purchase_not_configured') }}</p>
            <p class="wl-intro">{{ trans('bingo_lottery.purchase_not_configured_explainer') }}</p>
            <a class="wl-link" href="{{ $back_url }}">{{ trans('bingo_lottery.back_to_results') }}</a>
        </section>
    </main>
</div>
@endsection
