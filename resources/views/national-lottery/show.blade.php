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
    @vite(['resources/css/national-lottery.css'])
@endpush

@section('content')
    {{--
        One draw in detail (PROMPT 5, file 19).

        THIS IS THE PAGE THAT HAS TO BE ABLE TO SAY "THIS WAS CORRECTED".
        The full provenance block is rendered here, not the compact badge: the
        provider, the source state, both fingerprints, the parser version, the
        retrieval and import times, the result version, and - when one exists
        - the version this record replaced. A visitor who wants to know
        whether a number changed can read the answer instead of inferring it.

        WHAT IS STILL NOT SHOWN: no internal database id, no importer's
        identity, no endpoint URL, no token, no payload. The projection was
        whitelisted field by field before it reached this template.

        NO UNESCAPED RAW ECHO AND NO ARITHMETIC. Every value goes through
        Blade's escaping, and both calendars arrive pre-computed from
        NationalLotteryDateService.
    --}}

    @php
        $projection = is_array($current ?? null) ? $current : [];
        $available = (bool) ($projection['available'] ?? false);
        $status = (string) ($projection['status'] ?? 'UNAVAILABLE');
        $provenance = (array) ($projection['provenance'] ?? []);
        $draw = (array) ($projection['draw'] ?? []);
        $date = (array) ($draw['date'] ?? []);
        $version = $provenance['result_version'] ?? null;
        $supersedes = $provenance['supersedes_version'] ?? null;
    @endphp

    <div class="nl-page nl-page--detail" data-nl-page="national-lottery-detail" data-nl-locale="{{ $meta['lang'] }}">
        <a class="nl-skip-link" href="#nl-main">{{ trans('national_lottery.skip_to_content') }}</a>

        <main id="nl-main" class="nl-main" tabindex="-1">
            <nav class="nl-breadcrumb" aria-label="{{ trans('national_lottery.heading') }}">
                <a class="nl-breadcrumb__link" href="{{ $routes['index'] }}">
                    {{ trans('national_lottery.heading') }}
                </a>
            </nav>

            <header class="nl-header">
                <h1 class="nl-title">{{ trans('national_lottery.detail_heading') }}</h1>
                <p class="nl-disclaimer">{{ trans('national_lottery.not_official_notice') }}</p>
            </header>

            @unless ($available)
                <section class="nl-section nl-section--empty">
                    <p class="nl-empty" role="status">
                        {{ trans('national_lottery.status.'.strtolower($status)) }}
                    </p>
                    <p class="nl-empty__action">
                        <a class="nl-link" href="{{ $routes['index'] }}">{{ trans('national_lottery.heading') }}</a>
                    </p>
                </section>
            @else
                @if ($supersedes !== null)
                    {{-- A correction is announced, never silent. The earlier
                         version is retained in the record. --}}
                    <p class="nl-notice nl-notice--correction" role="status">
                        {{ trans('national_lottery.correction_notice', ['version' => $version]) }}
                    </p>
                @endif

                <section class="nl-section nl-section--result" aria-labelledby="nl-detail-numbers">
                    <h2 class="nl-section__heading" id="nl-detail-numbers">
                        {{ trans('national_lottery.current_result_heading') }}
                    </h2>

                    <x-national-lottery.result-card
                        :result="$projection"
                        :is-thai="$is_thai"
                        :show-link="false"
                    />

                    <dl class="nl-detail__dates">
                        <div class="nl-detail__date">
                            <dt>{{ trans('national_lottery.date_gregorian_label') }}</dt>
                            <dd><time datetime="{{ $date['iso'] ?? '' }}">{{ $date['iso'] ?? '' }}</time></dd>
                        </div>
                        <div class="nl-detail__date">
                            <dt>{{ trans('national_lottery.date_buddhist_label') }}</dt>
                            <dd>{{ $date['buddhist_year'] ?? '' }}</dd>
                        </div>
                        @if (($draw['published_at'] ?? null) !== null)
                            <div class="nl-detail__date">
                                <dt>{{ trans('national_lottery.published_at_label') }}</dt>
                                <dd><time datetime="{{ $draw['published_at'] }}">{{ $draw['published_at'] }}</time></dd>
                            </div>
                        @endif
                    </dl>
                </section>

                <section class="nl-section nl-section--provenance" aria-labelledby="nl-detail-provenance">
                    <h2 class="nl-section__heading" id="nl-detail-provenance">
                        {{ trans('national_lottery.provenance_heading') }}
                    </h2>

                    <x-national-lottery.source-status :provenance="$provenance" />
                </section>
            @endunless

            <x-national-lottery.year-nav
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
    @vite(['resources/js/national-lottery.js'])
@endpush
