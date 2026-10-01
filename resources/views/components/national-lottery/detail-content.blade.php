@props([
    'projection' => [],
    'years' => [],
    'isThai' => false,
    'detailMode' => 'draw-detail',
])

@php
    $projection = is_array($projection) ? $projection : [];
    $draw = is_array($projection['draw'] ?? null) ? $projection['draw'] : [];
    $date = is_array($draw['date'] ?? null) ? $draw['date'] : [];
    $provenance = is_array($projection['provenance'] ?? null) ? $projection['provenance'] : [];
    $displayDate = $isThai ? (string) ($date['display_th'] ?? '') : (string) ($date['display_en'] ?? '');
    $reference = (string) ($draw['reference'] ?? '');
@endphp

<div class="space-y-8" data-nl-detail-mode="{{ $detailMode }}">
    <nav aria-label="{{ trans('national_lottery.heading') }}" class="text-sm text-gray-400">
        <a class="text-[#D4AF37] hover:text-[#F5E6B8]" href="{{ route('national-lottery.index') }}">{{ trans('national_lottery.heading') }}</a>
        <span aria-hidden="true"> / </span>
        <span>{{ trans('national_lottery.detail_heading') }}</span>
    </nav>

    <header>
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#D4AF37]">{{ trans('national_lottery.detail_heading') }}</p>
        <h1 class="mt-2 text-4xl font-black text-[#F5E6B8]">{{ trans('national_lottery.heading') }}</h1>
        @if ($displayDate !== '')
            <p class="mt-3 text-sm text-gray-400"><time datetime="{{ $date['iso'] ?? '' }}">{{ $displayDate }}</time>@if ($reference !== '') <span class="ml-2 font-mono text-xs text-gray-500">{{ $reference }}</span>@endif</p>
        @endif
    </header>

    <x-national-lottery.result-card
        :result="$projection"
        :provenance="$provenance"
        :is-thai="$isThai"
        :show-link="false"
    />

    <x-national-lottery.source-status :provenance="$provenance" />
    <x-national-lottery.year-nav :years="$years" :active-year="null" />
</div>
