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
    {{--
        One draw in detail (PROMPT 9, file 19).

        THIS IS THE PAGE THAT HAS TO BE ABLE TO SAY "THIS WAS CORRECTED".
        The full provenance block is rendered here, not the compact badge: the
        provider, the source state, both fingerprints, the parser version, the
        retrieval and import times, the result version, the integrity status,
        and - when one exists - the version this record replaced. A visitor who
        wants to know whether a number changed can read the answer instead of
        inferring it.

        WHAT IS STILL NOT SHOWN: no internal database id, no importer's
        identity, no endpoint URL, no token, no payload. The projection was
        whitelisted field by field through the ProvidesResultProvenance
        interface before it reached this template.

        NO UNESCAPED RAW ECHO AND NO ARITHMETIC. Every value goes through
        Blade's escaping, and both calendars arrive pre-computed from the
        shared calendar service.
    --}}

    @php
        $projection = is_array($current ?? null) ? $current : [];
        $available = (bool) ($projection['available'] ?? false);
        $status = (string) ($projection['status'] ?? 'UNAVAILABLE');
        $provenance = (array) ($projection['provenance'] ?? []);
        $integrity = $projection['integrity'] ?? null;
        $draw = (array) ($projection['draw'] ?? []);
        $date = (array) ($draw['date'] ?? []);
        $version = $provenance['result_version'] ?? null;
        $supersedes = $provenance['supersedes_version'] ?? null;
    @endphp

    <div class="wl-page wl-page--detail" data-wl-page="pcso-lottery-detail" data-wl-locale="{{ $meta['lang'] }}">
        <a class="wl-skip-link" href="#wl-main">{{ trans('pcso_lottery.skip_to_content') }}</a>

        <main id="wl-main" class="wl-main" tabindex="-1">
            <nav class="wl-breadcrumb" aria-label="{{ trans('pcso_lottery.heading') }}">
                <a class="wl-breadcrumb__link" href="{{ $routes['index'] }}">
                    {{ trans('pcso_lottery.heading') }}
                </a>
            </nav>

            <header class="wl-header">
                <h1 class="wl-title">{{ trans('pcso_lottery.detail_heading') }}</h1>
                <p class="wl-disclaimer">{{ trans('pcso_lottery.not_official_notice') }}</p>
            </header>

            @unless ($available)
                <section class="wl-section wl-section--empty">
                    <p class="wl-empty" role="status">
                        {{ trans('pcso_lottery.status.'.strtolower($status)) }}
                    </p>
                    <p class="wl-empty__action">
                        <a class="wl-link" href="{{ $routes['index'] }}">{{ trans('pcso_lottery.heading') }}</a>
                    </p>
                </section>
            @else
                @if ($supersedes !== null)
                    {{-- A correction is announced, never silent. The earlier
                         version is retained in the record. --}}
                    <p class="wl-notice wl-notice--correction" role="status">
                        {{ trans('pcso_lottery.correction_notice', ['version' => $version]) }}
                    </p>
                @endif

                <section class="wl-section wl-section--result" aria-labelledby="wl-detail-numbers">
                    <h2 class="wl-section__heading" id="wl-detail-numbers">
                        {{ trans('pcso_lottery.current_result_heading') }}
                    </h2>

                    <x-pcso-lottery.result-card
                        :result="$projection"
                        :is-thai="$is_thai"
                        :show-link="false"
                    />

                    <dl class="wl-detail__dates">
                        <div class="wl-detail__date">
                            <dt>{{ trans('pcso_lottery.date_gregorian_label') }}</dt>
                            <dd><time datetime="{{ $date['iso'] ?? '' }}">{{ $date['iso'] ?? '' }}</time></dd>
                        </div>
                        <div class="wl-detail__date">
                            <dt>{{ trans('pcso_lottery.date_buddhist_label') }}</dt>
                            <dd>{{ $date['buddhist_year'] ?? '' }}</dd>
                        </div>
                        <div class="wl-detail__date">
                            <dt>{{ trans('pcso_lottery.date_timezone_label') }}</dt>
                            <dd>{{ $draw['timezone'] ?? '' }}</dd>
                        </div>
                        @if (($draw['published_at'] ?? null) !== null)
                            <div class="wl-detail__date">
                                <dt>{{ trans('pcso_lottery.published_at_label') }}</dt>
                                <dd><time datetime="{{ $draw['published_at'] }}">{{ $draw['published_at'] }}</time></dd>
                            </div>
                        @endif
                    </dl>
                </section>

                <section class="wl-section wl-section--provenance" aria-labelledby="wl-detail-provenance">
                    <h2 class="wl-section__heading" id="wl-detail-provenance">
                        {{ trans('pcso_lottery.provenance_heading') }}
                    </h2>

                    <x-pcso-lottery.source-status :provenance="$provenance" :integrity="$integrity" />
                </section>
            @endunless

            <x-pcso-lottery.year-nav
                :years="$years"
                :active-year="$active_year"
                :is-thai="$is_thai"
            />
        </main>
    </div>

        {{-- The shared public footer, the same one About, Vision, Terms, Fees,
             Prize Verification and Discounts already carry. Without it a
             visitor who arrived on a lane page from a search engine had no
             way out of it except the browser's back button. --}}
        <x-public-page.footer />

@endsection

@push('scripts')
    @vite(['resources/js/pcso-lottery.js'])
@endpush
