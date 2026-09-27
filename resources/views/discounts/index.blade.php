@extends('layouts.app')

@section('title', $meta['title'])

@section('meta_description', $meta['description'])
@section('meta_canonical', $meta['canonical'])
@section('meta_og_title', $meta['og_title'])
@section('meta_og_description', $meta['og_description'])
@section('meta_og_type', $meta['og_type'])
@section('meta_og_url', $meta['og_url'])

@push('styles')
    @vite(['resources/css/prize-discount.css'])
@endpush

@section('content')
    {{--
        Public Lotto Discount catalogue (PROMPT 4).

        Anonymous, read-only, server-computed. Nothing on this page is a
        personalised price: the account-grade layer is described but never
        quantified, and GLO products are rendered as fixed-price with an
        explicit notice instead of a rule table.
    --}}
    <div
        class="pd-page pd-page--discounts"
        data-pd-page="discounts"
        data-pd-catalogue-version="{{ $catalogue['catalogue_version'] }}"
    >
        <a class="pp-skip-link" href="#pd-main">{{ trans('public_pages.skip_to_content') }}</a>

        <main id="pd-main" class="pd-main" tabindex="-1">
            <header class="pd-header">
                <p class="pd-eyebrow">{{ $catalogue['currency'] }}</p>
                <h1 class="pd-title">{{ trans('prize_discount.discount_heading') }}</h1>
                <p class="pd-lead">{{ trans('prize_discount.discount_intro') }}</p>
                <p class="pd-muted">
                    {{ trans('prize_discount.discount_catalogue_version') }}:
                    <span class="pd-mono">{{ $catalogue['catalogue_version'] }}</span>
                    &middot;
                    {{ trans('prize_discount.discount_generated_at') }}:
                    <span class="pd-mono">{{ $catalogue['generated_at'] }}</span>
                </p>
            </header>

            @if (count($available_filters) > 1)
                <nav class="pd-filters" aria-label="{{ trans('prize_discount.discount_filter_label') }}">
                    <a
                        class="pd-filter {{ $filter === null ? 'is-active' : '' }}"
                        href="{{ route('discounts') }}"
                    >{{ trans('prize_discount.discount_filter_all') }}</a>

                    @foreach ($available_filters as $filterKey)
                        <a
                            class="pd-filter {{ $filter === $filterKey ? 'is-active' : '' }}"
                            href="{{ route('discounts', ['product' => $filterKey]) }}"
                            data-pd-filter="{{ $filterKey }}"
                        >{{ $filterKey }}</a>
                    @endforeach
                </nav>
            @endif

            <section class="pd-card pd-card--rules">
                @if ($catalogue['products'] === [])
                    <p class="pd-muted">{{ trans('prize_discount.discount_none_published') }}</p>
                @else
                    @foreach ($catalogue['products'] as $product)
                        <x-public.discount-table
                            :product="$product"
                            :currency="$catalogue['currency']"
                            :show-period="$show_effective_period"
                        />
                    @endforeach
                @endif
            </section>

            @if ($catalogue['immutable_products'] !== [])
                <section class="pd-card pd-card--immutable" data-pd-section="immutable">
                    <h2>{{ trans('prize_discount.immutable_heading') }}</h2>
                    <p class="pd-disclaimer pd-disclaimer--strong">{{ trans('prize_discount.immutable_notice') }}</p>
                    <ul class="pd-immutable">
                        @foreach ($catalogue['immutable_products'] as $immutable)
                            <li data-pd-immutable-product="{{ $immutable['product'] }}">
                                <span>{{ $immutable['product'] }}</span>
                                <span class="pd-mono">
                                    {{ trans('prize_discount.immutable_price') }}:
                                    {{ $immutable['price'] }} {{ $immutable['currency'] }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="pd-card pd-card--layers" data-pd-section="layers">
                <h2>{{ trans('prize_discount.layer_heading') }}</h2>
                <ul class="pd-notes">
                    <li>{{ trans('prize_discount.layer_server_computed') }}</li>
                    <li>{{ trans('prize_discount.layer_order') }}</li>
                    @if (($catalogue['account_grade_layer']['published'] ?? false) === true)
                        <li data-pd-layer="account_grade">
                            {{ trans($catalogue['account_grade_layer']['description_key']) }}
                        </li>
                    @endif
                </ul>
            </section>

            <section class="pd-card pd-card--payout" data-pd-section="payout">
                <h2>{{ trans('prize_discount.payout_heading') }}</h2>
                <p class="pd-muted">{{ trans('prize_discount.payout_note') }}</p>

                @if ($payout_pairs === [])
                    <p class="pd-muted">{{ trans('prize_discount.payout_none_published') }}</p>
                @else
                    <table class="pd-table">
                        <caption class="pd-sr-only">{{ trans('prize_discount.payout_heading') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ trans('prize_discount.result_product') }}</th>
                                <th scope="col">{{ trans('prize_discount.payout_direct') }}</th>
                                <th scope="col">{{ trans('prize_discount.payout_reverse') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payout_pairs as $pair)
                                <tr data-pd-payout-product="{{ $pair['product'] }}">
                                    <th scope="row">{{ $pair['product'] }}</th>
                                    @foreach (['direct', 'reverse'] as $role)
                                        <td data-pd-payout-role="{{ $role }}">
                                            @if ($pair[$role] === null)
                                                <span class="pd-muted">{{ trans('prize_discount.payout_unavailable') }}</span>
                                            @else
                                                <span class="pd-payout__market">{{ $pair[$role]['label'] }}</span>
                                                <span class="pd-mono" data-pd-multiplier>
                                                    {{ trans('prize_discount.payout_multiplier_unit', ['value' => $pair[$role]['multiplier']]) }}
                                                </span>
                                                <span class="pd-muted">
                                                    {{ trans('prize_discount.payout_digits') }}: {{ $pair[$role]['digits'] }}
                                                    &middot;
                                                    {{ trans('prize_discount.payout_permutation') }}:
                                                    {{ $pair[$role]['permutation']
                                                        ? trans('prize_discount.payout_permutation_yes')
                                                        : trans('prize_discount.payout_permutation_no') }}
                                                </span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            <section class="pd-card pd-card--affiliate" data-pd-section="affiliate">
                <h2>{{ trans('prize_discount.affiliate_heading') }}</h2>

                @if ($affiliate['published'] === false || $affiliate['bands'] === [])
                    <p class="pd-muted">{{ trans('prize_discount.affiliate_not_published') }}</p>
                @else
                    <p class="pd-muted">{{ trans($affiliate['note_key']) }}</p>
                    <table class="pd-table">
                        <caption class="pd-sr-only">{{ trans('prize_discount.affiliate_heading') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ trans('prize_discount.affiliate_band') }}</th>
                                <th scope="col">{{ trans('prize_discount.affiliate_rate') }}</th>
                                <th scope="col">{{ trans('prize_discount.affiliate_eligibility') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($affiliate['bands'] as $band)
                                <tr data-pd-affiliate-band="{{ $band['band'] }}">
                                    <th scope="row">{{ trans($band['label_key']) }}</th>
                                    <td class="pd-mono">{{ $band['rate_percentage'] }}{{ trans('prize_discount.percent_suffix') }}</td>
                                    <td>{{ trans($band['eligibility_key']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            <p class="pd-disclaimer">{{ trans('prize_discount.privacy_notice') }}</p>
        </main>

        <x-public-page.footer />
    </div>
@endsection
