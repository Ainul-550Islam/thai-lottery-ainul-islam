@extends('layouts.app')

@php
    $groups = is_array($fees['groups'] ?? null) ? $fees['groups'] : [];
    $rows = is_array($fees['rows'] ?? null) ? $fees['rows'] : [];
    if ($rows === []) {
        foreach ($groups as $feeGroup) {
            foreach ((array) ($feeGroup['rows'] ?? []) as $feeRow) {
                $rows[] = $feeRow;
            }
        }
    }
    $previewCategories = [];
    foreach (($preview['categories'] ?? []) as $option) {
        $previewCategories[(string) $option['value']] = (string) $option['label'];
    }
    $previewProviders = [];
    foreach (($preview['providers'] ?? []) as $option) {
        $previewProviders[(string) $option['value']] = (string) $option['label'];
    }
    foreach ($rows as $feeRow) {
        $feeKey = (string) ($feeRow['key'] ?? '');
        if ($feeKey !== '') {
            $previewCategories[$feeKey] ??= (string) ($feeRow['label'] ?? $feeKey);
        }
        $providerKey = (string) ($feeRow['provider'] ?? '');
        if ($providerKey !== '') {
            $previewProviders[$providerKey] ??= (string) ($feeRow['provider_label'] ?? $providerKey);
        }
    }
@endphp

@section('title', (string) ($meta['title'] ?? trans('account_services.fees_title', [], app()->getLocale())))
@section('meta_description', (string) ($meta['description'] ?? $fees['meta_description'] ?? 'Published fee schedule.'))
@section('meta_canonical', (string) ($meta['canonical'] ?? url('/fees')))
@section('meta_og_title', (string) ($meta['og_title'] ?? $fees['meta_title'] ?? 'Fees'))
@section('meta_og_description', (string) ($meta['og_description'] ?? $fees['meta_description'] ?? ''))
@section('meta_og_type', (string) ($meta['og_type'] ?? 'website'))
@section('meta_og_url', (string) ($meta['og_url'] ?? url('/fees')))

@push('styles')
    @vite('resources/css/pages/fees.css')
@endpush

