@props([
    'rows' => [],
    'isThai' => false,
    'caption' => null,
    'pagination' => null,
    'paginationRoute' => null,
    'paginationParams' => [],
])

{{--
    Accessible, responsive results table (PROMPT 5, file 20).

    ACCESSIBILITY IS STRUCTURAL, NOT DECORATIVE
    - a real <caption> names the table for a screen reader landing on it;
    - every <th> carries scope, so a cell can be announced with its column;
    - the date column header is scope="row" on each body row, so a row reads
      as "18 September 2026, 1st Prize 004615" instead of a bare number salad;
    - each numeric cell carries a data-label, which the stylesheet uses in the
      stacked mobile layout so a cell never appears without its heading;
    - the pagination is a <nav> with an aria-label and rel=prev/next.

    RESPONSIVE WITHOUT LOSING MEANING. On narrow screens the stylesheet turns
    each row into a block; the data-label attributes keep every value paired
    with its field name. No column is hidden, because a hidden 2 Down on a
    phone is a missing result, not a tidier layout.

    LEADING ZEROS. Values are printed verbatim as the strings the service
    produced. There is no formatting helper in this file.

    3FRONT / 3AFTER are rendered as individual values in their source order,
    never joined into one string.
--}}

@php
    $caption = $caption ?? trans('national_lottery.table_caption');
    $pagination = is_array($pagination) ? $pagination : null;
@endphp

<div class="nl-table-wrap">
    <table class="nl-table" data-nl-table="results">
        <caption class="nl-table__caption">{{ $caption }}</caption>

        <thead class="nl-table__head">
            <tr>
                <th scope="col" class="nl-table__th nl-table__th--date">{{ trans('national_lottery.col_draw_date') }}</th>
                <th scope="col" class="nl-table__th">{{ trans('national_lottery.col_first_prize') }}</th>
                <th scope="col" class="nl-table__th">{{ trans('national_lottery.col_three_up') }}</th>
                <th scope="col" class="nl-table__th">{{ trans('national_lottery.col_two_up') }}</th>
                <th scope="col" class="nl-table__th">{{ trans('national_lottery.col_three_front') }}</th>
                <th scope="col" class="nl-table__th">{{ trans('national_lottery.col_three_after') }}</th>
                <th scope="col" class="nl-table__th">{{ trans('national_lottery.col_two_down') }}</th>
                <th scope="col" class="nl-table__th">{{ trans('national_lottery.col_source') }}</th>
                <th scope="col" class="nl-table__th">{{ trans('national_lottery.col_detail') }}</th>
            </tr>
        </thead>

        <tbody class="nl-table__body">
            @forelse ($rows as $row)
                @php
                    $draw = (array) ($row['draw'] ?? []);
                    $date = (array) ($draw['date'] ?? []);
                    $numbers = (array) ($row['numbers'] ?? []);
                    $provenance = (array) ($row['provenance'] ?? []);
                    $reference = (string) ($draw['reference'] ?? '');
                    $displayDate = $isThai
                        ? (string) ($date['display_th'] ?? '')
                        : (string) ($date['display_en'] ?? '');
                @endphp

                <tr class="nl-table__row">
                    <th scope="row" class="nl-table__cell nl-table__cell--date" data-label="{{ trans('national_lottery.col_draw_date') }}">
                        <time datetime="{{ $date['iso'] ?? '' }}">{{ $displayDate }}</time>
                        <span class="nl-table__date-alt">{{ $date['iso'] ?? '' }}</span>
                    </th>

                    @foreach (['first_prize' => 'col_first_prize', 'three_up' => 'col_three_up', 'two_up' => 'col_two_up'] as $field => $labelKey)
                        <td class="nl-table__cell" data-label="{{ trans('national_lottery.'.$labelKey) }}">
                            @if (($numbers[$field] ?? null) === null || ($numbers[$field] ?? '') === '')
                                <span class="nl-table__missing">{{ trans('national_lottery.no_value') }}</span>
                            @else
                                <span class="nl-digits" data-nl-field="{{ $field }}">{{ $numbers[$field] }}</span>
                            @endif
                        </td>
                    @endforeach

                    @foreach (['three_front' => 'col_three_front', 'three_after' => 'col_three_after'] as $field => $labelKey)
                        <td class="nl-table__cell" data-label="{{ trans('national_lottery.'.$labelKey) }}">
                            @php $values = (array) ($numbers[$field] ?? []); @endphp
                            @if ($values === [])
                                <span class="nl-table__missing">{{ trans('national_lottery.no_value') }}</span>
                            @else
                                <ul class="nl-table__list">
                                    @foreach ($values as $index => $value)
                                        <li class="nl-table__list-item">
                                            <span
                                                class="nl-digits"
                                                data-nl-field="{{ $field }}"
                                                data-nl-position="{{ $index + 1 }}"
                                            >{{ $value }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                    @endforeach

                    <td class="nl-table__cell" data-label="{{ trans('national_lottery.col_two_down') }}">
                        @if (($numbers['two_down'] ?? null) === null || ($numbers['two_down'] ?? '') === '')
                            <span class="nl-table__missing">{{ trans('national_lottery.no_value') }}</span>
                        @else
                            <span class="nl-digits" data-nl-field="two_down">{{ $numbers['two_down'] }}</span>
                        @endif
                    </td>

                    <td class="nl-table__cell nl-table__cell--source" data-label="{{ trans('national_lottery.col_source') }}">
                        <x-national-lottery.source-status :provenance="$provenance" :compact="true" />
                    </td>

                    <td class="nl-table__cell nl-table__cell--detail" data-label="{{ trans('national_lottery.col_detail') }}">
                        @if ($reference !== '')
                            <a class="nl-table__link" href="{{ route('national-lottery.show', ['draw' => $reference]) }}">
                                {{ trans('national_lottery.view_detail') }}
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr class="nl-table__row nl-table__row--empty">
                    <td class="nl-table__cell nl-table__cell--empty" colspan="9">
                        {{ trans('national_lottery.empty_search') }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

@if ($pagination !== null && (int) ($pagination['last_page'] ?? 1) > 1 && $paginationRoute !== null)
    <nav class="nl-pagination" aria-label="{{ trans('national_lottery.history_heading') }}">
        @php
            $page = (int) ($pagination['page'] ?? 1);
            $last = (int) ($pagination['last_page'] ?? 1);
        @endphp

        @if ((bool) ($pagination['has_previous'] ?? false))
            <a
                class="nl-pagination__link nl-pagination__link--prev"
                rel="prev"
                href="{{ route($paginationRoute, array_merge($paginationParams, ['page' => $page - 1])) }}"
            >{{ trans('national_lottery.pagination_previous') }}</a>
        @endif

        <span class="nl-pagination__status">
            {{ trans('national_lottery.pagination_status', ['page' => $page, 'last' => $last]) }}
            <span class="nl-pagination__total">{{ trans('national_lottery.pagination_total', ['total' => (int) ($pagination['total'] ?? 0)]) }}</span>
        </span>

        @if ((bool) ($pagination['has_next'] ?? false))
            <a
                class="nl-pagination__link nl-pagination__link--next"
                rel="next"
                href="{{ route($paginationRoute, array_merge($paginationParams, ['page' => $page + 1])) }}"
            >{{ trans('national_lottery.pagination_next') }}</a>
        @endif
    </nav>
@endif
