@extends('layouts.app')

@section('title', $meta['title'])
@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_robots', $meta['robots'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/pcso-lottery.css'])
@endpush

@section('content')
<div class="wl-page pcso-page" data-wl-page="pcso-lottery-buy" data-wl-locale="{{ $meta['lang'] }}">
    <a class="wl-skip-link" href="#wl-main">{{ trans('pcso_lottery.skip_to_content') }}</a>
    <main id="wl-main" class="wl-main" tabindex="-1">
        <nav class="wl-breadcrumb" aria-label="{{ trans('pcso_lottery.heading') }}">
            <a class="wl-breadcrumb__link" href="{{ $back_url }}">{{ trans('pcso_lottery.heading') }}</a>
        </nav>

        <header class="wl-header">
            <p class="wl-eyebrow">{{ trans('pcso_lottery.public_name') }}</p>
            <h1 class="wl-title">{{ trans('pcso_lottery.buy_title') }}</h1>
            <p class="wl-intro">{{ trans('pcso_lottery.purchase_intro') }}</p>
        </header>

        <section class="pcso-purchase-state pcso-purchase-state--disabled" aria-labelledby="pcso-purchase-state-heading">
            <div class="pcso-purchase-state__icon" aria-hidden="true">!</div>
            <div class="pcso-purchase-state__content">
                <p class="pcso-purchase-state__eyebrow">{{ trans('pcso_lottery.purchase_status_heading') }}</p>
                <h2 id="pcso-purchase-state-heading">{{ $capability['status'] }}</h2>
                <p>{{ trans('pcso_lottery.purchase_not_configured_explainer') }}</p>
                <p class="pcso-purchase-state__reason">{{ $capability['reason'] }}</p>
            </div>
        </section>

        <section class="wl-section" aria-labelledby="pcso-missing-contract-heading">
            <h2 id="pcso-missing-contract-heading" class="wl-section__heading">{{ trans('pcso_lottery.purchase_contract_heading') }}</h2>
            <p class="wl-section__subheading">{{ trans('pcso_lottery.purchase_contract_intro') }}</p>
            <ul class="pcso-contract-list">
                @foreach ($capability['missing'] as $missing)
                    <li>{{ $missing }}</li>
                @endforeach
            </ul>
            <p class="wl-disclaimer">{{ trans('pcso_lottery.purchase_no_action_notice') }}</p>
        </section>

        <nav class="wl-actions" aria-label="{{ trans('pcso_lottery.public_name') }}">
            <a class="wl-link" href="{{ $back_url }}">{{ trans('pcso_lottery.back_to_results') }}</a>
            <a class="wl-link" href="{{ route('pcso-lottery.latest') }}">{{ trans('pcso_lottery.latest_link') }}</a>
        </nav>
    </main>
</div>

<x-public-page.footer />
@endsection
