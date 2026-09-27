@props([
    'years' => [],
    'activeYear' => null,
    'isThai' => false,
])

{{--
    Year navigation (PROMPT 5, file 22).

    EVERY LINK HERE IS BACKED BY DATA. The list arrives from
    NationalLotteryHistoryService::availableYears(), which is a
    SELECT DISTINCT over publicly visible draws. There is no hard-coded 2569,
    no hard-coded 2568, and no generated range. A year that has no published
    draw is not rendered, so this navigation can never send a visitor to an
    empty page that looks like missing data.

    BOTH CALENDARS. The label is Buddhist Era for a Thai reader and Gregorian
    otherwise - and both were computed by NationalLotteryDateService. This
    template performs no calendar arithmetic of its own; the Buddhist-era
    offset literal appears nowhere in it.

    When the list is empty the component says so plainly rather than rendering
    an empty strip that reads as a broken widget.
--}}

@php
    $years = is_array($years) ? $years : [];
    $activeYear = is_int($activeYear) ? $activeYear : null;
@endphp

<nav class="nl-years" aria-label="{{ trans('national_lottery.year_nav_heading') }}" data-nl-years>
    <h2 class="nl-years__heading">{{ trans('national_lottery.year_nav_heading') }}</h2>

    @if ($years === [])
        <p class="nl-years__empty" role="status">{{ trans('national_lottery.year_nav_empty') }}</p>
    @else
        <ul class="nl-years__list">
            @foreach ($years as $year)
                @php
                    $gregorian = (int) ($year['gregorian'] ?? 0);
                    $label = $isThai
                        ? (string) ($year['label_th'] ?? '')
                        : (string) ($year['label_en'] ?? '');
                    $isActive = $activeYear !== null && $activeYear === $gregorian;
                @endphp

                @if ($gregorian > 0)
                    <li class="nl-years__item">
                        <a
                            class="nl-years__link @if ($isActive) nl-years__link--active @endif"
                            href="{{ route('national-lottery.year', ['year' => $gregorian]) }}"
                            @if ($isActive) aria-current="page" @endif
                        >
                            <span class="nl-years__label">{{ $label }}</span>
                            <span class="nl-years__count">
                                {{ trans('national_lottery.year_draw_count', ['count' => (int) ($year['draws'] ?? 0)]) }}
                            </span>
                            {{-- The other calendar, always available, never
                                 computed in this file. --}}
                            <span class="nl-years__alt">
                                {{ $isThai ? ($year['label_en'] ?? '') : ($year['label_th'] ?? '') }}
                            </span>
                        </a>
                    </li>
                @endif
            @endforeach
        </ul>
    @endif
</nav>
