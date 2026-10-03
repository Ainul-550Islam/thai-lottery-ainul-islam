@extends('layouts.app')

@section('title', __('results.meta_title'))
@section('meta_description', __('results.meta_description'))

@section('content')
<main class="min-h-screen bg-[#0B0904] text-white pt-24 pb-20 px-4 sm:px-6 lg:px-8" data-results-hub>
    <div class="max-w-6xl mx-auto space-y-10">
        <section class="space-y-4 text-center" aria-labelledby="results-title">
            <p class="text-xs uppercase tracking-[0.22em] text-[#D4AF37]">{{ __('results.published_results') }}</p>
            <h1 id="results-title" class="text-4xl sm:text-5xl font-black tracking-tight text-[#F5E6B8]">
                {{ __('results.title') }}
            </h1>
            <p class="max-w-3xl mx-auto text-base text-gray-300 leading-relaxed">
                {{ __('results.lead') }}
            </p>
        </section>

        <section class="flex flex-col sm:flex-row justify-center gap-3" aria-label="{{ __('results.title') }} actions">
            <a href="{{ route('ticket-check') }}" class="rounded-xl bg-[#D4AF37] px-5 py-3 text-center text-sm font-bold text-[#0B0904] focus:outline-none focus-visible:ring-2 focus-visible:ring-white">
                {{ __('results.check_link') }}
            </a>
            <a href="{{ route('results.search') }}" class="rounded-xl border border-[#D4AF37]/40 px-5 py-3 text-center text-sm font-semibold text-[#F5E6B8] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#D4AF37]">
                {{ __('results.search_link') }}
            </a>
        </section>

        @if (empty($rows))
            <section class="rounded-3xl border border-white/10 bg-[#141007] p-8 text-center" aria-live="polite">
                <h2 class="text-xl font-bold text-[#F5E6B8]">{{ __('results.no_public_data') }}</h2>
                <p class="mt-2 text-sm text-gray-400">{{ __('results.unavailable') }}</p>
            </section>
        @else
            <section aria-labelledby="results-list-title">
                <h2 id="results-list-title" class="mb-5 text-2xl font-black text-[#F5E6B8]">{{ __('results.published_results') }}</h2>
                <div class="overflow-x-auto rounded-3xl border border-[#D4AF37]/20 bg-[#141007] shadow-2xl">
                    <table class="min-w-[860px] w-full text-left text-sm">
                        <caption class="sr-only">{{ __('results.published_results') }}</caption>
                        <thead class="border-b border-white/10 text-xs uppercase tracking-wider text-gray-400">
                            <tr>
                                <th scope="col" class="px-5 py-4">{{ __('results.draw') }}</th>
                                <th scope="col" class="px-5 py-4">{{ __('results.date') }}</th>
                                <th scope="col" class="px-5 py-4">{{ __('results.first_prize') }}</th>
                                <th scope="col" class="px-5 py-4">{{ __('results.second_prize') }}</th>
                                <th scope="col" class="px-5 py-4">{{ __('results.third_prize') }}</th>
                                <th scope="col" class="px-5 py-4">{{ __('results.source') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            @foreach ($rows as $row)
                                @php
                                    $source = (string) ($row['source_state'] ?? 'unavailable');
                                    $sourceLabel = match ($source) {
                                        'OFFICIAL_SOURCE_VERIFIED' => __('results.official_source_verified'),
                                        'INTERNAL_RECONCILED' => __('results.internal_reconciled'),
                                        'FIXTURE_ONLY' => __('results.fixture_only'),
                                        'NOT_CONFIGURED' => __('results.not_configured'),
                                        default => __('results.unavailable_source'),
                                    };
                                    $list = static function (mixed $value): string {
                                        if (is_array($value)) {
                                            return implode(', ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value));
                                        }
                                        return is_scalar($value) && $value !== null && $value !== '' ? (string) $value : __('results.no_prize_data');
                                    };
                                @endphp
                                <tr class="align-top">
                                    <td class="px-5 py-4 font-semibold text-white">{{ $row['draw_number'] ?: __('results.not_published') }}</td>
                                    <td class="px-5 py-4 text-gray-300">{{ $row['draw_date'] ?: __('results.not_published') }}</td>
                                    <td class="px-5 py-4 font-mono text-[#F5E6B8]">{{ $row['first_prize'] ?: __('results.no_prize_data') }}</td>
                                    <td class="px-5 py-4 font-mono text-gray-200">{{ $list($row['second_prize'] ?? null) }}</td>
                                    <td class="px-5 py-4 font-mono text-gray-200">{{ $list($row['third_prize'] ?? null) }}</td>
                                    <td class="px-5 py-4">
                                        <span class="inline-flex rounded-full border border-[#D4AF37]/30 px-3 py-1 text-xs font-semibold text-[#F5E6B8]" data-source-state="{{ $source }}" aria-label="{{ __('results.source') }}: {{ $sourceLabel }}">
                                            {{ $sourceLabel }}
                                        </span>
                                        {{-- The import fingerprint. It ties this row to the
                                             import that produced it, so a published result can
                                             be traced rather than taken on trust. --}}
                                        <span class="mt-1 block font-mono text-[10px] text-gray-400" data-result-version="{{ $row['result_version'] ?? '' }}">
                                            {{ $row['result_version'] ?? '' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</main>
@endsection
