@extends('layouts.app')

@section('title', __('player.responsible_gaming_page_title'))
@section('meta_description', __('player.responsible_gaming_page_lead'))
@section('meta_robots', 'noindex,nofollow')

@section('content')
<div class="mx-auto max-w-4xl space-y-8">
    <header class="border-b border-slate-800 pb-6">
        <p class="text-xs font-black uppercase tracking-[0.18em] text-emerald-400">{{ __('player.responsible_gaming_title') }}</p>
        <h1 class="mt-1 text-3xl font-black tracking-tight text-white">{{ __('player.responsible_gaming_page_title') }}</h1>
        <p class="mt-2 text-sm leading-6 text-slate-400">{{ __('player.responsible_gaming_page_lead') }}</p>
    </header>

    @if (session('success'))
        <div class="rounded-xl border border-emerald-500/30 bg-emerald-950/30 p-4 text-sm text-emerald-200" role="status">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-xl border border-rose-500/30 bg-rose-950/30 p-4 text-sm text-rose-200" role="alert">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-xl border border-rose-500/30 bg-rose-950/30 p-4 text-sm text-rose-200" role="alert">
            <ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($activeExclusion)
        <section class="rounded-3xl border border-rose-500/30 bg-rose-950/30 p-6" role="status">
            <h2 class="text-lg font-black text-rose-200">{{ __('player.self_exclusion_active') }}</h2>
            <p class="mt-2 text-sm leading-6 text-rose-100/75">{{ __('player.self_exclusion_active_lead') }}</p>
            <p class="mt-3 font-mono text-xs text-rose-200">{{ $activeExclusion->ends_at?->toIso8601String() }}</p>
        </section>
    @endif

    <section class="rounded-3xl border border-slate-800 bg-slate-900/90 p-6 shadow-xl sm:p-8" aria-labelledby="limits-heading">
        <h2 id="limits-heading" class="text-xl font-black text-white">{{ __('player.responsible_gaming_title') }}</h2>
        <p class="mt-2 text-sm leading-6 text-slate-400">{{ __('player.responsible_gaming_lead') }}</p>
        <form method="POST" action="{{ route('player.limits.update') }}" class="mt-6 space-y-5">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                <div>
                    <label for="daily_deposit_limit" class="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('player.daily_deposit_limit') }}</label>
                    <input id="daily_deposit_limit" name="daily_deposit_limit" type="text" inputmode="decimal" value="{{ old('daily_deposit_limit', $limits?->daily_deposit_limit) }}" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 font-mono text-sm text-white focus:border-emerald-400 focus:outline-none">
                </div>
                <div>
                    <label for="single_bet_limit" class="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('player.single_bet_limit') }}</label>
                    <input id="single_bet_limit" name="single_bet_limit" type="text" inputmode="decimal" value="{{ old('single_bet_limit', $limits?->single_bet_limit) }}" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 font-mono text-sm text-white focus:border-emerald-400 focus:outline-none">
                </div>
                <div>
                    <label for="daily_wagering_limit" class="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('player.daily_wagering_limit') }}</label>
                    <input id="daily_wagering_limit" name="daily_wagering_limit" type="text" inputmode="decimal" value="{{ old('daily_wagering_limit', $limits?->daily_wagering_limit) }}" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 font-mono text-sm text-white focus:border-emerald-400 focus:outline-none">
                </div>
            </div>
            <p class="text-xs text-slate-500">{{ __('player.limits_optional_hint') }}</p>
            <button type="submit" class="rounded-xl bg-emerald-600 px-5 py-3 text-sm font-black text-white hover:bg-emerald-500">{{ __('player.save_limits') }}</button>
        </form>
    </section>

    <section class="rounded-3xl border border-rose-500/20 bg-slate-900/90 p-6 shadow-xl sm:p-8" aria-labelledby="exclusion-heading">
        <h2 id="exclusion-heading" class="text-xl font-black text-white">{{ __('player.self_exclusion_heading') }}</h2>
        <p class="mt-2 text-sm leading-6 text-slate-400">{{ __('player.self_exclusion_lead') }}</p>
        <form method="POST" action="{{ route('player.self-exclusion.store') }}" class="mt-6 space-y-5">
            @csrf
            <div>
                <label for="duration" class="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-400">{{ __('player.self_exclusion_duration') }}</label>
                <select id="duration" name="duration" class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white focus:border-rose-400 focus:outline-none">
                    <option value="7_days">{{ __('player.self_exclusion_7') }}</option>
                    <option value="30_days">{{ __('player.self_exclusion_30') }}</option>
                    <option value="90_days">{{ __('player.self_exclusion_90') }}</option>
                    <option value="180_days">{{ __('player.self_exclusion_180') }}</option>
                    <option value="365_days">{{ __('player.self_exclusion_365') }}</option>
                </select>
            </div>
            <button type="submit" class="rounded-xl bg-rose-600 px-5 py-3 text-sm font-black text-white hover:bg-rose-500" onclick="return confirm(@json(__('player.self_exclusion_confirm'))) ">{{ __('player.self_exclusion_submit') }}</button>
        </form>
    </section>
</div>
@endsection
