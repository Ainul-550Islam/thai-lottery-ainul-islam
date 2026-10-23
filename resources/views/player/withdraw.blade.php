@extends('layouts.app')

@section('title', __('player.withdraw_title').' — '.config('app.name', 'Thai Lottery'))
@section('meta_robots', 'noindex, nofollow')

@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <div>
        <span class="tl-v2-eyebrow">{{ __('player.nav_wallet') }}</span>
        <h1 class="mt-1 text-2xl sm:text-3xl font-black text-[#FFF6D6] tracking-tight">{{ __('player.withdraw_title') }}</h1>
        <p class="text-[#D4CAB3] text-sm mt-1">{{ __('player.withdraw_lead') }}</p>
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
    @if ($errors->any())
        <div class="bg-rose-950/65 border border-rose-500/45 rounded-xl px-4 py-3 text-sm text-rose-200" role="alert">
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="tl-v2-card border-[#D4AF37]/40 flex flex-wrap items-center justify-between gap-4">
        <div>
            <span class="tl-v2-eyebrow block">{{ __('player.available_to_withdraw') }}</span>
            <div class="text-2xl sm:text-3xl font-black font-mono text-[#FFF6D6] mt-1" data-tabular-nums>
                {{ $availableBalance ?? __('player.not_configured') }}
            </div>
        </div>
        <div class="text-xs text-[#D4CAB3] sm:text-right space-y-1">
            <div>{{ __('player.min_withdrawal') }} <span class="text-[#FFF6D6] font-mono font-bold" data-tabular-nums>{{ \App\Services\Finance\Money::of($limits['min'], $currency)->format() }}</span></div>
            <div>{{ __('player.max_withdrawal') }} <span class="text-[#FFF6D6] font-mono font-bold" data-tabular-nums>{{ \App\Services\Finance\Money::of($limits['max'], $currency)->format() }}</span></div>
            <div>{{ __('player.processing_time') }} <span class="text-[#F5E6B8] font-bold">{{ __('player.within_hours', ['hours' => $limits['processing_hours']]) }}</span></div>
        </div>
    </div>

    <div class="tl-v2-card">
        @if ($methods === [])
            <div class="mb-5 tl-v2-subcard border-[#D4AF37]/35 text-sm text-[#F5E6B8]" role="status">{{ __('player.payment_methods_not_configured') }}</div>
        @endif
        <form method="POST" action="{{ route('player.withdraw.store') }}" class="space-y-6">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid()->toString() }}">

            <fieldset>
                <legend class="text-xs font-bold text-[#F5E6B8] uppercase tracking-wider block mb-3">{{ __('player.payout_method') }}</legend>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @foreach ($methods as $value => $label)
                        <label class="tl-v2-subcard flex items-center gap-3 cursor-pointer hover:border-[#D4AF37] transition min-h-[52px]">
                            <input type="radio" name="method" value="{{ $value }}" @checked($value === 'bank_transfer' || $loop->first) class="h-4 w-4 accent-[#D4AF37]">
                            <span class="font-bold text-sm text-[#FFF6D6]">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4" data-bank-fields>
                <div>
                    <label for="withdraw-bank-name" class="text-xs font-bold text-[#F5E6B8] uppercase tracking-wider block mb-2">{{ __('player.bank_name') }} <span class="text-[#9D937D] normal-case font-normal">{{ __('player.bank_transfer_only') }}</span></label>
                    <select id="withdraw-bank-name" name="bank_name" class="tl-v2-input">
                        <option value="">{{ __('player.select_bank_prompt') }}</option>
                        @foreach ($supportedBanks ?? config('payment.supported_banks', []) as $bankKey => $bankLabel)
                            <option value="{{ $bankKey }}" @selected(old('bank_name') === (string) $bankKey)>{{ $bankLabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="withdraw-account-number" class="text-xs font-bold text-[#F5E6B8] uppercase tracking-wider block mb-2">{{ __('player.account_label') }}</label>
                    <input id="withdraw-account-number" type="text" name="account_number" value="{{ old('account_number') }}" required
                           class="tl-v2-input font-mono">
                </div>
            </div>

            <div>
                <label for="withdraw-account-name" class="text-xs font-bold text-[#F5E6B8] uppercase tracking-wider block mb-2">{{ __('player.account_holder_name') }}</label>
                <input id="withdraw-account-name" type="text" name="account_name" value="{{ old('account_name', $user->name ?? $user->username ?? '') }}" required aria-describedby="withdraw-kyc-name-hint"
                       class="tl-v2-input">
                <span id="withdraw-kyc-name-hint" class="text-xs text-[#9D937D] mt-1.5 block">{{ __('player.kyc_name_match') }}</span>
            </div>

            <div>
                <label for="withdraw-amount" class="text-xs font-bold text-[#F5E6B8] uppercase tracking-wider block mb-2">{{ __('player.withdrawal_amount_label') }}</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <span class="text-[#D4AF37] font-bold" aria-hidden="true">฿</span>
                    </div>
                    <input id="withdraw-amount" type="number" name="amount" value="{{ old('amount') }}" min="{{ $limits['min'] }}" max="{{ $limits['max'] }}" step="0.01" required
                           class="tl-v2-input pl-9 font-mono font-bold text-lg">
                </div>
                <span class="text-xs text-[#9D937D] mt-1.5 block">
                    {{ __('player.withdrawal_review_note', ['hours' => $limits['processing_hours']]) }}
                </span>
            </div>

            <button type="submit" @disabled($methods === []) class="tl-v2-btn-gold w-full disabled:cursor-not-allowed disabled:opacity-50">
                {{ __('player.submit_withdrawal') }}
            </button>
        </form>
    </div>

    @if ($recentWithdrawals->isNotEmpty())
        <div class="tl-v2-card">
            <h2 class="text-base font-extrabold text-[#FFF6D6] mb-4">{{ __('player.recent_withdrawals') }}</h2>
            <div class="space-y-2.5">
                @foreach ($recentWithdrawals as $withdrawal)
                    @php
                        $statusKey = 'status_'.$withdrawal->status->value;
                        $statusLabel = trans()->has('player.'.$statusKey) ? __('player.'.$statusKey) : __('player.not_configured');
                        $withdrawalCurrency = $withdrawal->currency instanceof \App\Enums\Currency ? $withdrawal->currency : null;
                        $withdrawalAmountLabel = $withdrawalCurrency instanceof \App\Enums\Currency
                            ? \App\Services\Finance\Money::of((string) $withdrawal->amount, $withdrawalCurrency)->format()
                            : __('player.not_configured');
                    @endphp
                    <div class="flex flex-wrap items-center justify-between gap-2 tl-v2-subcard text-sm">
                        <a class="font-mono font-bold text-[#F5E6B8] hover:text-[#D4AF37] underline underline-offset-4" href="{{ route('player.withdrawal.status', ['withdrawal' => $withdrawal->reference_number]) }}">{{ $withdrawal->reference_number }}</a>
                        <span class="font-mono font-bold text-[#FFF6D6]" data-tabular-nums>{{ $withdrawalAmountLabel }}</span>
                        <span class="tl-v2-pill {{ $withdrawal->status->value === 'completed' ? 'tl-v2-pill--verified' : 'tl-v2-pill--configured' }}">
                            {{ $statusLabel }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
