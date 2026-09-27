@props([
    'provenance' => [],
    'integrity' => null,
    'compact' => false,
])

{{--
    Source, provenance and integrity badge (PROMPT 6, file 24).

    THE BADGE IS THE HONESTY OF THE PAGE. It is what separates "a number this
    platform received from a verified official feed" from "a number a developer
    seeded on a laptop". Both render the same digits; only this component tells
    them apart, so it is never omitted from a surface that shows numbers.

    STATUS IS NEVER COLOUR-ONLY. Each badge carries a text label from the
    translation files as well as a coloured dot, so it is readable without
    colour perception and survives a monochrome print.

    THE STATE IS NOT DECIDED HERE. AbstractLotterySourceService clamped it to
    the closed public vocabulary before it reached this file. This template
    maps a state string to a translation key and a CSS modifier - it contains
    no branch that could promote FIXTURE_ONLY to anything else.

    NOTHING SENSITIVE IS RENDERED. The array arrives whitelisted through the
    ProvidesResultProvenance interface: a host, never a URL; a fingerprint,
    never a payload; no token, no user id, no internal database id. Everything
    goes through Blade's escaping and this lane uses no unescaped raw-echo tag.
--}}

@php
    $available = (bool) ($provenance['available'] ?? false);
    $state = (string) ($provenance['source_state'] ?? 'UNAVAILABLE');
    $stateKey = strtolower($state);
    $isFixture = (bool) ($provenance['is_fixture'] ?? false);
    $isOfficial = (bool) ($provenance['is_official'] ?? false);

    $modifier = match (true) {
        $isOfficial => 'official',
        $isFixture => 'fixture',
        $state === 'INTERNAL_RECONCILED' => 'internal',
        default => 'unknown',
    };

    $integrity = is_array($integrity) ? $integrity : null;
    $integrityKey = $integrity !== null ? strtolower((string) ($integrity['status'] ?? 'not_verified')) : null;

    $rows = [
        'provider' => $provenance['provider'] ?? null,
        'source_identifier' => $provenance['source_identifier'] ?? null,
        'source_host' => $provenance['source_host'] ?? null,
        'result_version' => $provenance['result_version'] ?? null,
        'supersedes' => $provenance['supersedes_version'] ?? null,
        'parser_version' => $provenance['parser_version'] ?? null,
        'retrieved_at' => $provenance['retrieved_at'] ?? null,
        'imported_at' => $provenance['imported_at'] ?? null,
        'payload_fingerprint' => $provenance['payload_fingerprint'] ?? null,
        'normalized_fingerprint' => $provenance['normalized_fingerprint'] ?? null,
    ];
@endphp

<div class="wl-source wl-source--{{ $modifier }}" data-wl-source-state="{{ $state }}">
    <span class="wl-source__badge" role="status">
        <span class="wl-source__dot" aria-hidden="true"></span>
        <span class="wl-source__label">{{ trans('weekly_lottery.source_state.'.$stateKey) }}</span>
    </span>

    @unless ($compact)
        <p class="wl-source__hint">{{ trans('weekly_lottery.source_state_hint.'.$stateKey) }}</p>

        @if ($available)
            <dl class="wl-source__facts">
                @foreach ($rows as $key => $value)
                    <div class="wl-source__fact">
                        <dt class="wl-source__term">{{ trans('weekly_lottery.provenance.'.$key) }}</dt>
                        <dd class="wl-source__value">
                            @if ($value === null || $value === '')
                                {{ trans('weekly_lottery.provenance.none') }}
                            @else
                                <span @class(['wl-source__hash' => str_ends_with($key, 'fingerprint')])>{{ $value }}</span>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>

            <p class="wl-source__explainer">{{ trans('weekly_lottery.provenance.explainer') }}</p>

            @if ($integrityKey !== null)
                {{-- The integrity STATUS is public on purpose: it is the
                     honest qualifier on the fingerprint. A reader must be able
                     to tell "these bytes are self-consistent" from "an
                     authorised key signed them". --}}
                <dl class="wl-source__facts wl-source__facts--integrity">
                    <div class="wl-source__fact">
                        <dt class="wl-source__term">{{ trans('weekly_lottery.integrity.status') }}</dt>
                        <dd class="wl-source__value">{{ trans('weekly_lottery.integrity_status.'.$integrityKey) }}</dd>
                    </div>
                    <div class="wl-source__fact">
                        <dt class="wl-source__term">{{ trans('weekly_lottery.integrity.canonical_version') }}</dt>
                        <dd class="wl-source__value">
                            {{ $integrity['canonical_version'] ?? trans('weekly_lottery.provenance.none') }}
                        </dd>
                    </div>
                    <div class="wl-source__fact">
                        <dt class="wl-source__term">{{ trans('weekly_lottery.integrity.independently_verified') }}</dt>
                        <dd class="wl-source__value">
                            {{ ($integrity['independently_verified'] ?? false)
                                ? trans('weekly_lottery.integrity.yes')
                                : trans('weekly_lottery.integrity.no') }}
                        </dd>
                    </div>
                </dl>

                <p class="wl-source__explainer">{{ trans('weekly_lottery.integrity_hint.'.$integrityKey) }}</p>
                <p class="wl-source__explainer">{{ trans('weekly_lottery.integrity.explainer') }}</p>
            @endif
        @endif
    @endunless
</div>