@section('content')
<div class="next-public-page next-public-page--fees" data-next-public-page="fees">
    <a class="pp-skip-link" href="#fees-main">{{ trans('public_pages.skip_to_content') }}</a>
    <header class="next-page-header"><div class="next-shell next-page-header__inner"><a class="next-brand" href="{{ route('home') }}" aria-label="{{ trans('fees_page.home_aria') }}"><span class="next-brand__mark">TL</span><span>{{ config('app.name') }}<small>{{ trans('fees_page.brand') }}</small></span></a><nav class="next-nav" aria-label="{{ trans('fees_page.nav_aria') }}"><a href="{{ route('home') }}">{{ trans('fees_page.nav_home') }}</a><a href="{{ route('about') }}">{{ trans('fees_page.nav_about') }}</a><a href="{{ route('results.index') }}">{{ trans('fees_page.nav_results') }}</a><a class="is-active" href="{{ route('fees') }}" aria-current="page">{{ trans('fees_page.nav_fees') }}</a><a href="{{ route('contact') }}">{{ trans('fees_page.nav_contact') }}</a></nav>@guest<a class="next-button next-button--gold" href="{{ route('login') }}">{{ trans('fees_page.nav_login') }}</a>@else<a class="next-button next-button--gold" href="{{ route('player.dashboard') }}">{{ trans('fees_page.nav_dashboard') }}</a>@endguest</div></header>
    <div id="fees-main" class="next-shell next-content" tabindex="-1">
        <section class="next-hero" aria-labelledby="fees-title"><div><p class="next-eyebrow">{{ trans('fees_page.eyebrow') }}</p><h1 id="fees-title">{{ $fees['title'] ?? 'Fees' }}</h1><p>{{ $fees['lead'] ?? trans('account_services.fees_lead') }}</p><p class="next-note">{{ trans('fees_page.note') }}</p></div><div class="next-hero-object next-hero-object--fees" aria-hidden="true"><span>฿</span></div></section>
        <section class="next-meta-strip" aria-label="{{ trans('fees_page.metadata') }}"><div><span>{{ trans('account_services.fees_rule_version_label') }}</span><strong>{{ $fees['version'] ?? $fees['rule_version'] ?? 'NOT_CONFIGURED' }}</strong></div><div><span>{{ trans('fees_page.currency') }}</span><strong>{{ $fees['currency'] ?? 'NOT_CONFIGURED' }}</strong></div><div><span>{{ trans('fees_page.public_rows') }}</span><strong>{{ count($rows) }}</strong></div><div><span>{{ trans('fees_page.calculation') }}</span><strong>{{ trans('fees_page.server_side') }}</strong></div></section>
        <div class="next-layout"><aside class="next-sidebar" aria-label="{{ trans('fees_page.sections_aria') }}"><p class="next-eyebrow">{{ trans('fees_page.sections') }}</p>@foreach ($groups as $group)<a href="#fee-{{ $group['key'] ?? $loop->iteration }}" data-content-link="fee-{{ $group['key'] ?? $loop->iteration }}">{{ $group['label'] ?? trans('fees_page.group') }}</a>@endforeach<a href="#fee-methodology" data-content-link="fee-methodology">{{ trans('fees_page.methodology_link') }}</a></aside><div>
            <div class="next-search" role="search"><label for="fees-search">{{ trans('fees_page.search_label') }}</label><div class="next-search__row"><input id="fees-search" type="search" data-local-search placeholder="{{ trans('fees_page.search_placeholder') }}" autocomplete="off"><button class="next-button" type="button" data-clear-search>{{ trans('fees_page.clear') }}</button></div><p class="next-search__status" data-search-status aria-live="polite">{{ trans('fees_page.rows_shown', ['count' => count($rows)]) }}</p></div>
            <section class="next-section" id="fee-preview" data-content-section aria-labelledby="fee-preview-title"><div class="next-section__heading"><span class="next-section__number">ƒ</span><div><p class="next-eyebrow">{{ trans('fees_page.server_calculation') }}</p><h2 id="fee-preview-title">{{ trans('account_services.fees_preview_title') }}</h2></div></div><div class="next-section__body"><p>{{ trans('account_services.fees_preview_lead') }}</p><form class="next-form" method="post" action="{{ route('api.v1.fees.preview') }}" data-fee-preview-form>@csrf<div class="next-form__grid"><div><label for="fee-preview-category">{{ trans('account_services.fees_preview_category_label') }}</label><select id="fee-preview-category" name="category" required>@forelse ($previewCategories as $categoryKey => $categoryLabel)<option value="{{ $categoryKey }}">{{ $categoryLabel }}</option>@empty<option value="">{{ trans('account_services.fees_not_configured') }}</option>@endforelse</select></div><div><label for="fee-preview-provider">{{ trans('account_services.fees_preview_provider_label') }}</label><select id="fee-preview-provider" name="provider"><option value="">{{ trans('account_services.fees_preview_provider_none') }}</option>@foreach ($previewProviders as $providerKey => $providerLabel)<option value="{{ $providerKey }}">{{ $providerLabel }}</option>@endforeach</select></div></div><div><label for="fee-preview-amount">{{ trans('account_services.fees_preview_amount_label') }}</label><input id="fee-preview-amount" name="base_amount" type="text" inputmode="decimal" pattern="[0-9]{1,13}([.][0-9]{1,2})?" maxlength="16" required placeholder="100.00"></div><button class="next-button next-button--gold" type="submit">{{ trans('account_services.fees_preview_submit') }}</button></form><noscript><p class="next-muted">{{ trans('account_services.fees_preview_nojs') }}</p></noscript><div class="next-alert" data-fee-preview-result data-pending="{{ __('frontend_ui.pending') }}" data-network-error="{{ __('frontend_ui.network_error') }}" data-request-error="{{ __('frontend_ui.request_error') }}" data-unauthorized="{{ __('frontend_ui.unauthorized') }}" data-forbidden="{{ __('frontend_ui.forbidden') }}" data-expired="{{ __('frontend_ui.expired') }}" data-invalid="{{ __('frontend_ui.invalid') }}" data-rate-limited="{{ __('frontend_ui.rate_limited') }}" data-server-error="{{ __('frontend_ui.server_error') }}" data-malformed="{{ __('frontend_ui.malformed') }}" data-base="{{ __('frontend_ui.base') }}" data-rule-version="{{ __('frontend_ui.rule_version') }}" data-verification-state="{{ __('frontend_ui.verification_state') }}" data-verification-response="{{ __('frontend_ui.verification_response') }}" role="status" aria-live="polite" hidden></div></div></section>
            @forelse ($groups as $group)
                @php $groupId = 'fee-'.($group['key'] ?? $loop->iteration); @endphp
                <section class="next-section" id="{{ $groupId }}" data-content-section aria-labelledby="{{ $groupId }}-title"><div class="next-section__heading"><span class="next-section__number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><div><p class="next-eyebrow">{{ trans('fees_page.published_category') }}</p><h2 id="{{ $groupId }}-title">{{ $group['label'] ?? trans('fees_page.group') }}</h2></div></div>
                    {{-- Rendered through the shared component so the published
                         schedule carries its machine-readable contract: one
                         data-pp-fee per row, an explicit state, a scoped row
                         header and the NOT_CONFIGURED marker. --}}
                    <x-public.fee-table
                        :title="$group['label'] ?? trans('fees_page.group')"
                        :caption="$group['label'] ?? trans('fees_page.group')"
                        :rows="(array) ($group['rows'] ?? [])"
                        :show-provider="(bool) ($group['has_providers'] ?? false)"
                        col-category="{{ trans('fees_page.col_category') }}"
                        col-type="{{ trans('fees_page.col_type') }}"
                        col-amount="{{ trans('fees_page.col_amount') }}"
                        col-provider="{{ trans('fees_page.col_provider') }}"
                        col-description="{{ trans('fees_page.col_description') }}"
                        col-effective="{{ trans('fees_page.col_effective') }}"
                        not-configured="NOT_CONFIGURED"
                        :provider-none-label="trans('account_services.fees_provider_none')"
                    />
                </section>
            @empty
                <section class="next-panel" role="status"><h2>{{ trans('fees_page.empty_title') }}</h2><p>{{ trans('fees_page.empty_body') }}</p></section>
            @endforelse
            <section class="next-section" id="fee-methodology" data-content-section aria-labelledby="fee-methodology-title"><div class="next-section__heading"><span class="next-section__number">Σ</span><div><p class="next-eyebrow">{{ trans('fees_page.transparent') }}</p><h2 id="fee-methodology-title">{{ trans('fees_page.how_to_read') }}</h2></div></div><div class="next-section__body"><p>{{ trans('fees_page.method_body') }}</p><p class="next-muted">{{ trans('fees_page.print_note') }}</p></div></section>
        </div></div>
        <section class="next-bottom"><div><p class="next-eyebrow">{{ trans('fees_page.bottom_eyebrow') }}</p><h2>{{ trans('fees_page.bottom_title') }}</h2><p>{{ trans('fees_page.bottom_body') }}</p></div><div class="next-bottom__links"><button class="next-button next-button--gold" type="button" data-print-page>{{ trans('fees_page.print_save') }}</button><a class="next-button" href="{{ route('contact') }}">{{ trans('fees_page.contact_support') }}</a></div></section>
    </div>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/fees.js')
@endpush
