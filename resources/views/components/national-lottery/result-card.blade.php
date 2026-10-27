@props([
    'result' => [],
    'isThai' => false,
    'heading' => null,
    'showLink' => true,
    'provenance' => [],
])

@php
    $result = is_array($result) ? $result : [];
    $available = (bool) ($result['available'] ?? false);
    $status = strtolower((string) ($result['status'] ?? 'unavailable'));
    $draw = is_array($result['draw'] ?? null) ? $result['draw'] : [];
    $date = is_array($draw['date'] ?? null) ? $draw['date'] : [];
    $numbers = is_array($result['numbers'] ?? null) ? $result['numbers'] : [];
    $reference = isset($draw['reference']) ? (string) $draw['reference'] : '';
    $displayDate = $isThai ? (string) ($date['display_th'] ?? '') : (string) ($date['display_en'] ?? '');
    $front = array_values(array_filter((array) ($numbers['three_front'] ?? []), static fn ($value): bool => is_string($value) && $value !== ''));
    $after = array_values(array_filter((array) ($numbers['three_after'] ?? []), static fn ($value): bool => is_string($value) && $value !== ''));
@endphp

<article class="rounded-3xl border border-[#D4AF37]/30 bg-gradient-to-br from-[#1C170E] via-[#141007] to-[#0D0B05] p-6 shadow-2xl sm:p-10" data-nl-card="result" data-nl-status="{{ $status }}">
    <header class="mb-8 flex flex-col justify-between gap-4 border-b border-[#D4AF37]/20 pb-6 sm:flex-row sm:items-start">
        <div>
            <p class="mb-2 text-xs font-bold uppercase tracking-[0.16em] text-[#D4AF37]">{{ trans('national_lottery.current_result_heading') }}</p>
            <h2 class="text-2xl font-black tracking-tight text-[#F5E6B8]">{{ $heading ?? trans('national_lottery.heading') }}</h2>
            @if ($displayDate !== '')
                <p class="mt-2 text-sm text-gray-300"><time datetime="{{ $date['iso'] ?? '' }}">{{ $displayDate }}</time></p>
            @endif
            @if ($reference !== '')
                <p class="mt-1 font-mono text-xs text-gray-500">{{ $reference }}</p>
            @endif
        </div>
        <x-national-lottery.source-status :provenance="$provenance" compact />
    </header>

    @if (! $available || $numbers === [])
        <div class="rounded-2xl border border-amber-400/30 bg-amber-400/5 p-6" role="status">
            <p class="font-semibold text-amber-200">{{ trans('national_lottery.status.'.$status) }}</p>
            <p class="mt-2 text-sm leading-7 text-gray-400">{{ trans('national_lottery.empty_current') }}</p>
        </div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (['first_prize', 'three_up', 'two_up', 'two_down'] as $field)
                @if (isset($numbers[$field]) && is_string($numbers[$field]) && $numbers[$field] !== '')
                    <div class="rounded-2xl border border-[#D4AF37]/20 bg-[#120F08] p-5" data-nl-field="{{ $field }}">
                        <p class="text-xs font-bold uppercase tracking-wider text-[#D4AF37]">{{ trans('national_lottery.field_'.$field) }}</p>
                        <p class="mt-3 break-all font-mono text-3xl font-black tracking-[0.16em] text-[#F5E6B8]">{{ $numbers[$field] }}</p>
                        @if ($field === 'first_prize')
                            <p class="mt-2 text-xs text-gray-500">{{ trans('national_lottery.field_first_prize_hint') }}</p>
                        @endif
                    </div>
                @endif
            @endforeach
            @if ($front !== [])
                <div class="rounded-2xl border border-[#D4AF37]/20 bg-[#120F08] p-5" data-nl-field="three_front">
                    <p class="text-xs font-bold uppercase tracking-wider text-[#D4AF37]">{{ trans('national_lottery.field_three_front') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">@foreach ($front as $value)<span class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 font-mono text-xl text-[#F5E6B8]">{{ $value }}</span>@endforeach</div>
                </div>
            @endif
            @if ($after !== [])
                <div class="rounded-2xl border border-[#D4AF37]/20 bg-[#120F08] p-5" data-nl-field="three_after">
                    <p class="text-xs font-bold uppercase tracking-wider text-[#D4AF37]">{{ trans('national_lottery.field_three_after') }}</p>
                    <div class="mt-3 flex flex-wrap gap-2">@foreach ($after as $value)<span class="rounded-lg border border-white/10 bg-white/5 px-3 py-2 font-mono text-xl text-[#F5E6B8]">{{ $value }}</span>@endforeach</div>
                </div>
            @endif
        </div>

        @if ($showLink && $reference !== '')
            <div class="mt-8 border-t border-[#D4AF37]/15 pt-6">
                <a class="inline-flex items-center gap-2 rounded-xl border border-[#D4AF37]/40 bg-[#221B0E] px-5 py-3 text-sm font-bold text-[#F5E6B8]" href="{{ route('national-lottery.show', ['draw' => $reference]) }}">{{ trans('national_lottery.view_detail') }} <span aria-hidden="true">→</span></a>
            </div>
        @endif
    @endif
</article>
