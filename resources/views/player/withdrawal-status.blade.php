@extends('layouts.app')

@section('title', __('player.withdrawal_status_title'))
@section('meta_description', __('player.withdrawal_status_lead'))
@section('meta_robots', 'noindex,nofollow')

@php
    $status = $withdrawal->status->value;
    $statusKey = 'status_'.$status;
    $statusLabel = trans()->has('player.'.$statusKey) ? __('player.'.$statusKey) : __('player.not_configured');
    $currency = $withdrawal->currency instanceof \App\Enums\Currency ? $withdrawal->currency : null;
    $amountLabel = $currency instanceof \App\Enums\Currency
        ? \App\Services\Finance\Money::of((string) $withdrawal->amount, $currency)->format()
        : __('player.not_configured');
@endphp

@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <header>
        <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-400">{{ __('player.withdraw_title') }}</p>
        <h1 class="mt-1 text-3xl font-black text-white">{{ __('player.withdrawal_status_title') }}</h1>
        <p class="mt-2 text-sm text-slate-400">{{ __('player.withdrawal_status_lead') }}</p>
    </header>

    <section class="rounded-3xl border border-slate-800 bg-slate-900/90 p-6 shadow-xl sm:p-8" aria-labelledby="withdrawal-status-heading">
        <div class="flex flex-col gap-4 border-b border-slate-800 pb-5 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 id="withdrawal-status-heading" class="text-xl font-black text-white">{{ $withdrawal->reference_number }}</h2>
                <p class="mt-1 font-mono text-xs text-slate-500">{{ $withdrawal->requested_at?->toIso8601String() ?? __('player.not_recorded') }}</p>
            </div>
            <span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1 text-xs font-black uppercase text-amber-300">{{ $statusLabel }}</span>
        </div>
        <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-slate-800 bg-slate-950 p-4"><dt class="text-xs uppercase tracking-wider text-slate-500">{{ __('player.withdrawal_amount_label') }}</dt><dd class="mt-2 font-mono text-lg font-bold text-white">{{ $amountLabel }}</dd></div>
            <div class="rounded-xl border border-slate-800 bg-slate-950 p-4"><dt class="text-xs uppercase tracking-wider text-slate-500">{{ __('player.payout_method') }}</dt><dd class="mt-2 font-mono text-lg font-bold text-white">{{ __('player.payment_method_'.$withdrawal->method->value) }}</dd></div>
            <div class="rounded-xl border border-slate-800 bg-slate-950 p-4"><dt class="text-xs uppercase tracking-wider text-slate-500">{{ __('player.withdrawal_provider_label') }}</dt><dd class="mt-2 font-mono text-sm text-slate-300">{{ $withdrawal->provider ?? __('player.not_configured') }}</dd></div>
            <div class="rounded-xl border border-slate-800 bg-slate-950 p-4"><dt class="text-xs uppercase tracking-wider text-slate-500">{{ __('player.withdrawal_completed_label') }}</dt><dd class="mt-2 font-mono text-sm text-slate-300">{{ $withdrawal->completed_at?->toIso8601String() ?? __('player.not_recorded') }}</dd></div>
        </dl>
        <p class="mt-6 rounded-xl border border-slate-700 bg-slate-950/70 p-4 text-sm leading-6 text-slate-400">{{ __('player.withdrawal_status_authoritative_note') }}</p>
    </section>

    <div class="flex flex-wrap gap-3">
        <a href="{{ route('player.withdraw') }}" class="rounded-xl bg-emerald-600 px-4 py-3 text-sm font-black text-white hover:bg-emerald-500">{{ __('player.withdraw_title') }}</a>
        <a href="{{ route('player.wallet') }}" class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-sm font-bold text-slate-200 hover:bg-slate-800">{{ __('player.nav_wallet') }}</a>
    </div>
</div>
@endsection
