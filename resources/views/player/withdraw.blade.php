@extends('layouts.app')

@section('title', 'Request Withdrawal — Thai Lottery')

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
                {{ \App\Services\Finance\Money::of(bcsub((string) ($wallet->balance ?? '0'), (string) ($wallet->locked_balance ?? '0'), 2), \App\Enums\Currency::THB)->format() }}
            </div>
        </div>
        <div class="text-xs text-slate-400 text-right">
            {{-- Limits and the processing window come from the SAME config the
                 withdrawal engine enforces — never a marketing number. --}}
            <div>{{ __('player.min_withdrawal') }} <span class="text-white font-mono font-bold">{{ \App\Services\Finance\Money::of($limits['min'], \App\Enums\Currency::THB)->format() }}</span></div>
            <div>{{ __('player.max_withdrawal') }} <span class="text-white font-mono font-bold">{{ \App\Services\Finance\Money::of($limits['max'], \App\Enums\Currency::THB)->format() }}</span></div>
            <div>{{ __('player.processing_time') }} <span class="text-emerald-400 font-bold">{{ __('player.within_hours', ['hours' => $limits['processing_hours']]) }}</span></div>
        </div>
    </div>

    <!-- Withdrawal Form -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 sm:p-8 shadow-xl">
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
                        <option value="">— Select if paying to a bank —</option>
                        <option value="Bangkok Bank">Bangkok Bank (BBL)</option>
                        <option value="Kasikornbank">Kasikornbank (KBANK)</option>
                        <option value="Siam Commercial Bank">Siam Commercial Bank (SCB)</option>
                        <option value="Krungthai Bank">Krungthai Bank (KTB)</option>
                        <option value="Bank of Ayudhya">Bank of Ayudhya (Krungsri)</option>
                        <option value="TTB Bank">TMBThanachart Bank (TTB)</option>
                    </select>
                </div>

                <div>
                    <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">{{ __('player.account_label') }}</label>
                    <input type="text" name="account_number" placeholder="XXX-X-XXXXX-X" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white font-mono text-sm focus:outline-none focus:border-emerald-500">
                </div>
            </div>

            <div>
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">{{ __('player.account_holder_name') }}</label>
                <input type="text" name="account_name" value="{{ old('account_name', $user->name ?? '') }}" required
                       class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-white text-sm focus:outline-none focus:border-emerald-500">
                <span class="text-[11px] text-slate-500 mt-1 block">{{ __('player.kyc_name_match') }}</span>
            </div>

            <!-- Amount: bounds mirror the configured engine limits. -->
            <div>
                <label class="text-xs font-bold text-slate-400 uppercase tracking-wider block mb-2">{{ __('player.withdrawal_amount_thb') }}</label>
                <div class="relative rounded-xl shadow-sm">
                    <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                        <span class="text-slate-500 font-bold">฿</span>
                    </div>
                    <input type="number" name="amount" value="{{ old('amount', '500') }}" min="{{ $limits['min'] }}" max="{{ $limits['max'] }}" step="0.01" required
                           class="w-full bg-slate-950 border border-slate-800 rounded-xl pl-8 pr-4 py-3 text-white font-mono font-bold text-lg focus:outline-none focus:border-emerald-500">
                </div>
                <span class="text-[11px] text-slate-500 mt-1 block">
                    Requests are reviewed, then paid out within {{ $limits['processing_hours'] }} hours.
                </span>
            </div>

            <button type="submit" class="w-full py-3.5 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl shadow-lg transition duration-150">
                Request Withdrawal
            </button>
        </form>
    </div>

    @if ($recentWithdrawals->isNotEmpty())
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 shadow-xl">
            <h2 class="text-base font-bold text-white mb-4">{{ __('player.recent_withdrawals') }}</h2>
            <div class="space-y-2">
                @foreach ($recentWithdrawals as $withdrawal)
                    <div class="flex items-center justify-between bg-slate-950 border border-slate-800 rounded-xl px-4 py-3 text-sm">
                        <span class="font-mono text-slate-300">{{ $withdrawal->reference_number }}</span>
                        <span class="font-mono font-bold text-white">{{ \App\Services\Finance\Money::of((string) $withdrawal->amount, \App\Enums\Currency::THB)->format() }}</span>
                        <span class="text-xs font-bold uppercase tracking-wider px-2 py-1 rounded-full border
                            {{ $withdrawal->status->value === 'completed' ? 'border-emerald-500/30 text-emerald-400 bg-emerald-500/10' : 'border-amber-500/30 text-amber-400 bg-amber-500/10' }}">
                            {{ $withdrawal->status->value }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endsection
