@props([
    'rows' => [],
    'isThai' => false,
    'caption' => null,
    'pagination' => null,
    'paginationRoute' => null,
    'paginationParams' => [],
])

{{--
    Accessible, responsive history table (PROMPT 8, file 20).

    ACCESSIBILITY IS STRUCTURAL, NOT DECORATIVE
    - a real <caption> names the table for a screen reader landing on it;
    - every column <th> carries scope="col";
    - the date cell of each body row is a <th scope="row">, so a row reads as
      "18 September 2026, 6 Mega 001234" instead of a bare number salad;
    - each numeric cell carries data-label, which the stylesheet uses in the
      stacked mobile layout so a cell never appears without its heading;
    - the pagination is a <nav> with an aria-label and rel=prev/next;
    - the source column carries a text status, never colour alone.

    RESPONSIVE WITHOUT LOSING DATA. Below the breakpoint each row becomes a
    block and the data-label attributes keep every value paired with its field
    name. 6 Mega, 3 Mega and 2 Mega are NEVER display:none - a column hidden on a
    phone is a missing result, not a tidier layout. Verified at 320, 375, 768,
    1024 and 1440.

    A DRAW WITH NO NUMBERS IS STILL A ROW. Its three cells read "not
    published". Hiding the row would misrepresent the record: the draw
    happened, and the honest history shows the gap.
--}}

@php
    $caption = $caption ?? trans('bingo_lottery.table_caption');
    $pagination = is_array($pagination) ? $pagination : null;
    $columns = ['first_6_mega' => 'col_first_6_mega', 'three_mega' => 'col_three_mega', 'two_mega' => 'col_two_mega'];
@endphp

<div class="wl-table-wrap">
    <table class="wl-table" data-wl-table="results">
        <caption class="wl-table__caption">{{ $caption }}</caption>

        <thead class="wl-table__head">
            <tr>
                <th scope="col" class="wl-table__th wl-table__th--date">{{ trans('bingo_lottery.col_draw_date') }}</th>
                <th scope="col" class="wl-table__th">{{ trans('bingo_lottery.col_first_6_mega') }}</th>
                <th scope="col" class="wl-table__th">{{ trans('bingo_lottery.col_three_mega') }}</th>
                <th scope="col" class="wl-table__th">{{ trans('bingo_lottery.col_two_mega') }}</th>
                <th scope="col" class="wl-table__th">{{ trans('bingo_lottery.col_source') }}</th>
                <th scope="col" class="wl-table__th">{{ trans('bingo_lottery.col_detail') }}</th>
            </tr>
        </thead>

        <tbody class="wl-table__body">
            @forelse ($rows as $row)
                @php
                    $draw = (array) ($row['draw'] ?? []);
                    $date = (array) ($draw['date'] ?? []);
                    $numbers = (array) ($row['numbers'] ?? []);
                    $provenance = (array) ($row['provenance'] ?? []);
                    $reference = (string) ($draw['reference'] ?? '');
                    $hasNumbers = (bool) ($row['has_numbers'] ?? false);
                    $displayDate = $isThai
                        ? (string) ($date['display_th'] ?? '')
                        : (string) ($date['display_en'] ?? '');
                @endphp

                <tr @class(['wl-table__row', 'wl-table__row--unavailable' => ! $hasNumbers])>
                    <th scope="row" class="wl-table__cell wl-table__cell--date" data-label="{{ trans('bingo_lottery.col_draw_date') }}">
                        <time datetime="{{ $date['iso'] ?? '' }}">{{ $displayDate }}</time>
                        <span class="wl-table__date-alt">{{ $date['iso'] ?? '' }}</span>
                    </th>

                    @foreach ($columns as $field => $labelKey)
                        <td class="wl-table__cell" data-label="{{ trans('bingo_lottery.'.$labelKey) }}">
                            @if (($numbers[$field] ?? null) === null || ($numbers[$field] ?? '') === '')
                                <span class="wl-table__missing">{{ trans('bingo_lottery.no_value') }}</span>
                            @else
                                <span class="wl-digits" data-wl-field="{{ $field }}">{{ $numbers[$field] }}</span>
                            @endif
                        </td>
                    @endforeach

                    <td class="wl-table__cell wl-table__cell--source" data-label="{{ trans('bingo_lottery.col_source') }}">
                        <x-bingo-lottery.source-status :provenance="$provenance" :compact="true" />
                    </td>

                    <td class="wl-table__cell wl-table__cell--detail" data-label="{{ trans('bingo_lottery.col_detail') }}">
                        @if ($reference !== '')
                            <a class="wl-table__link" href="{{ route('bingo-lottery.show', ['draw' => $reference]) }}">
                                {{ trans('bingo_lottery.view_detail') }}
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr class="wl-table__row wl-table__row--empty">
                    <td class="wl-table__cell wl-table__cell--empty" colspan="6">
                        {{ trans('bingo_lottery.empty_search') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($pagination !== null && (int) ($pagination['last_page'] ?? 1) > 1 && $paginationRoute !== null)
    <nav class="wl-pagination" aria-label="{{ trans('bingo_lottery.history_heading') }}">
        @php
            $page = (int) ($pagination['page'] ?? 1);
            $last = (int) ($pagination['last_page'] ?? 1);
        @endphp

        @if ((bool) ($pagination['has_previous'] ?? false))
            <a
                class="wl-pagination__link wl-pagination__link--prev"
                rel="prev"
                href="{{ route($paginationRoute, array_merge($paginationParams, ['page' => $page - 1])) }}"
            >{{ trans('bingo_lottery.pagination_previous') }}</a>
        @endif

        <span class="wl-pagination__status">
            {{ trans('bingo_lottery.pagination_status', ['page' => $page, 'last' => $last]) }}
            <span class="wl-pagination__total">{{ trans('bingo_lottery.pagination_total', ['total' => (int) ($pagination['total'] ?? 0)]) }}</span>
        </span>

        @if ((bool) ($pagination['has_next'] ?? false))
            <a
                class="wl-pagination__link wl-pagination__link--next"
                rel="next"
                href="{{ route($paginationRoute, array_merge($paginationParams, ['page' => $page + 1])) }}"
            >{{ trans('bingo_lottery.pagination_next') }}</a>
        @endif
    </nav>
@endif
