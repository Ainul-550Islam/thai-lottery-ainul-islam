@extends('layouts.app')

@section('title', __('player.draw_detail_title', ['draw' => $draw->draw_number ?? $draw->id]))
@section('meta_description', __('player.draw_detail_lead'))
@section('meta_robots', 'noindex,nofollow')

@section('content')
@php
    $result = $draw->result;
    $resultArrays = [
        'second_prize' => __('player.second_prize'),
        'third_prize' => __('player.third_prize'),
        'consolation_prizes' => __('player.consolation_prizes'),
    ];
@endphp

<div class="mx-auto max-w-4xl space-y-6">
    <div class="flex items-center justify-between gap-3">
        <a href="{{ route('player.draws') }}" class="tl-v2-btn-outline text-xs">&larr; {{ __('player.back_to_draws') }}</a>
        <span class="tl-v2-pill tl-v2-pill--configured font-mono">{{ $draw->draw_number ?? $draw->id }}</span>
    </div>

    <section id="live-results-board" class="tl-v2-card" aria-labelledby="draw-detail-heading" aria-live="polite">
        <div class="flex flex-col gap-4 border-b border-[#D4AF37]/15 pb-6 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="tl-v2-eyebrow">{{ __('player.draw_detail_label') }}</p>
                <h1 id="draw-detail-heading" class="mt-1 font-mono text-3xl font-black text-[#FFF6D6]">{{ $draw->draw_number ?? $draw->id }}</h1>
                <p class="mt-2 text-xs text-[#D4CAB3]">{{ __('player.scheduled_label') }} <span class="font-mono text-[#F5E6B8]">{{ $draw->scheduled_at?->format('l, F j, Y H:i T') ?? __('player.not_recorded') }}</span></p>
            </div>
            <span class="tl-v2-pill tl-v2-pill--verified">{{ $draw->status->value }}</span>
        </div>

        @if ($result)
            <div class="mt-6 tl-v2-subcard border-[#D4AF37]/35 p-6 text-center">
                <span class="block text-xs font-bold uppercase tracking-widest text-[#D4AF37]">{{ __('player.first_prize_1') }}</span>
                <strong class="mt-2 block font-mono text-4xl font-black tracking-widest text-[#FFF6D6]" data-tabular-nums data-prize="first_prize">{{ $result->first_prize ?? __('player.not_published') }}</strong>
            </div>
            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach ($resultArrays as $field => $label)
                    @php $values = is_array($result->{$field}) ? array_map('strval', $result->{$field}) : []; @endphp
                    <div class="tl-v2-subcard">
                        <span class="block text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ $label }}</span>
                        <strong class="mt-2 block break-words font-mono text-sm text-[#F5E6B8]" data-tabular-nums>{{ $values === [] ? __('player.not_published') : implode(', ', $values) }}</strong>
                    </div>
                @endforeach
            </div>
            @if (is_array($result->all_numbers) && $result->all_numbers !== [])
                <div class="mt-5 tl-v2-subcard">
                    <span class="block text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ __('player.all_recorded_numbers') }}</span>
                    <p class="mt-2 break-words font-mono text-sm text-[#FAF6EC]" data-tabular-nums>{{ implode(', ', array_map('strval', $result->all_numbers)) }}</p>
                </div>
            @endif
        @else
            <div class="mt-6 tl-v2-subcard border-dashed p-6 text-center" role="status">
                <p class="font-bold text-[#FFF6D6]">{{ __('player.results_pending') }}</p>
                <p class="mt-2 text-sm text-[#D4CAB3]">{{ __('player.results_pending_lead') }}</p>
            </div>
        @endif
    </section>
</div>
@endsection

@push('scripts')
    @vite('resources/js/lottery/live-results.js')
@endpush
