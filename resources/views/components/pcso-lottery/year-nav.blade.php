@props([
    'years' => [],
    'activeYear' => null,
    'isThai' => false,
])

{{--
    Previous-year navigation (PROMPT 9, file 22).

    EVERY LINK HERE IS BACKED BY DATA. The list arrives from
    PcsoLotteryHistoryService::availableYears(), which is a SELECT DISTINCT
    over publicly visible draws. There is no hard-coded 2568, no 2567, and no
    generated range. A year with no published draw is not rendered, so this
    navigation can never send a visitor to an empty page that reads as missing
    data.

    BOTH CALENDARS. The label is Buddhist Era for a Thai reader and Gregorian
    otherwise - and both were computed by the shared calendar service. This
    template performs no calendar arithmetic of its own; the Buddhist-era
    offset literal appears nowhere in it.

    When the list is empty the component says so plainly rather than rendering
    an empty strip that reads as a broken widget.
--}}

@php
    $years = is_array($years) ? $years : [];
    $activeYear = is_int($activeYear) ? $activeYear : null;
@endphp

<nav class="wl-years" aria-label="{{ trans('pcso_lottery.year_nav_heading') }}" data-wl-years>
    <h2 class="wl-years__heading">{{ trans('pcso_lottery.year_nav_heading') }}</h2>

    @if ($years === [])
        <p class="wl-years__empty" role="status">{{ trans('pcso_lottery.year_nav_empty') }}</p>
    @else
        <ul class="wl-years__list">
            @foreach ($years as $year)
                @php
                    $gregorian = (int) ($year['gregorian'] ?? 0);
                    $label = $isThai
                        ? (string) ($year['label_th'] ?? '')
                        : (string) ($year['label_en'] ?? '');
                    $isActive = $activeYear !== null && $activeYear === $gregorian;
                @endphp

                @if ($gregorian > 0)
                    <li class="wl-years__item">
                        <a
                            class="wl-years__link @if ($isActive) wl-years__link--active @endif"
                            href="{{ route('pcso-lottery.year', ['year' => $gregorian]) }}"
                            @if ($isActive) aria-current="page" @endif
                        >
                            <span class="wl-years__label">{{ $label }}</span>
                            <span class="wl-years__count">
                                {{ trans('pcso_lottery.year_draw_count', ['count' => (int) ($year['draws'] ?? 0)]) }}
                            </span>
                            {{-- The other calendar, always available, never
                                 computed in this file. --}}
                            <span class="wl-years__alt">
                                {{ $isThai ? ($year['label_en'] ?? '') : ($year['label_th'] ?? '') }}
                            </span>
                        </a>
                    </li>
                @endif
            @endforeach
        </ul>
    @endif
</nav>
