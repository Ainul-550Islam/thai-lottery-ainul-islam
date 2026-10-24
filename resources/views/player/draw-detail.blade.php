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
        <a href="{{ route('player.draws') }}" class="text-xs font-bold text-emerald-400 hover:underline">&larr; {{ __('player.back_to_draws') }}</a>
        <span class="rounded-full bg-slate-800 px-3 py-1 font-mono text-xs text-slate-300">{{ $draw->draw_number ?? $draw->id }}</span>
    </div>

    <section id="live-results-board" class="rounded-3xl border border-slate-800 bg-slate-900 p-6 shadow-xl sm:p-8" aria-labelledby="draw-detail-heading" aria-live="polite">
        <div class="flex flex-col gap-4 border-b border-slate-800 pb-6 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="text-xs font-black uppercase tracking-wider text-emerald-400">{{ __('player.draw_detail_label') }}</p>
                <h1 id="draw-detail-heading" class="mt-1 font-mono text-3xl font-black text-white">{{ $draw->draw_number ?? $draw->id }}</h1>
                <p class="mt-2 text-xs text-slate-400">{{ __('player.scheduled_label') }} <span class="font-mono text-slate-200">{{ $draw->scheduled_at?->format('l, F j, Y H:i T') ?? __('player.not_recorded') }}</span></p>
            </div>
            <span class="rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3.5 py-1.5 text-xs font-bold uppercase tracking-wider text-emerald-300">{{ $draw->status->value }}</span>
        </div>

        @if ($result)
            <div class="mt-6 rounded-2xl border border-amber-500/20 bg-slate-950 p-6 text-center">
                <span class="block text-xs font-bold uppercase tracking-widest text-slate-400">{{ __('player.first_prize_1') }}</span>
                <strong class="mt-2 block font-mono text-4xl font-black tracking-widest text-amber-400" data-prize="first_prize">{{ $result->first_prize ?? __('player.not_published') }}</strong>
            </div>
            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach ($resultArrays as $field => $label)
                    @php $values = is_array($result->{$field}) ? array_map('strval', $result->{$field}) : []; @endphp
                    <div class="rounded-2xl border border-slate-800 bg-slate-950/80 p-4">
                        <span class="block text-xs uppercase tracking-wider text-slate-500">{{ $label }}</span>
                        <strong class="mt-2 block break-words font-mono text-sm text-emerald-300">{{ $values === [] ? __('player.not_published') : implode(', ', $values) }}</strong>
                    </div>
                @endforeach
            </div>
            @if (is_array($result->all_numbers) && $result->all_numbers !== [])
                <div class="mt-5 rounded-2xl border border-slate-800 bg-slate-950/80 p-4">
                    <span class="block text-xs uppercase tracking-wider text-slate-500">{{ __('player.all_recorded_numbers') }}</span>
                    <p class="mt-2 break-words font-mono text-sm text-slate-300">{{ implode(', ', array_map('strval', $result->all_numbers)) }}</p>
                </div>
            @endif
        @else
            <div class="mt-6 rounded-2xl border border-amber-500/30 bg-amber-950/20 p-6 text-center" role="status">
                <p class="font-bold text-amber-200">{{ __('player.results_pending') }}</p>
                <p class="mt-2 text-sm text-amber-100/70">{{ __('player.results_pending_lead') }}</p>
            </div>
        @endif
    </section>
</div>
@endsection

@push('scripts')
    @vite('resources/js/lottery/live-results.js')
@endpush
