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
    $product = is_array($home['product'] ?? null) ? $home['product'] : [];
    $result = is_array($home['current_result'] ?? null) ? $home['current_result'] : [];
    $nextDraw = is_array($home['next_draw'] ?? null) ? $home['next_draw'] : [];
    $live = is_array($home['live_draw'] ?? null) ? $home['live_draw'] : [];
    $prize = is_array($home['prize_highlight'] ?? null) ? $home['prize_highlight'] : [];
    $purchase = is_array($home['purchase'] ?? null) ? $home['purchase'] : [];
    $hasResult = (bool) ($result['has_result'] ?? false);
    $sourceState = strtolower((string) ($result['source_state'] ?? 'unavailable'));
    $prizeSourceState = strtolower((string) ($prize['source_state'] ?? 'unavailable'));
@endphp

<div class="glo-l6-page" data-glo-l6-home>
    <a class="glo-l6-skip" href="#glo-l6-main">{{ trans('glo_l6.skip_to_content') }}</a>
    <main id="glo-l6-main" class="glo-l6-main" tabindex="-1">
        <header class="glo-l6-header">
            <p class="glo-l6-kicker">{{ trans('glo_l6.product_label') }}</p>
            <h1>{{ trans('glo_l6.heading') }}</h1>
            <p class="glo-l6-lede">{{ trans('glo_l6.intro') }}</p>
            <p class="glo-l6-disclaimer">{{ trans('glo_l6.provenance_notice') }}</p>
        </header>

        <section class="glo-l6-hero" aria-label="{{ trans('glo_l6.product_label') }}">
            <div class="glo-l6-hero__orb" aria-hidden="true">
                <span class="glo-l6-hero__ring glo-l6-hero__ring--one"></span>
                <span class="glo-l6-hero__ring glo-l6-hero__ring--two"></span>
                <strong>L6</strong>
            </div>
            <div class="glo-l6-hero__copy">
                <p class="glo-l6-kicker">{{ trans('glo_l6.hero_kicker') }}</p>
                <h2>{{ trans('glo_l6.hero_title') }}</h2>
                <p>{{ trans('glo_l6.hero_copy') }}</p>
                @if (($product['status'] ?? '') === 'CONFIGURED' && ($product['ticket_price'] ?? '') !== '')
                    <p class="glo-l6-product-note">
                        {{ trans('glo_l6.product_parameter_label') }}:
                        <strong>{{ $product['ticket_price'] }} {{ $product['currency'] ?? '' }}</strong>
                        · {{ trans('glo_l6.digits_label', ['digits' => $product['digits'] ?? '']) }}
                    </p>
                @else
                    <p class="glo-l6-state glo-l6-state--muted">{{ trans('glo_l6.product_not_configured') }}</p>
                @endif
            </div>
        </section>

        <section class="glo-l6-grid" aria-label="{{ trans('glo_l6.overview_heading') }}">
            <article class="glo-l6-panel glo-l6-panel--result">
                <div class="glo-l6-panel__heading">
                    <div>
                        <p class="glo-l6-kicker">{{ trans('glo_l6.latest_result_label') }}</p>
                        <h2>{{ trans('glo_l6.result_heading') }}</h2>
                    </div>
                    <span class="glo-l6-source glo-l6-source--{{ $sourceState }}">
                        {{ trans('glo_l6.source_state.'.$sourceState) }}
                    </span>
                </div>

                @if ($hasResult)
                    <div class="glo-l6-result-meta">
                        @if (($result['draw_number'] ?? null) !== null)
                            <span>{{ trans('glo_l6.draw_number_label') }}: {{ $result['draw_number'] }}</span>
                        @endif
                        @if (($result['draw_date'] ?? null) !== null)
                            <time datetime="{{ $result['draw_date'] }}">{{ $result['draw_date'] }}</time>
                        @endif
                    </div>
                    <div class="glo-l6-number-row" aria-label="{{ trans('glo_l6.first_prize_label') }}">
                        <span class="glo-l6-number-label">{{ trans('glo_l6.first_prize_label') }}</span>
                        <strong class="glo-l6-number">{{ $result['first_prize'] ?? trans('glo_l6.not_published') }}</strong>
                    </div>
                    <div class="glo-l6-tier-grid">
                        @foreach ([
                            'front_three' => 'front_three_label',
                            'last_three' => 'last_three_label',
                            'last_two' => 'last_two_label',
                            'adjacent_first' => 'adjacent_label',
                        ] as $field => $labelKey)
                            @php $values = is_array($result[$field] ?? null) ? $result[$field] : []; @endphp
                            <div class="glo-l6-tier">
                                <span>{{ trans('glo_l6.'.$labelKey) }}</span>
                                <strong>{{ $values === [] ? trans('glo_l6.not_published') : implode(', ', array_map('strval', $values)) }}</strong>
                            </div>
                        @endforeach
                    </div>
                    <p class="glo-l6-footnote">{{ trans('glo_l6.result_version_label') }}: {{ $result['result_version'] ?? trans('glo_l6.not_recorded') }}</p>
                @else
                    <p class="glo-l6-empty" role="status">{{ trans('glo_l6.no_result') }}</p>
                    @if (($result['message'] ?? '') !== '')
                        <p class="glo-l6-footnote">{{ $result['message'] }}</p>
                    @endif
                @endif
            </article>

            <article class="glo-l6-panel">
                <p class="glo-l6-kicker">{{ trans('glo_l6.next_draw_label') }}</p>
                <h2>{{ trans('glo_l6.next_draw_heading') }}</h2>
                @if (($nextDraw['status'] ?? '') === 'AVAILABLE' && ($nextDraw['scheduled_at_display'] ?? null) !== null)
                    <p class="glo-l6-next-date">{{ $nextDraw['scheduled_at_display'] }}</p>
                    <p class="glo-l6-muted">{{ trans('glo_l6.timezone_label') }}: {{ $nextDraw['timezone'] ?? trans('glo_l6.not_recorded') }}</p>
                    @if (($nextDraw['draw_number'] ?? null) !== null)
                        <p class="glo-l6-muted">{{ trans('glo_l6.draw_number_label') }}: {{ $nextDraw['draw_number'] }}</p>
                    @endif
                @else
                    <p class="glo-l6-empty" role="status">{{ trans('glo_l6.next_draw_unavailable') }}</p>
                @endif
            </article>
        </section>

        <section class="glo-l6-grid" aria-label="{{ trans('glo_l6.supporting_heading') }}">
            <article class="glo-l6-panel">
                <div class="glo-l6-panel__heading">
                    <div>
                        <p class="glo-l6-kicker">{{ trans('glo_l6.prize_label') }}</p>
                        <h2>{{ trans('glo_l6.prize_heading') }}</h2>
                    </div>
                    <span class="glo-l6-source glo-l6-source--{{ $prizeSourceState }}">
                        {{ trans('glo_l6.source_state.'.$prizeSourceState) }}
                    </span>
                </div>
                @if (($prize['amount'] ?? null) !== null)
                    <p class="glo-l6-prize-amount">{{ $prize['amount'] }}</p>
                    @if (($prize['label'] ?? null) !== null)
                        <p class="glo-l6-muted">{{ $prize['label'] }}</p>
                    @endif
                @else
                    <p class="glo-l6-empty" role="status">{{ trans('glo_l6.prize_unavailable') }}</p>
                @endif
            </article>

            <article class="glo-l6-panel">
                <p class="glo-l6-kicker">{{ trans('glo_l6.live_label') }}</p>
                <h2>{{ trans('glo_l6.live_heading') }}</h2>
                @if (($live['status'] ?? '') === 'CONFIGURED' && ($live['embed_url'] ?? null) !== null)
                    <p class="glo-l6-state glo-l6-state--available">{{ trans('glo_l6.live_available') }}</p>
                    <a class="glo-l6-button glo-l6-button--quiet" href="{{ $live['embed_url'] }}" rel="noopener noreferrer">{{ trans('glo_l6.open_live') }}</a>
                @else
                    <p class="glo-l6-empty" role="status">{{ trans('glo_l6.live_unavailable') }}</p>
                @endif
            </article>
        </section>

        <section class="glo-l6-purchase" aria-labelledby="glo-l6-purchase-heading">
            <div>
                <p class="glo-l6-kicker">{{ trans('glo_l6.purchase_label') }}</p>
                <h2 id="glo-l6-purchase-heading">{{ trans('glo_l6.purchase_heading') }}</h2>
                <p>{{ trans('glo_l6.purchase_not_configured_explainer') }}</p>
                <p class="glo-l6-purchase-reason">{{ trans('glo_l6.purchase_not_configured_reason') }}</p>
            </div>
            <button class="glo-l6-button glo-l6-button--disabled" type="button" disabled aria-disabled="true">
                {{ trans('glo_l6.purchase_disabled') }}
            </button>
        </section>

        <section class="glo-l6-actions" aria-label="{{ trans('glo_l6.actions_heading') }}">
            <a class="glo-l6-button" href="{{ route('glo-l6.latest') }}">{{ trans('glo_l6.open_latest') }}</a>
            <a class="glo-l6-button glo-l6-button--quiet" href="{{ route('glo-l6.history') }}">{{ trans('glo_l6.open_history') }}</a>
            <a class="glo-l6-button glo-l6-button--quiet" href="{{ route('glo-l6.buy') }}">{{ trans('glo_l6.purchase_heading') }}</a>
            <a class="glo-l6-button glo-l6-button--quiet" href="{{ $home['checker_url'] }}">{{ trans('glo_l6.check_ticket') }}</a>
            <a class="glo-l6-button glo-l6-button--quiet" href="{{ $home['results_api_url'] }}">{{ trans('glo_l6.public_results_api') }}</a>
        </section>
    </main>
</div>

<x-public-page.footer />
@endsection
