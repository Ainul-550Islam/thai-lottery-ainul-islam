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
        Public Prize Verification page (PROMPT 4).

        Anonymous. The form POSTs to the same URL behind
        throttle:ticket-verification. Every rendered value is escaped by Blade;
        the only values that reach this template are the allow-listed keys the
        verification service publishes.
    --}}
    <div class="pd-page pd-page--verify" data-pd-page="prize-verification" data-pd-policy="{{ $policy_version }}">
        <a class="pp-skip-link" href="#pd-main">{{ trans('public_pages.skip_to_content') }}</a>

        <main id="pd-main" class="pd-main" tabindex="-1">
            <header class="pd-header">
                <p class="pd-eyebrow">GLO-compatible</p>
                <h1 class="pd-title">{{ trans('prize_discount.verify_heading') }}</h1>
                <p class="pd-lead">{{ trans('prize_discount.verify_intro') }}</p>
                <p class="pd-disclaimer pd-disclaimer--strong">{{ trans('prize_discount.not_official_notice') }}</p>
            </header>

            <section class="pd-card pd-card--form">
                <form
                    method="POST"
                    action="{{ route('prize-verification.submit') }}"
                    class="pd-form"
                    data-pd-form="verification"
                    data-pd-number-max="{{ $limits['number_max'] }}"
                    data-pd-reference-max="{{ $limits['reference_max'] }}"
                    data-pd-barcode-max="{{ $limits['barcode_max'] }}"
                >
                    @csrf

                    <fieldset class="pd-fieldset">
                        <legend>{{ trans('prize_discount.verify_mode_label') }}</legend>

                        <div class="pd-modes">
                            @foreach ($modes as $availableMode)
                                <label class="pd-mode">
                                    <input
                                        type="radio"
                                        name="mode"
                                        value="{{ $availableMode }}"
                                        data-pd-mode-option
                                        @checked($availableMode === $mode)
                                    >
                                    <span>{{ trans('prize_discount.mode_'.$availableMode) }}</span>
                                </label>
                            @endforeach
                        </div>

                        @foreach ($modes as $availableMode)
                            <p class="pd-hint" data-pd-hint="{{ $availableMode }}" @if ($availableMode !== $mode) hidden @endif>
                                {{ trans('prize_discount.mode_'.$availableMode.'_hint') }}
                            </p>
                        @endforeach
                    </fieldset>

                    <div class="pd-field">
                        <label for="pd-value">{{ trans('prize_discount.verify_value_label') }}</label>
                        <input
                            id="pd-value"
                            name="value"
                            type="text"
                            inputmode="text"
                            autocomplete="off"
                            spellcheck="false"
                            required
                            maxlength="{{ max($limits['number_max'], $limits['reference_max'], $limits['barcode_max']) }}"
                            value="{{ old('value') }}"
                            data-pd-value
                        >
                        @error('value')
                            <p class="pd-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="pd-field">
                        <label for="pd-draw">{{ trans('prize_discount.verify_draw_label') }}</label>
                        <input
                            id="pd-draw"
                            name="draw"
                            type="text"
                            autocomplete="off"
                            spellcheck="false"
                            maxlength="64"
                            value="{{ old('draw') }}"
                        >
                        <p class="pd-hint">{{ trans('prize_discount.verify_draw_hint') }}</p>
                        @error('draw')
                            <p class="pd-error">{{ $message }}</p>
                        @enderror
                    </div>

                    @error('mode')
                        <p class="pd-error">{{ $message }}</p>
                    @enderror

                    <div class="pd-actions">
                        <button type="submit" class="pd-button pd-button--primary">
                            {{ trans('prize_discount.verify_submit') }}
                        </button>
                        <button type="reset" class="pd-button">
                            {{ trans('prize_discount.verify_reset') }}
                        </button>
                    </div>

                    <p class="pd-muted">{{ trans('prize_discount.verify_rate_note') }}</p>
                </form>
            </section>

            @if ($result !== null)
                <x-public.verification-result :result="$result" />
            @else
                <section class="pd-card pd-card--empty">
                    <p class="pd-muted">{{ trans('prize_discount.result_none') }}</p>
                </section>
            @endif

            <section class="pd-card pd-card--providers">
                <h2>{{ trans('prize_discount.barcode_heading') }}</h2>
                @php
                    $providerLabelMap = [
                        'SUPPORTED' => 'prize_discount.barcode_supported',
                        'NOT_CONFIGURED' => 'prize_discount.barcode_not_configured',
                        'UNSUPPORTED_FORMAT' => 'prize_discount.barcode_unsupported_format',
                        'INVALID' => 'prize_discount.barcode_invalid',
                    ];
                @endphp
                <ul class="pd-providers">
                    <li data-pd-provider="glo_data_matrix" data-pd-provider-state="{{ $providers['glo_data_matrix'] }}">
                        <span>{{ trans('prize_discount.barcode_provider_glo_data_matrix') }}</span>
                        <span class="pd-badge">{{ trans($providerLabelMap[$providers['glo_data_matrix']] ?? 'prize_discount.not_configured') }}</span>
                    </li>
                    <li data-pd-provider="operator_qr" data-pd-provider-state="{{ $providers['operator_qr'] }}">
                        <span>{{ trans('prize_discount.barcode_provider_operator_qr') }}</span>
                        <span class="pd-badge">{{ trans($providerLabelMap[$providers['operator_qr']] ?? 'prize_discount.not_configured') }}</span>
                    </li>
                </ul>
                <p class="pd-muted">
                    {{ trans('prize_discount.barcode_fixture_schema') }}:
                    <span class="pd-mono">{{ $providers['fixture_schema'] }}</span>
                </p>
                <p class="pd-disclaimer">{{ trans('prize_discount.barcode_fixture_notice') }}</p>
            </section>

            <section class="pd-card pd-card--physical" data-pd-informational="{{ $guidance['informational_only'] ? 'true' : 'false' }}">
                <h2>{{ trans('prize_discount.physical_note_heading') }}</h2>
                <ul class="pd-notes">
                    @foreach ($guidance['keys'] as $noteKey)
                        <li>{{ trans($noteKey) }}</li>
                    @endforeach
                </ul>
            </section>

            <p class="pd-disclaimer">{{ trans('prize_discount.privacy_notice') }}</p>
        </main>

        <x-public-page.footer />
    </div>
@endsection

@push('scripts')
    @vite(['resources/js/prize-verification.js'])
@endpush
