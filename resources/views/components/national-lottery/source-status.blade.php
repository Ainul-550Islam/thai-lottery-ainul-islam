@props([
    'provenance' => [],
    'compact' => false,
])

{{--
    Source and provenance badge (PROMPT 5, file 24).

    THE BADGE IS THE HONESTY OF THE PAGE. It is what separates "a number this
    platform received from a verified official feed" from "a number a
    developer seeded on a laptop". Both render the same digits; only this
    component tells them apart, so it is never omitted from a surface that
    shows numbers.

    THE STATE IS NOT DECIDED HERE. NationalLotterySourceService clamped it to
    the closed public vocabulary before it reached this file. This template
    only maps a state string to a translation key and a CSS modifier - it
    contains no branch that could promote FIXTURE_ONLY to anything else.

    NOTHING SENSITIVE IS RENDERED. The array arrives already whitelisted: a
    host, never a URL; a fingerprint, never a payload; no token, no user id,
    no internal database id. Everything below goes through Blade's escaping;
    no unescaped raw-echo tag exists anywhere in this lane.
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

<div class="nl-source nl-source--{{ $modifier }}" data-nl-source-state="{{ $state }}">
    <span class="nl-source__badge" role="status">
        <span class="nl-source__dot" aria-hidden="true"></span>
        <span class="nl-source__label">{{ trans('national_lottery.source_state.'.$stateKey) }}</span>
    </span>

    @unless ($compact)
        <p class="nl-source__hint">{{ trans('national_lottery.source_state_hint.'.$stateKey) }}</p>

        @if ($available)
            <dl class="nl-source__facts">
                @foreach ($rows as $key => $value)
                    <div class="nl-source__fact">
                        <dt class="nl-source__term">{{ trans('national_lottery.provenance.'.$key) }}</dt>
                        <dd class="nl-source__value">
                            @if ($value === null || $value === '')
                                {{ trans('national_lottery.provenance.none') }}
                            @else
                                <span @class(['nl-source__hash' => str_ends_with($key, 'fingerprint')])>{{ $value }}</span>
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>

            <p class="nl-source__explainer">{{ trans('national_lottery.provenance.explainer') }}</p>
        @endif
    @endunless
</div>
