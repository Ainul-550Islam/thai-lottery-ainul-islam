@php
    $projection = is_array($projection ?? null) ? $projection : [];
    $available = (bool) ($projection['available'] ?? false);
    $status = strtolower((string) ($projection['status'] ?? 'unavailable'));
    $draw = is_array($projection['draw'] ?? null) ? $projection['draw'] : [];
    $date = is_array($draw['date'] ?? null) ? $draw['date'] : [];
    $provenance = is_array($projection['provenance'] ?? null) ? $projection['provenance'] : [];
    $reference = (string) ($draw['reference'] ?? '');
@endphp
<section class="space-y-6" data-national-detail="{{ $detail_mode ?? 'draw' }}">
    <nav class="text-sm text-gray-400" aria-label="{{ trans('national_lottery.heading') }}"><a class="hover:text-[#D4AF37]" href="{{ route('national-lottery.index') }}">{{ trans('national_lottery.heading') }}</a>@if ($reference !== '')<span class="px-2" aria-hidden="true">/</span><span class="text-[#F5E6B8]">{{ $reference }}</span>@endif</nav>
    <header class="space-y-3"><p class="text-xs font-semibold uppercase tracking-[0.18em] text-[#D4AF37]">{{ trans('national_lottery.detail_heading') }}</p><h1 class="text-4xl font-black text-[#F5E6B8]">{{ trans('national_lottery.detail_heading') }}@if (($date['display_en'] ?? '') !== '') — {{ $is_thai ? ($date['display_th'] ?? '') : ($date['display_en'] ?? '') }}@endif</h1><p class="max-w-3xl text-sm leading-7 text-gray-400">{{ trans('national_lottery.not_official_notice') }}</p></header>
    @if ($available)
        @if (($provenance['supersedes_version'] ?? null) !== null)<p class="rounded-xl border border-amber-400/30 bg-amber-400/5 p-4 text-sm text-amber-200" role="status">{{ trans('national_lottery.correction_notice', ['version' => $provenance['result_version'] ?? '']) }}</p>@endif
        <x-national-lottery.result-card :result="$projection" :is-thai="$is_thai" :show-link="false" /><x-national-lottery.source-status :provenance="$provenance" /><x-national-lottery.year-nav :years="$years" :active-year="null" />@else<section class="rounded-3xl border border-amber-400/30 bg-[#141007] p-8" role="status"><h2 class="text-xl font-bold text-amber-200">{{ trans('national_lottery.status.'.$status) }}</h2><p class="mt-2 text-sm leading-7 text-gray-400">{{ trans('national_lottery.empty_current') }}</p><a class="mt-5 inline-flex rounded-xl border border-[#D4AF37]/40 px-4 py-3 text-sm font-bold text-[#F5E6B8]" href="{{ route('national-lottery.index') }}">{{ trans('national_lottery.heading') }}</a></section>@endif
</section>
