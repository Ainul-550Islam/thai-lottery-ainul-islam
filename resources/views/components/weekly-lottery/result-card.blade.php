@props([
    'result' => [],
    'isThai' => false,
    'heading' => null,
    'showLink' => true,
])

@php
    $result = is_array($result) ? $result : [];
    $available = (bool) ($result['available'] ?? false);
    $hasNumbers = (bool) ($result['has_numbers'] ?? $available);
    $status = strtolower((string) ($result['status'] ?? 'unavailable'));
    $draw = is_array($result['draw'] ?? null) ? $result['draw'] : [];
    $date = is_array($draw['date'] ?? null) ? $draw['date'] : [];
    $numbers = is_array($result['numbers'] ?? null) ? $result['numbers'] : [];
    $provenance = is_array($result['provenance'] ?? null) ? $result['provenance'] : [];
    $integrity = is_array($result['integrity'] ?? null) ? $result['integrity'] : null;
    $reference = isset($draw['reference']) ? (string) $draw['reference'] : '';
    $dateLabel = $isThai ? (string) ($date['display_th'] ?? '') : (string) ($date['display_en'] ?? '');
@endphp

<article class="rounded-3xl border border-[#D4AF37]/30 bg-gradient-to-br from-[#1C170E] via-[#141007] to-[#0D0B05] p-6 shadow-2xl sm:p-10" data-wl-card="result" data-wl-status="{{ $status }}">
    <header class="mb-8 flex flex-col justify-between gap-4 border-b border-[#D4AF37]/20 pb-6 sm:flex-row sm:items-start">
        <div><p class="mb-2 text-xs font-bold uppercase tracking-[0.16em] text-[#D4AF37]">{{ trans('weekly_lottery.current_result_heading') }}</p><h2 class="text-2xl font-black text-[#F5E6B8]">{{ $heading ?? trans('weekly_lottery.heading') }}</h2>@if ($dateLabel !== '')<p class="mt-2 text-sm text-gray-300"><time datetime="{{ $date['iso'] ?? '' }}">{{ $dateLabel }}</time></p>@endif @if ($reference !== '')<p class="mt-1 font-mono text-xs text-gray-500">{{ $reference }}</p>@endif</div>
        <x-weekly-lottery.source-status :provenance="$provenance" :integrity="$integrity" compact />
    </header>
    @if (! $available || ! $hasNumbers || $numbers === [])
        {{-- A draw whose numbers are not published carries an explicit badge.
             The card previously printed only the machine status key and the
             explainer, so `weekly_lottery.result_unavailable_badge` - the one
             short phrase a reader scans for - appeared nowhere on the page. --}}
        <div class="rounded-2xl border border-amber-400/30 bg-amber-400/5 p-6" role="status"><p class="inline-block rounded-full border border-amber-400/40 px-3 py-1 text-xs font-bold uppercase tracking-wider text-amber-200" data-wl-badge="result-unavailable">{{ trans('weekly_lottery.result_unavailable_badge') }}</p><p class="mt-3 font-semibold text-amber-200">{{ trans('weekly_lottery.status.'.$status) }}</p><p class="mt-2 text-sm leading-7 text-gray-400">{{ trans('weekly_lottery.result_unavailable_explainer') }}</p></div>
    @else
        <div class="grid gap-4 sm:grid-cols-3">
            @foreach (['first_6', 'three_ball', 'two_ball'] as $field)
                @if (is_string($numbers[$field] ?? null) && $numbers[$field] !== '')<div class="rounded-2xl border border-[#D4AF37]/20 bg-[#120F08] p-5" data-wl-field="{{ $field }}"><p class="text-xs font-bold uppercase tracking-wider text-[#D4AF37]">{{ trans('weekly_lottery.field_'.$field) }}</p><p class="mt-3 font-mono text-3xl font-black tracking-[0.16em] text-[#F5E6B8]">{{ $numbers[$field] }}</p><p class="mt-2 text-xs text-gray-500">{{ trans('weekly_lottery.field_'.$field.'_hint') }}</p></div>@endif
            @endforeach
        </div>
        @if ($showLink && $reference !== '')<div class="mt-8 border-t border-[#D4AF37]/15 pt-6"><a class="inline-flex items-center gap-2 rounded-xl border border-[#D4AF37]/40 bg-[#221B0E] px-5 py-3 text-sm font-bold text-[#F5E6B8]" href="{{ route('weekly-lottery.show', ['draw' => $reference]) }}">{{ trans('weekly_lottery.view_detail') }} <span aria-hidden="true">→</span></a></div>@endif
    @endif
</article>
