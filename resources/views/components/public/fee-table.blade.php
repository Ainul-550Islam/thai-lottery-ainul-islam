{{-- Public fee table: semantic table, config-driven rows, escaped output only. --}}
<section class="pp-section" aria-labelledby="pp-fees-title" data-pp-section="fee-table">
    <div class="pp-section__inner">
        <h2 class="pp-section__title" id="pp-fees-title">{{ $title }}</h2>

        @if (($rows ?? []) === [])
            <p class="pp-section__text" data-pp-fees-empty>{{ $emptyLabel ?? 'No public fees are configured at this time.' }}</p>
        @else
            <div class="fee-table-wrap" tabindex="0" role="region" aria-label="{{ $title }}">
                <table class="fee-table" data-pp-fee-table>
                    <caption class="visually-hidden">{{ $caption ?? $title }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ $colCategory ?? 'Category' }}</th>
                            <th scope="col">{{ $colType ?? 'Calculation' }}</th>
                            <th scope="col">{{ $colAmount ?? 'Amount / rate' }}</th>
                            <th scope="col">{{ $colDescription ?? 'Description' }}</th>
                            <th scope="col">{{ $colEffective ?? 'Effective' }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr data-pp-fee="{{ $row['key'] }}" data-pp-calculation="{{ $row['calculation'] }}">
                                <th scope="row">{{ $row['label'] }}</th>
                                <td>{{ $row['calculation_label'] }}</td>
                                <td class="fee-table__amount" data-pp-fee-amount>
                                    @if ($row['amount_display'] === ($notConfigured ?? 'NOT_CONFIGURED'))
                                        <span class="fee-table__nc">{{ $row['amount_display'] }}</span>
                                    @else
                                        {{ $row['amount_display'] }}
                                    @endif
                                </td>
                                <td>{{ $row['description'] }}</td>
                                <td>
                                    @if ($row['effective_from'] !== null)
                                        {{ $row['effective_from'] }}
                                        @if ($row['effective_to'] !== null)
                                            → {{ $row['effective_to'] }}
                                        @endif
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        @isset($footerNote)
            <p class="pp-section__text fee-table__note">{{ $footerNote }}</p>
        @endisset
    </div>
</section>
