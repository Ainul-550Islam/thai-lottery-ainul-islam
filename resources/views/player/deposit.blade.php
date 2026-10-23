@extends('layouts.app')

@section('title', __('player.deposit_title').' — '.config('app.name', 'Thai Lottery'))
@section('meta_robots', 'noindex, nofollow')

@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <div>
        <span class="tl-v2-eyebrow">{{ __('player.nav_wallet') }}</span>
        <h1 class="mt-1 text-2xl sm:text-3xl font-black text-[#FFF6D6] tracking-tight">{{ __('player.deposit_title') }}</h1>
        <p class="text-[#D4CAB3] text-sm mt-1">{{ __('player.deposit_lead') }}</p>
    </div>

    @if (session('success'))
        <div class="bg-emerald-950/65 border border-emerald-500/45 rounded-xl px-4 py-3 text-sm text-emerald-200" role="status">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="bg-rose-950/65 border border-rose-500/45 rounded-xl px-4 py-3 text-sm text-rose-200" role="alert">
            {{ session('error') }}
        </div>
    @endif
    @if (session('instructions'))
        @php $manual = session('instructions'); @endphp
        <div class="tl-v2-card text-sm">
            <h3 class="font-bold text-[#FFF6D6] mb-1">{{ __('account_services.deposit_manual_title') }}</h3>
            <p class="text-[#D4CAB3] text-xs mb-3">{{ __('account_services.deposit_manual_lead') }}</p>
            <dl class="grid gap-2">
                @if (! empty($manual['bank_name']))
                    <div class="flex justify-between gap-4 border-b border-[#D4AF37]/15 pb-1.5">
                        <dt class="text-[#9D937D]">{{ __('account_services.deposit_manual_bank_label') }}</dt>
                        <dd class="font-semibold text-[#FFF6D6]">{{ $manual['bank_name'] }}</dd>
                    </div>
                @endif
                @if (! empty($manual['account_number']))
                    <div class="flex justify-between gap-4 border-b border-[#D4AF37]/15 pb-1.5">
                        <dt class="text-[#9D937D]">{{ __('account_services.deposit_manual_account_number_label') }}</dt>
                        <dd class="font-mono font-semibold text-[#FFF6D6]">{{ $manual['account_number'] }}</dd>
                    </div>
                @endif
                @if (! empty($manual['account_name']))
                    <div class="flex justify-between gap-4 border-b border-[#D4AF37]/15 pb-1.5">
                        <dt class="text-[#9D937D]">{{ __('account_services.deposit_manual_account_name_label') }}</dt>
                        <dd class="font-semibold text-[#FFF6D6]">{{ $manual['account_name'] }}</dd>
                    </div>
                @endif
                @if (! empty($manual['reference']))
                    <div class="flex justify-between gap-4 border-b border-[#D4AF37]/15 pb-1.5">
                        <dt class="text-[#9D937D]">{{ __('account_services.deposit_manual_reference_label') }}</dt>
                        <dd class="font-mono font-bold text-[#F5E6B8]">{{ $manual['reference'] }}</dd>
                    </div>
                @endif
                @if (! empty($manual['instructions']))
                    <div class="pt-1 text-[#D4CAB3]">{{ $manual['instructions'] }}</div>
                @endif
            </dl>
        </div>
    @endif
    @if ($errors->any())
        <div class="bg-rose-950/65 border border-rose-500/45 rounded-xl px-4 py-3 text-sm text-rose-200" role="alert">
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="tl-v2-card">
        <form method="POST" action="{{ route('player.deposit.store') }}" class="space-y-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid()->toString() }}">

            <fieldset>
                <legend class="text-xs font-bold text-[#F5E6B8] uppercase tracking-wider block mb-3">{{ __('player.select_payment_method') }}</legend>
                @if ($methods === [])
                    <div class="tl-v2-subcard border-[#D4AF37]/35 text-sm text-[#F5E6B8]" role="status">{{ __('player.payment_methods_not_configured') }}</div>
                @else
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($methods as $value => $label)
                            <label class="tl-v2-subcard flex items-center gap-3 cursor-pointer hover:border-[#D4AF37] transition min-h-[52px]">
                                <input type="radio" name="method" value="{{ $value }}" @checked($loop->first) class="h-4 w-4 accent-[#D4AF37]">
                                <span class="font-bold text-sm text-[#FFF6D6]">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </fieldset>

            <div>
                <label for="deposit-amount" class="text-xs font-bold text-[#F5E6B8] uppercase tracking-wider block mb-2">{{ __('player.deposit_amount_label') }}</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <span class="text-[#D4AF37] font-bold" aria-hidden="true">฿</span>
                    </div>
                    <input id="deposit-amount" type="number" name="amount" value="{{ old('amount') }}" min="{{ $limits['min'] }}" max="{{ $limits['max'] }}" step="0.01" required aria-describedby="deposit-limits-hint"
                           class="tl-v2-input pl-9 font-mono font-bold text-lg">
                </div>
                <span id="deposit-limits-hint" class="text-xs text-[#9D937D] mt-1.5 block">
                    {{ __('player.deposit_limits_note', [
                        'min' => \App\Services\Finance\Money::of($limits['min'], $currency)->format(),
                        'max' => \App\Services\Finance\Money::of($limits['max'], $currency)->format(),
                    ]) }}
                </span>
            </div>

            <button type="submit" @disabled($methods === []) class="tl-v2-btn-gold w-full disabled:cursor-not-allowed disabled:opacity-50">
                {{ __('player.submit_deposit') }}
            </button>
            <p class="text-xs text-[#9D937D] text-center">
                {{ __('player.deposit_footer_note') }}
            </p>
        </form>
    </div>

    @if ($recentDeposits->isNotEmpty())
        <div class="tl-v2-card">
            <h2 class="text-base font-extrabold text-[#FFF6D6] mb-4">{{ __('player.recent_deposits') }}</h2>
            <div class="space-y-2.5">
                @foreach ($recentDeposits as $deposit)
                    @php
                        $statusKey = 'status_'.$deposit->status->value;
                        $statusLabel = trans()->has('player.'.$statusKey) ? __('player.'.$statusKey) : __('player.not_configured');
                        $depositCurrency = $deposit->currency instanceof \App\Enums\Currency ? $deposit->currency : null;
                        $depositAmountLabel = $depositCurrency instanceof \App\Enums\Currency
                            ? \App\Services\Finance\Money::of((string) $deposit->amount, $depositCurrency)->format()
                            : __('player.not_configured');
                    @endphp
                    <div class="flex flex-wrap items-center justify-between gap-2 tl-v2-subcard text-sm">
                        <a class="font-mono font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('player.deposit.status', ['deposit' => $deposit->reference_number]) }}">{{ $deposit->reference_number }}</a>
                        <span class="font-mono font-bold text-[#FFF6D6]" data-tabular-nums>{{ $depositAmountLabel }}</span>
                        <span class="tl-v2-pill {{ $deposit->status->value === 'confirmed' || $deposit->status->value === 'approved' ? 'tl-v2-pill--verified' : 'tl-v2-pill--configured' }}">
                            {{ $statusLabel }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
