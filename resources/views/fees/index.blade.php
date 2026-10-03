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
    $previewProviders = [];
    foreach ($rows as $feeRow) {
        $feeKey = (string) ($feeRow['key'] ?? '');
        if ($feeKey !== '') {
            $previewCategories[$feeKey] = (string) ($feeRow['label'] ?? $feeKey);
        }
        $providerKey = (string) ($feeRow['provider'] ?? '');
        if ($providerKey !== '') {
            $previewProviders[$providerKey] = (string) ($feeRow['provider_label'] ?? $providerKey);
        }
    }
@endphp

@section('title', (string) ($meta['title'] ?? $fees['meta_title'] ?? $fees['title'] ?? 'Fees'))
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
    <header class="next-page-header"><div class="next-shell next-page-header__inner"><a class="next-brand" href="{{ route('home') }}" aria-label="Home"><span class="next-brand__mark">TL</span><span>{{ config('app.name') }}<small>PUBLISHED FEE MATRIX</small></span></a><nav class="next-nav" aria-label="Primary navigation"><a href="{{ route('home') }}">HOME</a><a href="{{ route('about') }}">ABOUT US</a><a href="{{ route('results.index') }}">RESULTS</a><a class="is-active" href="{{ route('fees') }}" aria-current="page">FEES</a><a href="{{ route('contact') }}">CONTACT</a></nav>@guest<a class="next-button next-button--gold" href="{{ route('login') }}">LOGIN</a>@else<a class="next-button next-button--gold" href="{{ route('player.dashboard') }}">DASHBOARD</a>@endguest</div></header>
    <main id="fees-main" class="next-shell next-content" tabindex="-1">
        <section class="next-hero" aria-labelledby="fees-title"><div><p class="next-eyebrow">06 · PUBLIC FEE MATRIX</p><h1 id="fees-title">{{ $fees['title'] ?? 'Fees' }}</h1><p>{{ $fees['meta_description'] ?? 'Published charges and calculation rules from the configured fee authority.' }}</p><p class="next-note">Rates, currency and effective rows are rendered from the canonical fee configuration. An unspecified rate remains NOT_CONFIGURED; it is never guessed.</p></div><div class="next-hero-object next-hero-object--fees" aria-hidden="true"><span>฿</span></div></section>
        <section class="next-meta-strip" aria-label="Fee schedule metadata"><div><span>{{ trans('account_services.fees_rule_version_label') }}</span><strong>{{ $fees['version'] ?? $fees['rule_version'] ?? 'NOT_CONFIGURED' }}</strong></div><div><span>CURRENCY</span><strong>{{ $fees['currency'] ?? 'NOT_CONFIGURED' }}</strong></div><div><span>PUBLIC ROWS</span><strong>{{ count($rows) }}</strong></div><div><span>CALCULATION</span><strong>SERVER-SIDE</strong></div></section>
        <div class="next-layout"><aside class="next-sidebar" aria-label="Fee sections"><p class="next-eyebrow">FEE SECTIONS</p>@foreach ($groups as $group)<a href="#fee-{{ $group['key'] ?? $loop->iteration }}" data-content-link="fee-{{ $group['key'] ?? $loop->iteration }}">{{ $group['label'] ?? 'Fee group' }}</a>@endforeach<a href="#fee-methodology" data-content-link="fee-methodology">Methodology</a></aside><div>
            <div class="next-search" role="search"><label for="fees-search">Search the fee schedule</label><div class="next-search__row"><input id="fees-search" type="search" data-local-search placeholder="Search a category or provider" autocomplete="off"><button class="next-button" type="button" data-clear-search>CLEAR</button></div><p class="next-search__status" data-search-status aria-live="polite">Showing all {{ count($rows) }} rows.</p></div>
            <section class="next-section" id="fee-preview" data-content-section aria-labelledby="fee-preview-title"><div class="next-section__heading"><span class="next-section__number">ƒ</span><div><p class="next-eyebrow">SERVER CALCULATION</p><h2 id="fee-preview-title">{{ trans('account_services.fees_preview_title') }}</h2></div></div><div class="next-section__body"><p>{{ trans('account_services.fees_preview_lead') }}</p><form class="next-form" method="post" action="{{ route('api.v1.fees.preview') }}" data-fee-preview-form><div class="next-form__grid"><div><label for="fee-preview-category">{{ trans('account_services.fees_preview_category_label') }}</label><select id="fee-preview-category" name="category" required>@forelse ($previewCategories as $categoryKey => $categoryLabel)<option value="{{ $categoryKey }}">{{ $categoryLabel }}</option>@empty<option value="">{{ trans('account_services.fees_not_configured') }}</option>@endforelse</select></div><div><label for="fee-preview-provider">{{ trans('account_services.fees_preview_provider_label') }}</label><select id="fee-preview-provider" name="provider"><option value="">{{ trans('account_services.fees_preview_provider_none') }}</option>@foreach ($previewProviders as $providerKey => $providerLabel)<option value="{{ $providerKey }}">{{ $providerLabel }}</option>@endforeach</select></div></div><div><label for="fee-preview-amount">{{ trans('account_services.fees_preview_amount_label') }}</label><input id="fee-preview-amount" name="base_amount" type="text" inputmode="decimal" pattern="[0-9]{1,13}([.][0-9]{1,2})?" maxlength="16" required placeholder="100.00"></div><button class="next-button next-button--gold" type="submit">{{ trans('account_services.fees_preview_submit') }}</button></form><noscript><p class="next-muted">{{ trans('account_services.fees_preview_nojs') }}</p></noscript><div class="next-alert" data-fee-preview-result role="status" aria-live="polite" hidden></div></div></section>
            @forelse ($groups as $group)
                @php $groupId = 'fee-'.($group['key'] ?? $loop->iteration); @endphp
                <section class="next-section" id="{{ $groupId }}" data-content-section aria-labelledby="{{ $groupId }}-title"><div class="next-section__heading"><span class="next-section__number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span><div><p class="next-eyebrow">PUBLISHED CATEGORY</p><h2 id="{{ $groupId }}-title">{{ $group['label'] ?? 'Fee group' }}</h2></div></div>
                    {{-- Rendered through the shared component so the published
                         schedule carries its machine-readable contract: one
                         data-pp-fee per row, an explicit state, a scoped row
                         header and the NOT_CONFIGURED marker. --}}
                    <x-public.fee-table
                        :title="$group['label'] ?? 'Fee group'"
                        :caption="$group['label'] ?? 'Fee group'"
                        :rows="(array) ($group['rows'] ?? [])"
                        :show-provider="(bool) ($group['has_providers'] ?? false)"
                        col-category="Category"
                        col-type="Calculation"
                        col-amount="Published charge"
                        col-provider="Provider"
                        col-description="Description"
                        col-effective="Effective"
                        not-configured="NOT_CONFIGURED"
                        :provider-none-label="trans('account_services.fees_provider_none')"
                    />
                </section>
            @empty
                <section class="next-panel" role="status"><h2>Fee schedule unavailable</h2><p>No public fee rows are currently configured.</p></section>
            @endforelse
            <section class="next-section" id="fee-methodology" data-content-section aria-labelledby="fee-methodology-title"><div class="next-section__heading"><span class="next-section__number">Σ</span><div><p class="next-eyebrow">TRANSPARENT CALCULATION</p><h2 id="fee-methodology-title">How to read this schedule</h2></div></div><div class="next-section__body"><p>Published rows are filtered by enabled, public-visible, non-internal and effective-date rules. Preview calculations are performed by the server against the same source; client-supplied rates are not accepted.</p><p class="next-muted">Use browser print or save to retain the visible schedule. A verified PDF export is not claimed unless configured.</p></div></section>
        </div></div>
        <section class="next-bottom"><div><p class="next-eyebrow">NEED A SPECIFIC ANSWER?</p><h2>Ask through the configured support route</h2><p>Include the fee category and relevant transaction context. Do not send payment credentials.</p></div><div class="next-bottom__links"><button class="next-button next-button--gold" type="button" data-print-page>PRINT / SAVE</button><a class="next-button" href="{{ route('contact') }}">CONTACT SUPPORT</a></div></section>
    </main>
</div>
@endsection

@push('scripts')
    @vite('resources/js/pages/fees.js')
@endpush
