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
    @vite(['resources/css/glo-l6.css'])
@endpush

@section('content')
@php
    $purchase = is_array($capability ?? null) ? $capability : [];
    $missing = is_array($purchase['missing'] ?? null) ? $purchase['missing'] : [];
    $missingLabels = [
        'public product and draw contract' => trans('glo_l6.missing_product_draw'),
        'selection inventory and validation contract' => trans('glo_l6.missing_selection_validation'),
        'responsible-gaming admission gate' => trans('glo_l6.missing_responsible_gaming'),
        'wallet reservation and debit endpoint' => trans('glo_l6.missing_wallet_reservation'),
        'ticket issuance, ledger and idempotency endpoint' => trans('glo_l6.missing_ticket_ledger'),
    ];
    $enabled = (bool) ($purchase['enabled'] ?? false);
@endphp

<div class="glo-l6-page">
    <a class="glo-l6-skip" href="#glo-l6-buy-main">{{ trans('glo_l6.skip_to_content') }}</a>
    <main id="glo-l6-buy-main" class="glo-l6-main" tabindex="-1">
        <header class="glo-l6-header">
            <p class="glo-l6-kicker">{{ trans('glo_l6.product_label') }}</p>
            <h1>{{ trans('glo_l6.buy_page_heading') }}</h1>
            <p class="glo-l6-lede">{{ trans('glo_l6.buy_page_lead') }}</p>
        </header>

        <section class="glo-l6-purchase glo-l6-purchase--page" aria-labelledby="glo-l6-buy-heading">
            <div>
                <p class="glo-l6-kicker">{{ trans('glo_l6.purchase_label') }}</p>
                <h2 id="glo-l6-buy-heading">{{ trans('glo_l6.purchase_heading') }}</h2>
                @if ($enabled)
                    <p>{{ trans('glo_l6.purchase_enabled_notice') }}</p>
                @else
                    <p>{{ trans('glo_l6.purchase_not_configured_explainer') }}</p>
                    <p class="glo-l6-purchase-reason">{{ trans('glo_l6.purchase_not_configured_reason') }}</p>
                @endif
            </div>
            <span class="glo-l6-source glo-l6-source--{{ strtolower((string) ($purchase['status'] ?? 'not_configured')) }}">
                {{ $enabled ? trans('glo_l6.purchase_available') : trans('glo_l6.purchase_disabled') }}
            </span>
        </section>

        @if (! $enabled)
            <section class="glo-l6-panel" aria-labelledby="glo-l6-missing-heading">
                <p class="glo-l6-kicker">{{ trans('glo_l6.not_configured_label') }}</p>
                <h2 id="glo-l6-missing-heading">{{ trans('glo_l6.missing_contract_heading') }}</h2>
                <p class="glo-l6-muted">{{ trans('glo_l6.missing_contract_lead') }}</p>
                <ul class="glo-l6-missing-list">
                    @forelse ($missing as $requirement)
                        <li>{{ $missingLabels[$requirement] ?? trans('glo_l6.not_recorded') }}</li>
                    @empty
                        <li>{{ trans('glo_l6.not_recorded') }}</li>
                    @endforelse
                </ul>
            </section>
        @endif

        <div class="glo-l6-actions">
            <a class="glo-l6-button glo-l6-button--quiet" href="{{ route('glo-l6.index') }}">{{ trans('glo_l6.back_to_l6') }}</a>
            <a class="glo-l6-button" href="{{ route('glo-l6.history') }}">{{ trans('glo_l6.open_history') }}</a>
        </div>
    </main>
</div>
@endsection
