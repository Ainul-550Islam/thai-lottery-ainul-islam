@props([
    'result' => [],
    'isThai' => false,
    'heading' => null,
    'showLink' => true,
])

{{--
    One draw's numbers, in full (PROMPT 5, file 21).

    LEADING ZEROS REACH THE SCREEN INTACT. Every value printed below arrives
    as a STRING that the model padded to its documented width. This template
    does not call number_format, does not cast, and does not concatenate a
    value into arithmetic. '004615' prints as 004615.

    3FRONT AND 3AFTER ARE LISTS, NOT A STRING. Each value is rendered as its
    own element with its own position, in the order the source delivered. The
    template never joins them with a separator into an opaque blob, because a
    blob cannot be read back as individual numbers and invites a reader to
    treat a display separator as part of the data.

    NO BUSINESS LOGIC. No date maths (the projection carries both calendars
    already), no source reasoning (the badge component owns that), no prize
    calculation of any kind. This file arranges values.
--}}

@php
    $available = (bool) ($result['available'] ?? false);
    $status = (string) ($result['status'] ?? 'UNAVAILABLE');
    $draw = (array) ($result['draw'] ?? []);
    $date = (array) ($draw['date'] ?? []);
    $numbers = (array) ($result['numbers'] ?? []);
    $provenance = (array) ($result['provenance'] ?? []);

    $displayDate = $isThai
        ? (string) ($date['display_th'] ?? '')
        : (string) ($date['display_en'] ?? '');

    $reference = (string) ($draw['reference'] ?? '');

    $scalars = [
        'first_prize' => $numbers['first_prize'] ?? null,
        'three_up' => $numbers['three_up'] ?? null,
        'two_up' => $numbers['two_up'] ?? null,
        'two_down' => $numbers['two_down'] ?? null,
    ];

    $lists = [
        'three_front' => (array) ($numbers['three_front'] ?? []),
        'three_after' => (array) ($numbers['three_after'] ?? []),
    ];
@endphp

<article class="nl-card" data-nl-card="result" data-nl-status="{{ $status }}">
    @if ($heading !== null)
        <h2 class="nl-card__heading">{{ $heading }}</h2>
    @endif

    @unless ($available)
        <p class="nl-card__empty" role="status">{{ trans('national_lottery.status.'.strtolower($status)) }}</p>
    @else
        <header class="nl-card__header">
            <p class="nl-card__date">
                <span class="nl-card__date-main">{{ $displayDate }}</span>
                {{-- Both calendars are always available. The Gregorian/ISO
                     value is machine readable and is what a reader can match
                     against an internal record. --}}
                <time class="nl-card__date-iso" datetime="{{ $date['iso'] ?? '' }}">
                    {{ trans('national_lottery.date_gregorian_label') }}: {{ $date['iso'] ?? '' }}
                </time>
                <span class="nl-card__date-be">
                    {{ trans('national_lottery.date_buddhist_label') }}: {{ $date['buddhist_year'] ?? '' }}
                </span>
            </p>

            <x-national-lottery.source-status :provenance="$provenance" :compact="true" />
        </header>

        <dl class="nl-card__numbers">
            @foreach ($scalars as $field => $value)
                <div class="nl-card__number nl-card__number--{{ str_replace('_', '-', $field) }}">
                    <dt class="nl-card__number-label">{{ trans('national_lottery.field_'.$field) }}</dt>
                    <dd class="nl-card__number-value">
                        @if ($value === null || $value === '')
                            <span class="nl-card__number-missing">{{ trans('national_lottery.no_value') }}</span>
                        @else
                            {{-- String in, string out. --}}
                            <span class="nl-digits" data-nl-field="{{ $field }}">{{ $value }}</span>
                        @endif
                    </dd>
                </div>
            @endforeach

            @foreach ($lists as $field => $values)
                <div class="nl-card__number nl-card__number--{{ str_replace('_', '-', $field) }}">
                    <dt class="nl-card__number-label">{{ trans('national_lottery.field_'.$field) }}</dt>
                    <dd class="nl-card__number-value">
                        @if ($values === [])
                            <span class="nl-card__number-missing">{{ trans('national_lottery.no_value') }}</span>
                        @else
                            <ul class="nl-card__list">
                                @foreach ($values as $index => $value)
                                    <li class="nl-card__list-item">
                                        <span
                                            class="nl-digits"
                                            data-nl-field="{{ $field }}"
                                            data-nl-position="{{ $index + 1 }}"
                                        >{{ $value }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>

        @if ($showLink && $reference !== '')
            <p class="nl-card__actions">
                <a class="nl-card__link" href="{{ route('national-lottery.show', ['draw' => $reference]) }}">
                    {{ trans('national_lottery.view_detail') }}
                </a>
            </p>
        @endif
    @endunless
</article>
