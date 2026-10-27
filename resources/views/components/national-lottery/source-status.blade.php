@props([
    'provenance' => [],
    'compact' => false,
])

@php
    $provenance = is_array($provenance) ? $provenance : [];
    $state = (string) ($provenance['source_state'] ?? 'UNAVAILABLE');
    $stateKey = strtolower($state);
    $labelKey = 'national_lottery.source_state.'.$stateKey;
@endphp

@if ($compact)
    <span class="inline-flex items-center gap-2 rounded-xl border border-white/10 bg-black/20 px-3 py-2 text-xs text-gray-300" data-nl-source-state="{{ $state }}">
        <span class="h-2 w-2 rounded-full {{ ($provenance['available'] ?? false) ? 'bg-emerald-400' : 'bg-amber-400' }}" aria-hidden="true"></span>
        <span>{{ trans($labelKey) }}</span>
    </span>
@else
    <section class="rounded-2xl border border-white/10 bg-[#100D06] p-5" data-nl-source-state="{{ $state }}">
        <h2 class="text-lg font-bold text-[#F5E6B8]">{{ trans('national_lottery.provenance_heading') }}</h2>
        <p class="mt-2 text-sm text-gray-400">{{ trans('national_lottery.source_state_hint.'.$stateKey) }}</p>
        @if (($provenance['available'] ?? false) === true)
            <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                @foreach (['provider', 'source_state', 'source_identifier', 'source_host', 'parser_version', 'retrieved_at', 'imported_at', 'result_version', 'supersedes_version', 'payload_fingerprint', 'normalized_fingerprint'] as $key)
                    @php $value = $provenance[$key] ?? null; @endphp
                    <div class="rounded-xl border border-white/5 bg-white/[0.03] p-3"><dt class="text-xs text-gray-500">{{ trans('national_lottery.provenance.'.($key === 'supersedes_version' ? 'supersedes' : $key)) }}</dt><dd class="mt-1 break-all font-mono text-xs text-gray-200">{{ $value === null || $value === '' ? trans('national_lottery.provenance.none') : $value }}</dd></div>
                @endforeach
            </dl>
            <p class="mt-4 text-xs leading-6 text-gray-500">{{ trans('national_lottery.provenance.explainer') }}</p>
        @endif
    </section>
@endif
