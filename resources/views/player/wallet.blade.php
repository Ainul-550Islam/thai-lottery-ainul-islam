@extends('layouts.app')

@section('title', __('player.wallet_title'))
@section('meta_description', __('player.wallet_lead'))
@section('meta_robots', 'noindex,nofollow')

@section('content')
@php
    $currencyCode = $selectedCurrency ?? ($wallet instanceof \App\Models\Wallet && $wallet->currency instanceof \App\Enums\Currency ? $wallet->currency->value : null);
    $currencyEnum = $currencyCode !== null ? \App\Enums\Currency::tryFrom((string) $currencyCode) : null;
@endphp
<div class="space-y-8">
    <div>
        <h1 class="text-2xl sm:text-3xl font-extrabold text-white tracking-tight">{{ __('player.wallet_title') }}</h1>
        <p class="text-slate-400 text-sm mt-1">{{ __('player.wallet_lead') }}</p>
    </div>

    <!-- Balance Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-xl">
            <span class="text-xs text-slate-400 font-semibold uppercase tracking-wider block">{{ __('player.available_balance') }}</span>
            <div class="text-3xl font-extrabold font-mono text-amber-400 mt-2">
                @if ($wallet instanceof \App\Models\Wallet && $currencyEnum instanceof \App\Enums\Currency)
                    {{ \App\Services\Finance\Money::of((string) $wallet->balance, $currencyEnum)->format() }}
                @else
                    {{ __('player.not_configured') }}
                @endif
            </div>
            <span class="text-[11px] text-slate-500 mt-1 block">{{ __('player.available_balance_hint') }}</span>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-xl">
            <span class="text-xs text-slate-400 font-semibold uppercase tracking-wider block">{{ __('player.total_deposited') }}</span>
            <div class="text-3xl font-extrabold font-mono text-white mt-2">
                @if ($wallet instanceof \App\Models\Wallet && $currencyEnum instanceof \App\Enums\Currency)
                    {{ \App\Services\Finance\Money::of((string) $totalDeposited, $currencyEnum)->format() }}
                @else
                    {{ __('player.not_configured') }}
                @endif
            </div>
            <span class="text-[11px] text-slate-500 mt-1 block">{{ __('player.total_deposited_hint') }}</span>
        </div>

        <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 shadow-xl">
            <span class="text-xs text-slate-400 font-semibold uppercase tracking-wider block">{{ __('player.total_prizes') }}</span>
            <div class="text-3xl font-extrabold font-mono text-emerald-400 mt-2">
                @if ($wallet instanceof \App\Models\Wallet && $currencyEnum instanceof \App\Enums\Currency)
                    {{ \App\Services\Finance\Money::of((string) $totalPrizesWon, $currencyEnum)->format() }}
                @else
                    {{ __('player.not_configured') }}
                @endif
            </div>
            <span class="text-[11px] text-slate-500 mt-1 block">{{ __('player.total_prizes_hint') }}</span>
        </div>
    </div>

    <!-- Quick Action Buttons -->
    <div class="flex items-center gap-4">
        <a href="{{ route('player.deposit') }}" class="px-6 py-3 bg-emerald-600 hover:bg-emerald-500 text-white font-bold rounded-xl shadow transition text-sm">
            {{ __('player.deposit_title') }}
        </a>
        <a href="{{ route('player.withdraw') }}" class="px-6 py-3 bg-slate-800 hover:bg-slate-700 text-slate-200 font-semibold rounded-xl border border-slate-700 transition text-sm">
            {{ __('player.withdraw_title') }}
        </a>
    </div>

    <!-- Transactions Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-3xl p-6 sm:p-8 shadow-xl">
        <h2 class="text-lg font-bold text-white mb-6">{{ __('player.journal_title') }}</h2>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-xs text-slate-400 uppercase font-semibold">
                        <th class="py-3 px-4">{{ __('player.col_transaction_ref') }}</th>
                        <th class="py-3 px-4">{{ __('player.col_type') }}</th>
                        <th class="py-3 px-4">{{ __('player.col_description') }}</th>
                        <th class="py-3 px-4 text-right">{{ __('player.col_amount') }}</th>
                        <th class="py-3 px-4 text-right">{{ __('player.col_date') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-800/60">
                    @forelse($transactions as $tx)
                        @php
                            $txCurrency = $tx->currency instanceof \App\Enums\Currency ? $tx->currency : null;
                            $txAmountLabel = $txCurrency instanceof \App\Enums\Currency
                                ? \App\Services\Finance\Money::of((string) $tx->amount, $txCurrency)->format()
                                : __('player.not_configured');
                            $txType = $tx->type instanceof \BackedEnum ? (string) $tx->type->value : (string) $tx->type;
                            $isCredit = in_array($txType, ['deposit', 'payout', 'prize_payout'], true);
                        @endphp
                        <tr class="hover:bg-slate-800/30 transition">
                            <td class="py-3.5 px-4 font-mono font-bold text-slate-200">
                                {{ $tx->reference_number ?? __('player.not_recorded') }}
                            </td>
                            <td class="py-3.5 px-4">
                                <span class="px-2 py-0.5 rounded text-xs font-bold uppercase {{ $isCredit ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-400' }}">
                                    {{ $txType }}
                                </span>
                            </td>
                            <td class="py-3.5 px-4 text-xs text-slate-400">
                                {{ $tx->description ?? __('player.not_recorded') }}
                            </td>
                            <td class="py-3.5 px-4 text-right font-mono font-bold {{ $isCredit ? 'text-emerald-400' : 'text-slate-200' }}">
                                {{ $isCredit ? '+' : '-' }}{{ $txAmountLabel }}
                            </td>
                            <td class="py-3.5 px-4 text-right text-xs text-slate-500 font-mono">
                                {{ $tx->created_at?->format('M d, Y H:i') ?? __('player.not_recorded') }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-12 text-center text-slate-500 text-xs">
                                {{ __('player.no_transactions_recorded') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if(method_exists($transactions, 'links'))
            <div class="pt-6">
                {{ $transactions->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
