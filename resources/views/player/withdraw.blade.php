@extends('layouts.app')

@section('title', __('player.withdraw_title').' — '.config('app.name', 'Thai Lottery'))

@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">{{ __('player.withdraw_title') }}</h1>
        <p class="text-slate-400 text-sm mt-1">{{ __('player.withdraw_lead') }}</p>
    </div>

    <!-- Flash feedback: the withdrawal reference and its real status, or the
         engine's own refusal message (insufficient balance, KYC gate). -->
    @if (session('success'))
        <div class="bg-emerald-950/60 border border-emerald-600/40 rounded-xl px-4 py-3 text-sm text-emerald-300">
            {{ session('success') }}
        </div>
    @endif
    @if (session('error'))
        <div class="bg-rose-950/60 border border-rose-600/40 rounded-xl px-4 py-3 text-sm text-rose-300">
            {{ session('error') }}
        </div>
    @endif
    @if ($errors->any())
        <div class="bg-rose-950/60 border border-rose-600/40 rounded-xl px-4 py-3 text-sm text-rose-300">
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Available Balance Card: exact decimal math, no float casting. -->
    <div class="bg-gradient-to-r from-emerald-950/60 to-slate-900 border border-emerald-500/30 rounded-2xl p-5 shadow flex items-center justify-between">
        <div>
            <span class="text-xs text-emerald-400 font-semibold uppercase tracking-wider block">{{ __('player.available_to_withdraw') }}</span>
            <div class="text-2xl font-bold font-mono text-white mt-0.5">
                {{ $availableBalance ?? __('player.not_configured') }}
            </div>
        </div>
        <div class="text-xs text-slate-400 text-right">
            {{-- Limits and the processing window come from the SAME config the
                 withdrawal engine enforces — never a marketing number. --}}
            <div>{{ __('player.min_withdrawal') }} <span class="text-white font-mono font-bold">{{ \App\Services\Finance\Money::of($limits['min'], $currency)->format() }}</span></div>
            <div>{{ __('player.max_withdrawal') }} <span class="text-white font-mono font-bold">{{ \App\Services\Finance\Money::of($limits['max'], $currency)->format() }}</span></div>
            <div>{{ __('player.processing_time') }} <span class="text-emerald-400 font-bold">{{ __('player.within_hours', ['hours' => $limits['processing_hours']]) }}</span></div>
        </div>
    </div>

    <!-- Withdrawal Form -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-xl">
        @if ($methods === [])
            <div class="mb-5 rounded-xl border border-amber-500/30 bg-amber-950/20 p-4 text-sm text-amber-200" role="status">{{ __('player.payment_methods_not_configured') }}</div>
        @endif
        <form method="POST" action="{{ route('player.withdraw.store') }}" class="space-y-6">
            @csrf
            {{-- One-time key: a double-submit replays the same key and the
                 finance engine returns the existing withdrawal row, so no
                 double request can ever be created from one form render. --}}
            <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid()->toString() }}">

            <!-- Method: rendered from the closed config list the
                 WithdrawalService validates against. -->
            <div>
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-3">{{ __('player.payout_method') }}</label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    @foreach ($methods as $value => $label)
                        <label class="border border-slate-800 bg-slate-950 p-4 rounded-xl flex flex-col items-center justify-center cursor-pointer hover:border-emerald-500 transition">
                            <input type="radio" name="method" value="{{ $value }}" @checked($value === 'bank_transfer' || $loop->first) class="text-emerald-500 focus:ring-emerald-500 mb-2">
                            <span class="font-bold text-sm text-white">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Destination: bank fields for bank_transfer; for the wallet
                 rails the account number is the mobile number, for crypto
                 the wallet address. Works without JavaScript: every field
                 group is visible and the server interprets by method. -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">{{ __('player.bank_name') }} <span class="text-slate-500 normal-case font-normal">{{ __('player.bank_transfer_only') }}</span></label>
                    <select name="bank_name" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-emerald-500">
                        <option value="">{{ __('player.select_bank_prompt') }}</option>
                        @foreach ($supportedBanks ?? config('payment.supported_banks', []) as $bankKey => $bankLabel)
                            <option value="{{ $bankKey }}" @selected(old('bank_name') === (string) $bankKey)>{{ $bankLabel }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">{{ __('player.account_label') }}</label>
                    <input type="text" name="account_number" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white font-mono text-sm focus:outline-none focus:border-emerald-500">
                </div>
            </div>

            <div>
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">{{ __('player.account_holder_name') }}</label>
                <input type="text" name="account_name" value="{{ old('account_name', $user->name ?? $user->username ?? '') }}" required
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-emerald-500">
                <span class="text-[11px] text-slate-500 mt-1 block">{{ __('player.kyc_name_match') }}</span>
            </div>

            <!-- Amount: bounds mirror the configured engine limits. -->
            <div>
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">{{ __('player.withdrawal_amount_label') }}</label>
                <div class="relative rounded-xl shadow-sm">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <span class="text-slate-500 font-bold">฿</span>
                    </div>
                    <input type="number" name="amount" value="{{ old('amount') }}" min="{{ $limits['min'] }}" max="{{ $limits['max'] }}" step="0.01" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl pl-8 pr-4 py-3 text-white font-mono font-bold text-lg focus:outline-none focus:border-emerald-500">
                </div>
                <span class="text-[11px] text-slate-500 mt-1 block">
                    {{ __('player.withdrawal_review_note', ['hours' => $limits['processing_hours']]) }}
                </span>
            </div>

            <button type="submit" @disabled($methods === []) class="w-full py-3.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl shadow-lg transition duration-150 disabled:cursor-not-allowed disabled:opacity-50">
                {{ __('player.submit_withdrawal') }}
            </button>
        </form>
    </div>

    @if ($recentWithdrawals->isNotEmpty())
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <h2 class="text-base font-bold text-white mb-4">{{ __('player.recent_withdrawals') }}</h2>
            <div class="space-y-2">
                @foreach ($recentWithdrawals as $withdrawal)
                    @php
                        $statusKey = 'status_'.$withdrawal->status->value;
                        $statusLabel = trans()->has('player.'.$statusKey) ? __('player.'.$statusKey) : __('player.not_configured');
                        $withdrawalCurrency = $withdrawal->currency instanceof \App\Enums\Currency ? $withdrawal->currency : null;
                        $withdrawalAmountLabel = $withdrawalCurrency instanceof \App\Enums\Currency
                            ? \App\Services\Finance\Money::of((string) $withdrawal->amount, $withdrawalCurrency)->format()
                            : __('player.not_configured');
                    @endphp
                    <div class="flex items-center justify-between bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-sm">
                        <a class="font-mono text-emerald-300 hover:underline" href="{{ route('player.withdrawal.status', ['withdrawal' => $withdrawal->reference_number]) }}">{{ $withdrawal->reference_number }}</a>
                        <span class="font-mono font-bold text-white">{{ $withdrawalAmountLabel }}</span>
                        <span class="text-xs font-bold uppercase tracking-wider px-2 py-1 rounded-full border
                            {{ $withdrawal->status->value === 'completed' ? 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10' : 'border-amber-500/30 text-amber-400 bg-amber-500/10' }}">
                            {{ $statusLabel }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
