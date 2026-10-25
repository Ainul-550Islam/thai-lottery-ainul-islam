{{--
    Print-safe legal document template (Terms / Privacy / Fees).

    Rendered by App\Services\PublicPages\LegalPdfExportService into mPDF, so the
    rules here are the rules of a print engine, not of a browser:
      * table/block layout only (no flexbox, no grid, no JS);
      * no external asset of any kind — fonts are embedded from resources/fonts,
        so a render works with the network down;
      * EVERY value is escaped: a document section is operator-authored text and
        is printed as text, never as markup.
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $documentTitle }}</title>
    <style>
        body {
            font-family: {{ $fontFamily }};
            font-size: {{ $baseFontSize }}pt;
            color: #14181f;
            line-height: 1.55;
        }

        h1 {
            font-size: {{ $baseFontSize + 8 }}pt;
            margin: 0 0 4mm 0;
            line-height: 1.25;
        }

        h2 {
            font-size: {{ $baseFontSize + 3 }}pt;
            margin: 6mm 0 2mm 0;
            padding-bottom: 1.2mm;
            border-bottom: 0.35mm solid #c9d1dc;
            line-height: 1.3;
        }

        p {
            margin: 0 0 2.2mm 0;
            text-align: left;
        }

        .doc-operator {
            font-size: {{ $baseFontSize - 1 }}pt;
            letter-spacing: 0.4pt;
            color: #4a5568;
            margin-bottom: 1.5mm;
        }

        .doc-meta {
            width: 100%;
            border-collapse: collapse;
            margin: 0 0 5mm 0;
            font-size: {{ $baseFontSize - 1 }}pt;
            color: #2b3442;
        }

        .doc-meta td {
            padding: 1.4mm 2mm;
            background: #f2f5f9;
            border: 0.2mm solid #d7dee8;
        }

        .doc-meta .label {
            color: #5b6779;
        }

        .doc-section-body {
            margin-bottom: 2mm;
        }

        .doc-section-extra {
            margin: 0 0 2mm 5mm;
            padding: 0;
        }

        .doc-section-extra li {
            margin-bottom: 1.6mm;
        }

        table.doc-fees {
            width: 100%;
            border-collapse: collapse;
            margin: 1mm 0 4mm 0;
            font-size: {{ $baseFontSize - 1 }}pt;
        }

        table.doc-fees th {
            background: #e8edf4;
            border: 0.2mm solid #c9d3df;
            padding: 1.6mm 2mm;
            text-align: left;
            font-weight: bold;
        }

        table.doc-fees td {
            border: 0.2mm solid #d7dee8;
            padding: 1.6mm 2mm;
            vertical-align: top;
        }

        table.doc-fees td.amount {
            white-space: nowrap;
            text-align: right;
        }

        .doc-not-configured {
            color: #7a4a00;
            font-weight: bold;
        }

        .doc-note {
            margin-top: 6mm;
            padding-top: 2mm;
            border-top: 0.2mm solid #c9d1dc;
            font-size: {{ $baseFontSize - 2 }}pt;
            color: #5b6779;
        }
    </style>
</head>
<body>

    <div class="doc-operator">{{ $operatorName }}</div>
    <h1>{{ $documentTitle }}</h1>

    <table class="doc-meta">
        <tr>
            <td><span class="label">{{ $versionLabel }}:</span> {{ $version }}</td>
            <td><span class="label">{{ $effectiveLabel }}:</span> {{ $effectiveAt }}</td>
            <td><span class="label">{{ $localeLabel }}:</span> {{ strtoupper($locale) }}</td>
        </tr>
    </table>

    @if ($type === 'fees')
        @foreach ($feeGroups as $group)
            <h2>{{ $group['label'] ?? '' }}</h2>

            <table class="doc-fees">
                <thead>
                    <tr>
                        <th style="width: 30%">{{ $feesCategoryLabel }}</th>
                        <th style="width: 16%">{{ $feesProviderLabel }}</th>
                        <th style="width: 18%">{{ $feesAmountLabel }}</th>
                        <th style="width: 36%">{{ $feesNotesLabel }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ((array) ($group['rows'] ?? []) as $row)
                        <tr>
                            <td>{{ $row['label'] ?? '' }}</td>
                            <td>{{ $row['provider_label'] ?? '—' }}</td>
                            <td class="amount">
                                @if (($row['state'] ?? '') === 'CONFIGURED')
                                    {{ $row['amount_display'] ?? '' }}
                                @else
                                    <span class="doc-not-configured">{{ $feesNotConfiguredLabel }}</span>
                                @endif
                            </td>
                            <td>
                                {{ $row['description'] ?? '' }}
                                @if (! empty($row['currency']))
                                    ({{ $row['currency'] }})
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @else
        @foreach ($sections as $section)
            <h2>{{ $section['title'] ?? '' }}</h2>

            @foreach (preg_split('/\R{2,}/u', (string) ($section['body'] ?? '')) ?: [] as $paragraph)
                @if (trim($paragraph) !== '')
                    <p class="doc-section-body">{{ $paragraph }}</p>
                @endif
            @endforeach

            @php
                $extras = array_values(array_filter(
                    array_map(static fn ($extra): string => trim((string) $extra), (array) ($section['extra'] ?? [])),
                    static fn (string $extra): bool => $extra !== '',
                ));
            @endphp

            @if ($extras !== [])
                <ul class="doc-section-extra">
                    @foreach ($extras as $extra)
                        <li>{{ $extra }}</li>
                    @endforeach
                </ul>
            @endif
        @endforeach
    @endif

    <p class="doc-note">{{ $generatedLabel }}: {{ $generatedAt }} UTC</p>

</body>
</html>
