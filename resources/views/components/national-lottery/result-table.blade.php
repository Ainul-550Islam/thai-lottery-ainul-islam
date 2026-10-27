@props([
    'rows' => [],
    'isThai' => false,
])

@php $rows = is_array($rows) ? $rows : []; @endphp
<div class="overflow-x-auto rounded-2xl border border-[#D4AF37]/20 bg-[#141007]" data-nl-table="results">
    <table class="w-full text-left text-sm">
        <caption class="sr-only">{{ trans('national_lottery.table_caption') }}</caption>
        <thead class="border-b border-[#D4AF37]/20 bg-[#1C170E] text-xs uppercase tracking-wider text-[#F5E6B8]"><tr><th scope="col" class="px-4 py-4">{{ trans('national_lottery.col_draw_date') }}</th><th scope="col" class="px-4 py-4">{{ trans('national_lottery.col_first_prize') }}</th><th scope="col" class="px-4 py-4">{{ trans('national_lottery.col_three_up') }}</th><th scope="col" class="px-4 py-4">{{ trans('national_lottery.col_two_up') }}</th><th scope="col" class="px-4 py-4">{{ trans('national_lottery.col_three_front') }}</th><th scope="col" class="px-4 py-4">{{ trans('national_lottery.col_three_after') }}</th><th scope="col" class="px-4 py-4">{{ trans('national_lottery.col_two_down') }}</th><th scope="col" class="px-4 py-4">{{ trans('national_lottery.col_source') }}</th><th scope="col" class="px-4 py-4">{{ trans('national_lottery.col_detail') }}</th></tr></thead>
        <tbody class="divide-y divide-white/5 text-gray-300">
        @forelse ($rows as $row)
            @php
                $draw = is_array($row['draw'] ?? null) ? $row['draw'] : [];
                $date = is_array($draw['date'] ?? null) ? $draw['date'] : [];
                $numbers = is_array($row['numbers'] ?? null) ? $row['numbers'] : [];
                $source = is_array($row['provenance'] ?? null) ? $row['provenance'] : [];
                $ref = (string) ($draw['reference'] ?? '');
                $dateLabel = $isThai ? (string) ($date['display_th'] ?? '') : (string) ($date['display_en'] ?? '');
                $front = array_values(array_filter((array) ($numbers['three_front'] ?? []), static fn ($value): bool => is_string($value) && $value !== ''));
                $after = array_values(array_filter((array) ($numbers['three_after'] ?? []), static fn ($value): bool => is_string($value) && $value !== ''));
            @endphp
            <tr class="hover:bg-white/[0.03]">
                <th scope="row" class="whitespace-nowrap px-4 py-4 font-semibold text-white">{{ $dateLabel }}</th>
                <td class="px-4 py-4 font-mono" data-nl-field="first_prize">{{ is_string($numbers['first_prize'] ?? null) && $numbers['first_prize'] !== '' ? $numbers['first_prize'] : trans('national_lottery.no_value') }}</td>
                <td class="px-4 py-4 font-mono" data-nl-field="three_up">{{ is_string($numbers['three_up'] ?? null) && $numbers['three_up'] !== '' ? $numbers['three_up'] : trans('national_lottery.no_value') }}</td>
                <td class="px-4 py-4 font-mono" data-nl-field="two_up">{{ is_string($numbers['two_up'] ?? null) && $numbers['two_up'] !== '' ? $numbers['two_up'] : trans('national_lottery.no_value') }}</td>
                <td class="px-4 py-4 font-mono" data-nl-field="three_front">{{ $front === [] ? trans('national_lottery.no_value') : implode(', ', $front) }}</td>
                <td class="px-4 py-4 font-mono" data-nl-field="three_after">{{ $after === [] ? trans('national_lottery.no_value') : implode(', ', $after) }}</td>
                <td class="px-4 py-4 font-mono" data-nl-field="two_down">{{ is_string($numbers['two_down'] ?? null) && $numbers['two_down'] !== '' ? $numbers['two_down'] : trans('national_lottery.no_value') }}</td>
                <td class="px-4 py-4 text-xs">{{ trans('national_lottery.source_state.'.strtolower((string) ($source['source_state'] ?? 'UNAVAILABLE'))) }}</td>
                <td class="px-4 py-4">@if ($ref !== '')<a class="text-[#D4AF37] hover:underline" href="{{ route('national-lottery.show', ['draw' => $ref]) }}">{{ trans('national_lottery.view_detail') }}</a>@else<span>{{ trans('national_lottery.no_value') }}</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">{{ trans('national_lottery.empty_year') }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
