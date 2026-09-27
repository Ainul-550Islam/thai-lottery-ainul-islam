@props([
    'result' => [],
    'isThai' => false,
    'heading' => null,
    'showLink' => true,
])

{{--
    One draw's numbers, in full (PROMPT 8, file 21).

    LEADING ZEROS REACH THE SCREEN INTACT. Every value printed below arrives as
    a STRING that the model padded to its documented width. This template does
    not call number_format, does not cast, and does not concatenate a value
    into arithmetic. '049' prints as 049 and '09' prints as 09.

    A MISSING RESULT IS RENDERED AS MISSING. When the draw published nothing,
    each field shows the translated "not published" wording and the card
    carries an explicit badge saying so. There is no branch in this file that
    can substitute a zero for an absent value - the value is null and null
    renders as words, not digits.

    NO BUSINESS LOGIC. No date maths (the projection carries both calendars
    already), no source reasoning (the badge component owns that), no prize
    calculation of any kind - this product has no prize model. This file
    arranges values.
--}}

@php
    $available = (bool) ($result['available'] ?? false);
    $hasNumbers = (bool) ($result['has_numbers'] ?? false);
    $status = (string) ($result['status'] ?? 'UNAVAILABLE');
    $draw = (array) ($result['draw'] ?? []);
    $date = (array) ($draw['date'] ?? []);
    $numbers = (array) ($result['numbers'] ?? []);
    $provenance = (array) ($result['provenance'] ?? []);
    $integrity = $result['integrity'] ?? null;

    $displayDate = $isThai
        ? (string) ($date['display_th'] ?? '')
        : (string) ($date['display_en'] ?? '');

    $reference = (string) ($draw['reference'] ?? '');

    $fields = [
        'first_6_mega' => $numbers['first_6_mega'] ?? null,
        'three_mega' => $numbers['three_mega'] ?? null,
        'two_mega' => $numbers['two_mega'] ?? null,
    ];
@endphp

<article class="wl-card" data-wl-card="result" data-wl-status="{{ $status }}">
    @if ($heading !== null)
        <h2 class="wl-card__heading">{{ $heading }}</h2>
    @endif

    @unless ($available)
        <p class="wl-card__empty" role="status">{{ trans('bingo_lottery.status.'.strtolower($status)) }}</p>
    @else
        <header class="wl-card__header">
            <p class="wl-card__date">
                <span class="wl-card__date-main">{{ $displayDate }}</span>
                {{-- Both calendars are always available. The Gregorian/ISO
                     value is machine readable and is what a reader can match
                     against an internal record. --}}
                <time class="wl-card__date-iso" datetime="{{ $date['iso'] ?? '' }}">
                    {{ trans('bingo_lottery.date_gregorian_label') }}: {{ $date['iso'] ?? '' }}
                </time>
                <span class="wl-card__date-be">
                    {{ trans('bingo_lottery.date_buddhist_label') }}: {{ $date['buddhist_year'] ?? '' }}
                </span>
            </p>

            <x-bingo-lottery.source-status :provenance="$provenance" :compact="true" />
        </header>

        @unless ($hasNumbers)
            <p class="wl-card__unavailable" role="status">
                <span class="wl-card__unavailable-badge">{{ trans('bingo_lottery.result_unavailable_badge') }}</span>
                <span class="wl-card__unavailable-text">{{ trans('bingo_lottery.result_unavailable_explainer') }}</span>
            </p>
        @endunless

        <dl class="wl-card__numbers">
            @foreach ($fields as $field => $value)
                <div class="wl-card__number wl-card__number--{{ str_replace('_', '-', $field) }}">
                    <dt class="wl-card__number-label">{{ trans('bingo_lottery.field_'.$field) }}</dt>
                    <dd class="wl-card__number-value">
                        @if ($value === null || $value === '')
                            <span class="wl-card__number-missing">{{ trans('bingo_lottery.no_value') }}</span>
                        @else
                            {{-- String in, string out. --}}
                            <span class="wl-digits" data-wl-field="{{ $field }}">{{ $value }}</span>
                        @endif
                    </dd>
                </div>
            @endforeach
        </dl>

        @if ($showLink && $reference !== '')
            <p class="wl-card__actions">
                <a class="wl-card__link" href="{{ route('bingo-lottery.show', ['draw' => $reference]) }}">
                    {{ trans('bingo_lottery.view_detail') }}
                </a>
            </p>
        @endif
    @endunless
</article>
