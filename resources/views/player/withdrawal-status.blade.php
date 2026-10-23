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
        <p class="tl-v2-eyebrow">{{ __('player.withdraw_title') }}</p>
        <h1 class="mt-1 text-3xl font-black text-[#FFF6D6]">{{ __('player.withdrawal_status_title') }}</h1>
        <p class="mt-2 text-sm text-[#D4CAB3]">{{ __('player.withdrawal_status_lead') }}</p>
    </header>

    <section class="tl-v2-card" aria-labelledby="withdrawal-status-heading">
        <div class="flex flex-col gap-4 border-b border-[#D4AF37]/15 pb-5 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 id="withdrawal-status-heading" class="text-xl font-black font-mono text-[#FFF6D6]">{{ $withdrawal->reference_number }}</h2>
                <p class="mt-1 font-mono text-xs text-[#9D937D]">{{ $withdrawal->requested_at?->toIso8601String() ?? __('player.not_recorded') }}</p>
            </div>
            <span class="tl-v2-pill tl-v2-pill--configured">{{ $statusLabel }}</span>
        </div>
        <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="tl-v2-subcard"><dt class="text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ __('player.withdrawal_amount_label') }}</dt><dd class="mt-2 font-mono text-lg font-bold text-[#FFF6D6]" data-tabular-nums>{{ $amountLabel }}</dd></div>
            <div class="tl-v2-subcard"><dt class="text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ __('player.payout_method') }}</dt><dd class="mt-2 font-mono text-lg font-bold text-[#F5E6B8]">{{ __('player.payment_method_'.$withdrawal->method->value) }}</dd></div>
            <div class="tl-v2-subcard"><dt class="text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ __('player.withdrawal_provider_label') }}</dt><dd class="mt-2 font-mono text-sm text-[#FAF6EC]">{{ $withdrawal->provider ?? __('player.not_configured') }}</dd></div>
            <div class="tl-v2-subcard"><dt class="text-xs font-bold uppercase tracking-wider text-[#9D937D]">{{ __('player.withdrawal_completed_label') }}</dt><dd class="mt-2 font-mono text-sm text-[#FAF6EC]">{{ $withdrawal->completed_at?->toIso8601String() ?? __('player.not_recorded') }}</dd></div>
        </dl>
        <p class="mt-6 tl-v2-subcard text-sm leading-6 text-[#D4CAB3]">{{ __('player.withdrawal_status_authoritative_note') }}</p>
    </section>

    <div class="flex flex-wrap gap-3">
        <a href="{{ route('player.withdraw') }}" class="tl-v2-btn-gold text-sm">{{ __('player.withdraw_title') }}</a>
        <a href="{{ route('player.wallet') }}" class="tl-v2-btn-outline text-sm">{{ __('player.nav_wallet') }}</a>
    </div>
</div>
@endsection
