@extends('layouts.app')

@section('title', __('player.deposit_title').' — '.config('app.name', 'Thai Lottery'))

@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">{{ __('player.deposit_title') }}</h1>
        <p class="text-slate-400 text-sm mt-1">{{ __('player.deposit_lead') }}</p>
    </div>

    <!-- Flash feedback: the deposit reference and its real status, or the
         engine's own refusal message. Nothing is invented here. -->
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
    @if (session('instructions'))
        {{-- Manual settlement instructions (FINAL AUDIT #4): rendered ONLY from
             the operator-configured settlement details the gateway returned;
             the view itself contains no bank identity of its own. --}}
        @php $manual = session('instructions'); @endphp
        <div class="bg-slate-900/80 border border-slate-700 rounded-xl px-4 py-4 text-sm">
            <h3 class="font-bold text-white mb-1">{{ __('account_services.deposit_manual_title') }}</h3>
            <p class="text-slate-400 text-xs mb-3">{{ __('account_services.deposit_manual_lead') }}</p>
            <dl class="grid gap-2">
                @if (! empty($manual['bank_name']))
                    <div class="flex justify-between gap-4 border-b border-slate-800 pb-1">
                        <dt class="text-slate-400">{{ __('account_services.deposit_manual_bank_label') }}</dt>
                        <dd class="font-semibold text-slate-100">{{ $manual['bank_name'] }}</dd>
                    </div>
                @endif
                @if (! empty($manual['account_number']))
                    <div class="flex justify-between gap-4 border-b border-slate-800 pb-1">
                        <dt class="text-slate-400">{{ __('account_services.deposit_manual_account_number_label') }}</dt>
                        <dd class="font-mono font-semibold text-slate-100">{{ $manual['account_number'] }}</dd>
                    </div>
                @endif
                @if (! empty($manual['account_name']))
                    <div class="flex justify-between gap-4 border-b border-slate-800 pb-1">
                        <dt class="text-slate-400">{{ __('account_services.deposit_manual_account_name_label') }}</dt>
                        <dd class="font-semibold text-slate-100">{{ $manual['account_name'] }}</dd>
                    </div>
                @endif
                @if (! empty($manual['reference']))
                    <div class="flex justify-between gap-4 border-b border-slate-800 pb-1">
                        <dt class="text-slate-400">{{ __('account_services.deposit_manual_reference_label') }}</dt>
                        <dd class="font-mono font-semibold text-emerald-400">{{ $manual['reference'] }}</dd>
                    </div>
                @endif
                @if (! empty($manual['instructions']))
                    <div class="pt-1 text-slate-300">{{ $manual['instructions'] }}</div>
                @endif
            </dl>
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

    <!-- Deposit Form Card -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-xl">
        <form method="POST" action="{{ route('player.deposit.store') }}" class="space-y-6">
            @csrf
            {{-- One-time key: a double-submit replays the same key and the
                 finance engine returns the existing deposit row, so no
                 double order can ever be created from one form render. --}}
            <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid()->toString() }}">

            <!-- Method Selection: rendered from the SAME closed config list
                 the DepositService validates against. No advertised gateway
                 that the canonical payment model does not have. -->
            <div>
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-3">{{ __('player.select_payment_method') }}</label>
                @if ($methods === [])
                    <div class="rounded-xl border border-amber-500/30 bg-amber-950/20 p-4 text-sm text-amber-200" role="status">{{ __('player.payment_methods_not_configured') }}</div>
                @else
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($methods as $value => $label)
                            <label class="border border-slate-800 bg-slate-950 p-4 rounded-xl flex flex-col items-center justify-center cursor-pointer hover:border-emerald-500 transition">
                                <input type="radio" name="method" value="{{ $value }}" @checked($loop->first) class="text-emerald-500 focus:ring-emerald-500 mb-2">
                                <span class="font-bold text-sm text-white">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Amount Input: bounds mirror the configured engine limits. -->
            <div>
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">{{ __('player.deposit_amount_label') }}</label>
                <div class="relative rounded-xl shadow-sm">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <span class="text-slate-500 font-bold">฿</span>
                    </div>
                    <input type="number" name="amount" value="{{ old('amount') }}" min="{{ $limits['min'] }}" max="{{ $limits['max'] }}" step="0.01" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl pl-8 pr-4 py-3 text-white font-mono font-bold text-lg focus:outline-none focus:border-emerald-500">
                </div>
                <span class="text-[11px] text-slate-500 mt-1 block">
                    {{ __('player.deposit_limits_note', [
                        'min' => \App\Services\Finance\Money::of($limits['min'], $currency)->format(),
                        'max' => \App\Services\Finance\Money::of($limits['max'], $currency)->format(),
                    ]) }}
                </span>
            </div>

            <button type="submit" @disabled($methods === []) class="w-full py-3.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl shadow-lg transition duration-150 disabled:cursor-not-allowed disabled:opacity-50">
                {{ __('player.submit_deposit') }}
            </button>
            <p class="text-[11px] text-slate-500 text-center">
                {{ __('player.deposit_footer_note') }}
            </p>
        </form>
    </div>

    @if ($recentDeposits->isNotEmpty())
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <h2 class="text-base font-bold text-white mb-4">{{ __('player.recent_deposits') }}</h2>
            <div class="space-y-2">
                    @foreach ($recentDeposits as $deposit)
                    @php
                        $statusKey = 'status_'.$deposit->status->value;
                        $statusLabel = trans()->has('player.'.$statusKey) ? __('player.'.$statusKey) : __('player.not_configured');
                        $depositCurrency = $deposit->currency instanceof \App\Enums\Currency ? $deposit->currency : null;
                        $depositAmountLabel = $depositCurrency instanceof \App\Enums\Currency
                            ? \App\Services\Finance\Money::of((string) $deposit->amount, $depositCurrency)->format()
                            : __('player.not_configured');
                    @endphp
                    <div class="flex items-center justify-between bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-sm">
                        <a class="font-mono text-emerald-300 hover:underline" href="{{ route('player.deposit.status', ['deposit' => $deposit->reference_number]) }}">{{ $deposit->reference_number }}</a>
                        <span class="font-mono font-bold text-white">{{ $depositAmountLabel }}</span>
                        <span class="text-xs font-bold uppercase tracking-wider px-2 py-1 rounded-full border
                            {{ $deposit->status->value === 'confirmed' || $deposit->status->value === 'approved' ? 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10' : 'border-amber-500/30 text-amber-400 bg-amber-500/10' }}">
                            {{ $statusLabel }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
