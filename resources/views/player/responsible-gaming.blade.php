@extends('layouts.app')

@section('title', __('player.responsible_gaming_page_title'))
@section('meta_description', __('player.responsible_gaming_page_lead'))
@section('meta_robots', 'noindex,nofollow')

@section('content')
<div class="mx-auto max-w-4xl space-y-8">
    <header class="border-b border-[#D4AF37]/15 pb-6">
        <p class="tl-v2-eyebrow">{{ __('player.responsible_gaming_title') }}</p>
        <h1 class="mt-1 text-3xl font-black tracking-tight text-[#FFF6D6]">{{ __('player.responsible_gaming_page_title') }}</h1>
        <p class="mt-2 text-sm leading-6 text-[#D4CAB3]">{{ __('player.responsible_gaming_page_lead') }}</p>
    </header>

    @if (session('success'))
        <div class="rounded-xl border border-emerald-500/45 bg-emerald-950/65 p-4 text-sm text-emerald-200" role="status">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="rounded-xl border border-rose-500/45 bg-rose-950/65 p-4 text-sm text-rose-200" role="alert">{{ session('error') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-xl border border-rose-500/45 bg-rose-950/65 p-4 text-sm text-rose-200" role="alert">
            <ul class="list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($activeExclusion)
        <section class="tl-v2-card border-rose-500/45 bg-rose-950/40" role="status">
            <h2 class="text-lg font-black text-rose-200">{{ __('player.self_exclusion_active') }}</h2>
            <p class="mt-2 text-sm leading-6 text-rose-100">{{ __('player.self_exclusion_active_lead') }}</p>
            <p class="mt-3 font-mono text-xs text-rose-200">{{ $activeExclusion->ends_at?->toIso8601String() }}</p>
        </section>
    @endif

    <section class="tl-v2-card" aria-labelledby="limits-heading">
        <h2 id="limits-heading" class="text-xl font-black text-[#FFF6D6]">{{ __('player.responsible_gaming_title') }}</h2>
        <p class="mt-2 text-sm leading-6 text-[#D4CAB3]">{{ __('player.responsible_gaming_lead') }}</p>
        <form method="POST" action="{{ route('player.limits.update') }}" class="mt-6 space-y-5">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                <div>
                    <label for="daily_deposit_limit" class="mb-2 block text-xs font-bold uppercase tracking-wider text-[#F5E6B8]">{{ __('player.daily_deposit_limit') }}</label>
                    <input id="daily_deposit_limit" name="daily_deposit_limit" type="text" inputmode="decimal" value="{{ old('daily_deposit_limit', $limits?->daily_deposit_limit) }}" class="tl-v2-input font-mono">
                </div>
                <div>
                    <label for="single_bet_limit" class="mb-2 block text-xs font-bold uppercase tracking-wider text-[#F5E6B8]">{{ __('player.single_bet_limit') }}</label>
                    <input id="single_bet_limit" name="single_bet_limit" type="text" inputmode="decimal" value="{{ old('single_bet_limit', $limits?->single_bet_limit) }}" class="tl-v2-input font-mono">
                </div>
                <div>
                    <label for="daily_wagering_limit" class="mb-2 block text-xs font-bold uppercase tracking-wider text-[#F5E6B8]">{{ __('player.daily_wagering_limit') }}</label>
                    <input id="daily_wagering_limit" name="daily_wagering_limit" type="text" inputmode="decimal" value="{{ old('daily_wagering_limit', $limits?->daily_wagering_limit) }}" class="tl-v2-input font-mono">
                </div>
            </div>
            <p class="text-xs text-[#9D937D]">{{ __('player.limits_optional_hint') }}</p>
            <button type="submit" class="tl-v2-btn-gold text-sm">{{ __('player.save_limits') }}</button>
        </form>
    </section>

    <section class="tl-v2-card border-rose-500/35" aria-labelledby="exclusion-heading">
        <h2 id="exclusion-heading" class="text-xl font-black text-[#FFF6D6]">{{ __('player.self_exclusion_heading') }}</h2>
        <p class="mt-2 text-sm leading-6 text-[#D4CAB3]">{{ __('player.self_exclusion_lead') }}</p>
        <form method="POST" action="{{ route('player.self-exclusion.store') }}" class="mt-6 space-y-5">
            @csrf
            <div>
                <label for="duration" class="mb-2 block text-xs font-bold uppercase tracking-wider text-[#F5E6B8]">{{ __('player.self_exclusion_duration') }}</label>
                <select id="duration" name="duration" class="tl-v2-input">
                    <option value="7_days">{{ __('player.self_exclusion_7') }}</option>
                    <option value="30_days">{{ __('player.self_exclusion_30') }}</option>
                    <option value="90_days">{{ __('player.self_exclusion_90') }}</option>
                    <option value="180_days">{{ __('player.self_exclusion_180') }}</option>
                    <option value="365_days">{{ __('player.self_exclusion_365') }}</option>
                </select>
            </div>
            <button type="submit" class="min-h-[44px] rounded-xl bg-rose-700 px-5 py-3 text-sm font-black text-white hover:bg-rose-600 transition" onclick="return confirm(@json(__('player.self_exclusion_confirm'))) ">{{ __('player.self_exclusion_submit') }}</button>
        </form>
    </section>
</div>
@endsection
